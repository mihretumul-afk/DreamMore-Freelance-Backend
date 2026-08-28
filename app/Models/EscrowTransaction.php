<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowTransaction extends Model
{
    use HasFactory;

    protected $table = 'escrow_transactions';

    protected $fillable = [
        'contract_id',
        'milestone_id',
        'type', // funding, escrow_hold, release, platform_fee, withdrawal, refund
        'amount',
        'currency',
        'from_user_id',
        'to_user_id',
        'status', // pending, completed, failed, reversed
        'payment_method',
        'gateway_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
