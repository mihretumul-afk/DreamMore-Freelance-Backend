<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_PENDING = 'pending_acceptance';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PAUSED  = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'job_id',
        'proposal_id',
        'employer_id',
        'freelancer_id',
        'title',
        'budget_type',
        'agreed_rate',
        'total_amount',
        'status',
        'terms',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'agreed_rate'  => 'decimal:2',
        'total_amount' => 'decimal:2',
        'start_date'   => 'datetime',
        'end_date'     => 'datetime',
    ];

    protected $attributes = [
        'budget_type' => 'fixed',
        'status'      => self::STATUS_PENDING,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('created_at');
    }

    // ── Helper methods ────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }
}
