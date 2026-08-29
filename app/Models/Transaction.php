<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    // ── Type constants ────────────────────────────────────────────────────
    public const TYPE_PAYMENT          = 'payment';
    public const TYPE_PLATFORM_FEE     = 'platform_fee';
    public const TYPE_PROCESSING_FEE   = 'processing_fee';
    public const TYPE_FUNDS_HELD       = 'funds_held';
    public const TYPE_FUNDS_RELEASED   = 'funds_released';
    public const TYPE_REFUND           = 'refund';
    public const TYPE_WITHDRAWAL       = 'withdrawal';
    public const TYPE_WITHDRAWAL_FEE   = 'withdrawal_fee';
    public const TYPE_ADJUSTMENT       = 'adjustment';

    // ── Direction constants ───────────────────────────────────────────────
    public const DIR_CREDIT = 'credit';
    public const DIR_DEBIT  = 'debit';

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REVERSED  = 'reversed';

    protected $fillable = [
        'reference',
        'payment_id',
        'user_id',
        'wallet_id',
        'direction',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'currency',
        'status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee'    => 'decimal:2',
    ];

    protected $attributes = [
        'currency' => 'ETB',
        'status'   => self::STATUS_COMPLETED,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Generate a unique transaction reference.
     */
    public static function generateReference(): string
    {
        return 'TXN-' . strtoupper(uniqid());
    }
}
