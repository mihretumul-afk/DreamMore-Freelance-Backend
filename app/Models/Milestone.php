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

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'amount',
        'status',
        'due_date',
        'submitted_at',
        'approved_at',
        'paid_at',
        'payment_id',
        'escrow_funded_at',
    ];

    /**
     * New milestones are created in the pending state.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'due_date'         => 'datetime',
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

    public function payment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Payment::class);
    }

    public function isEscrowFunded(): bool
    {
        return $this->escrow_funded_at !== null;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid' && $this->paid_at !== null;
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
    }

    public function isReleaseable(): bool
    {
        return $this->isEscrowFunded()
            && $this->status === 'approved'
            && !$this->isDisputed();
    }
}
