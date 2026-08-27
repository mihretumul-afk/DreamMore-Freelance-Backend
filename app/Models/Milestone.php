<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Milestone extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_AWAITING_FUNDING = 'awaiting_funding';
    const STATUS_FUNDED           = 'funded';
    const STATUS_IN_PROGRESS      = 'in_progress';
    const STATUS_SUBMITTED        = 'submitted';
    const STATUS_REVISION_REQUESTED = 'revision_requested';
    const STATUS_APPROVED         = 'approved';
    const STATUS_RELEASED         = 'released';
    const STATUS_PAID             = 'paid';
    const STATUS_DISPUTED         = 'disputed';
    const STATUS_CANCELLED        = 'cancelled';

    /**
     * Valid status transitions (from → [allowed destinations]).
     */
    const VALID_TRANSITIONS = [
        self::STATUS_AWAITING_FUNDING => [self::STATUS_FUNDED, self::STATUS_CANCELLED, self::STATUS_DISPUTED],
        self::STATUS_FUNDED           => [self::STATUS_IN_PROGRESS, self::STATUS_DISPUTED, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS      => [self::STATUS_SUBMITTED, self::STATUS_DISPUTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED        => [self::STATUS_APPROVED, self::STATUS_REVISION_REQUESTED, self::STATUS_DISPUTED],
        self::STATUS_REVISION_REQUESTED => [self::STATUS_SUBMITTED, self::STATUS_DISPUTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED         => [self::STATUS_RELEASED, self::STATUS_DISPUTED],
        self::STATUS_RELEASED         => [self::STATUS_PAID],
    ];

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'amount',
        'status',
        'due_date',
        'started_at',
        'submitted_at',
        'approved_at',
        'paid_at',
        'payment_id',
        'escrow_funded_at',
    ];

    /**
     * New milestones are created in the awaiting_funding state.
     */
    protected $attributes = [
        'status' => self::STATUS_AWAITING_FUNDING,
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'due_date'         => 'datetime',
        'started_at'       => 'datetime',
        'submitted_at'     => 'datetime',
        'approved_at'      => 'datetime',
        'paid_at'          => 'datetime',
        'escrow_funded_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(MilestoneSubmission::class)->orderBy('created_at', 'desc');
    }

    public function latestSubmission(): HasOne
    {
        return $this->hasOne(MilestoneSubmission::class)->latestOfMany();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MilestoneAttachment::class);
    }

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Payment::class);
    }

    /**
     * Check if the milestone can transition to a given status.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::VALID_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    public function isEscrowFunded(): bool
    {
        return $this->escrow_funded_at !== null;
    }

    public function isFunded(): bool
    {
        return $this->status === self::STATUS_FUNDED || $this->isEscrowFunded();
    }

    public function isAwaitingFunding(): bool
    {
        return $this->status === self::STATUS_AWAITING_FUNDING;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_RELEASED]) && $this->paid_at !== null;
    }

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    public function isReleaseable(): bool
    {
        return $this->isEscrowFunded()
            && $this->status === self::STATUS_APPROVED
            && !$this->isDisputed();
    }

    /**
     * Check if freelancer can start work on this milestone.
     */
    public function canStartWork(): bool
    {
        return $this->status === self::STATUS_FUNDED && $this->isEscrowFunded();
    }

    /**
     * Check if freelancer can submit work.
     */
    public function canSubmitWork(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_REVISION_REQUESTED,
        ], true);
    }
}
