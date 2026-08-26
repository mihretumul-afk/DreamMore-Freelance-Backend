<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = [
        'reference',
        'payer_id',
        'payee_id',
        'contract_id',
        'milestone_id',
        'payment_method_id',
        'type',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'provider_response',
        'description',
        'admin_notes',
        'processed_at',
        'failed_at',
        'failure_reason',
        'refund_status',
        'refund_reason',
        'refund_requested_at',
        'refund_requested_by',
        'refund_approved_at',
        'refund_approved_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'               => 'decimal:2',
            'fee'                  => 'decimal:2',
            'net_amount'           => 'decimal:2',
            'provider_response'    => 'array',
            'processed_at'         => 'datetime',
            'failed_at'            => 'datetime',
            'refund_requested_at'  => 'datetime',
            'refund_approved_at'   => 'datetime',
        ];
    }

    // ── Refund-status constants ───────────────────────────────────────────

    public const REFUND_STATUS_NONE      = 'none';
    public const REFUND_STATUS_REQUESTED = 'requested';
    public const REFUND_STATUS_APPROVED  = 'approved';
    public const REFUND_STATUS_REJECTED  = 'rejected';
    public const REFUND_STATUS_COMPLETED = 'completed';
    public const REFUND_STATUS_FAILED    = 'failed';

    // ── Type constants ───────────────────────────────────────────────────

    public const TYPE_ESCROW_FUNDED      = 'escrow_funded';
    public const TYPE_MILESTONE_RELEASED = 'milestone_released';
    public const TYPE_REFUND             = 'refund';
    public const TYPE_PLATFORM_FEE      = 'platform_fee';
    public const TYPE_MANUAL_ADJUSTMENT  = 'manual_adjustment';

    public const TYPES = [
        self::TYPE_ESCROW_FUNDED,
        self::TYPE_MILESTONE_RELEASED,
        self::TYPE_REFUND,
        self::TYPE_PLATFORM_FEE,
        self::TYPE_MANUAL_ADJUSTMENT,
    ];

    // ── Status constants ─────────────────────────────────────────────────

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_REFUNDED   = 'refunded';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_DISPUTED   = 'disputed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
        self::STATUS_DISPUTED,
    ];

    // ── Platform fee rate ────────────────────────────────────────────────

    /** Platform fee as a decimal fraction (5% = 0.05). Configurable in Stage 2. */
    public const FEE_RATE = 0.05;

    // ── Boot ─────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->reference)) {
                $payment->reference = self::generateReference();
            }
            // Auto-compute net_amount when not explicitly set
            if (empty($payment->net_amount)) {
                $payment->net_amount = (float) $payment->amount - (float) ($payment->fee ?? 0);
            }
        });
    }

    // ── Reference generation ─────────────────────────────────────────────

    public static function generateReference(): string
    {
        $year = now()->year;
        $seq  = str_pad((string) (self::whereYear('created_at', $year)->count() + 1), 6, '0', STR_PAD_LEFT);
        return "PAY-{$year}-{$seq}";
    }

    // ── Relationships ────────────────────────────────────────────────────

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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function isRefundable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && in_array($this->type, [self::TYPE_ESCROW_FUNDED, self::TYPE_MILESTONE_RELEASED], true)
            && $this->refund_status === self::REFUND_STATUS_NONE;
    }

    public function isRefundRequested(): bool
    {
        return $this->refund_status === self::REFUND_STATUS_REQUESTED;
    }

    public function refundRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_requested_by');
    }

    public function refundApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refund_approved_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('payer_id', $userId)->orWhere('payee_id', $userId);
        });
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
