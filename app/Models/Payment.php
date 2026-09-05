<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_DISPUTED   = 'disputed';

    // ── Type constants ────────────────────────────────────────────────────
    public const TYPE_ESCROW_FUNDED    = 'escrow_funded';
    public const TYPE_MILESTONE_RELEASED = 'milestone_released';
    public const TYPE_REFUND           = 'refund';
    public const TYPE_WITHDRAWAL       = 'withdrawal';
    public const TYPE_WALLET_DEPOSIT   = 'wallet_deposit';

    // ── Refund status constants ───────────────────────────────────────────
    public const REFUND_NONE      = 'none';
    public const REFUND_REQUESTED = 'requested';
    public const REFUND_APPROVED  = 'approved';
    public const REFUND_COMPLETED = 'completed';
    public const REFUND_REJECTED  = 'rejected';

    protected $fillable = [
        'reference',
        'payer_id',
        'payee_id',
        'contract_id',
        'milestone_id',
        'payment_method_id',
        'type',
        'amount',
        'platform_fee',
        'processing_fee',
        'fee',
        'net_amount',
        'currency',
        'status',
        'provider',
        'provider_transaction_id',
        'provider_reference',
        'provider_response',
        'failure_reason',
        'description',
        'metadata',
        'paid_at',
        'refunded_at',
        'processed_at',
    ];

    protected $casts = [
        'amount'                => 'decimal:2',
        'fee'                   => 'decimal:2',
        'net_amount'            => 'decimal:2',
        'processed_at'          => 'datetime',
        'failed_at'             => 'datetime',
        'refund_requested_at'   => 'datetime',
        'refund_approved_at'    => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'ETB',
        'status'   => self::STATUS_PENDING,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED || $this->refund_status === self::REFUND_COMPLETED;
    }

    /**
     * Generate a unique payment reference.
     */
    public static function generateReference(): string
    {
        return 'PAY-' . strtoupper(uniqid());
    }
}
