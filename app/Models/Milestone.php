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
    const STATUS_PENDING            = 'pending';
    const STATUS_AWAITING_FUNDING   = 'awaiting_funding';
    const STATUS_FUNDED             = 'funded';
    const STATUS_IN_PROGRESS        = 'in_progress';
    const STATUS_SUBMITTED          = 'submitted';
    const STATUS_REVISION_REQUESTED = 'revision_requested';
    const STATUS_APPROVED           = 'approved';
    const STATUS_REJECTED           = 'rejected';
    const STATUS_RELEASED           = 'released';
    const STATUS_PAID               = 'paid';
    const STATUS_DISPUTED           = 'disputed';
    const STATUS_CANCELLED          = 'cancelled';

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'amount',
        'status',
        'due_date',
        'funded_at',
        'submitted_at',
        'approved_at',
        'released_at',
        'escrow_funded_at',
        'paid_at',
        'sort_order',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sort_order' => 0,
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'due_date'         => 'datetime',
        'funded_at'        => 'datetime',
        'submitted_at'     => 'datetime',
        'approved_at'      => 'datetime',
        'released_at'      => 'datetime',
        'escrow_funded_at' => 'datetime',
        'paid_at'          => 'datetime',
        'sort_order'       => 'integer',
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

    public function escrowTransactions(): HasMany
    {
        return $this->hasMany(EscrowTransaction::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function latestDispute(): HasOne
    {
        return $this->hasOne(Dispute::class)->latestOfMany();
    }

    public function isEscrowFunded(): bool
    {
        return $this->funded_at !== null || $this->escrow_funded_at !== null || in_array($this->status, ['funded', 'submitted', 'approved', 'released', 'paid'], true);
    }

    public function isFunded(): bool
    {
        return $this->isEscrowFunded();
    }
}
