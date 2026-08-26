<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'reference',
        'payment_id',
        'user_id',
        'direction',
        'type',
        'amount',
        'fee',
        'currency',
        'status',
        'description',
        'contract_id',
        'milestone_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee'    => 'decimal:2',
        ];
    }

    // ── Direction constants ──────────────────────────────────────────────

    public const DIRECTION_CREDIT = 'credit'; // money coming in
    public const DIRECTION_DEBIT  = 'debit';  // money going out

    // ── Boot ─────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Transaction $txn) {
            if (empty($txn->reference)) {
                $txn->reference = self::generateReference();
            }
        });
    }

    // ── Reference generation ─────────────────────────────────────────────

    public static function generateReference(): string
    {
        $year = now()->year;
        $seq  = str_pad((string) (self::whereYear('created_at', $year)->count() + 1), 6, '0', STR_PAD_LEFT);
        return "TXN-{$year}-{$seq}";
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCredits($query)
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    public function scopeDebits($query)
    {
        return $query->where('direction', self::DIRECTION_DEBIT);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', Payment::STATUS_COMPLETED);
    }
}
