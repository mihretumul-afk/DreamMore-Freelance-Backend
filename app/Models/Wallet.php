<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'available_balance',
        'pending_balance',
        'currency',
    ];

    protected $attributes = [
        'available_balance' => 0.00,
        'pending_balance'   => 0.00,
        'currency'          => 'ETB',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'pending_balance'   => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get or create wallet for a user.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            ['currency' => 'ETB']
        );
    }

    /**
     * Credit available balance (after clearance period).
     */
    public function creditAvailable(float $amount): bool
    {
        if ($amount <= 0) return false;
        return $this->increment('available_balance', $amount);
    }

    /**
     * Move funds from pending to available (clearance complete).
     */
    public function clearPending(float $amount): bool
    {
        if ($amount <= 0) return false;
        if ($this->pending_balance < $amount) return false;

        DB::transaction(function () use ($amount) {
            $this->decrement('pending_balance', $amount);
            $this->increment('available_balance', $amount);
        });

        return true;
    }

    /**
     * Reserve funds for withdrawal.
     */
    public function reserveForWithdrawal(float $amount): bool
    {
        if ($amount <= 0) return false;
        if ($this->available_balance < $amount) return false;

        return $this->decrement('available_balance', $amount);
    }

    /**
     * Return funds to available balance (on withdrawal failure).
     */
    public function returnFromWithdrawal(float $amount): bool
    {
        if ($amount <= 0) return false;
        return $this->increment('available_balance', $amount);
    }

    /**
     * Check if user has sufficient balance.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->available_balance >= $amount;
    }

    /**
     * Complete a withdrawal (called after provider confirmation).
     */
    public function completeWithdrawal(float $amount): bool
    {
        // Funds already reserved in available balance, nothing more to do
        return true;
    }

    /**
     * Release a withdrawal reservation (on failure/cancel).
     */
    public function releaseReservation(float $amount, string $reason = ''): bool
    {
        if ($amount <= 0) return false;
        return $this->increment('available_balance', $amount);
    }
}
