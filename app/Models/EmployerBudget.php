<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'monthly_limit',
        'alert_threshold',
        'alerts_enabled',
        'currency',
        'last_reset_at',
    ];

    protected $casts = [
        'monthly_limit'    => 'decimal:2',
        'alert_threshold'  => 'decimal:2',
        'alerts_enabled'   => 'boolean',
    ];

    protected $attributes = [
        'currency'         => 'ETB',
        'alert_threshold'  => 80,
        'alerts_enabled'   => true,
    ];

    // ── Relationships ─────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Calculate spending for the current month.
     */
    public function getCurrentMonthSpending(): float
    {
        $startOfMonth = now()->startOfMonth();

        return (float) Payment::where('payer_id', $this->user_id)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('status', Payment::STATUS_COMPLETED)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');
    }

    /**
     * Get remaining budget for the current month.
     */
    public function getRemainingBudget(): float
    {
        return max(0, (float) $this->monthly_limit - $this->getCurrentMonthSpending());
    }

    /**
     * Get budget usage percentage.
     */
    public function getUsagePercentage(): float
    {
        if ($this->monthly_limit <= 0) {
            return 0;
        }

        return round(($this->getCurrentMonthSpending() / $this->monthly_limit) * 100, 1);
    }

    /**
     * Check if budget alert should be triggered.
     */
    public function shouldAlert(): bool
    {
        if (!$this->alerts_enabled || $this->monthly_limit <= 0) {
            return false;
        }

        return $this->getUsagePercentage() >= $this->alert_threshold;
    }

    /**
     * Check if budget is exceeded.
     */
    public function isExceeded(): bool
    {
        return $this->getCurrentMonthSpending() > $this->monthly_limit && $this->monthly_limit > 0;
    }
}
