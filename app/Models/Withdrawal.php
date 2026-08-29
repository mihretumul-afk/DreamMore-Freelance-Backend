<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    use HasFactory;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_REQUESTED  = 'requested';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REJECTED   = 'rejected';

    protected $fillable = [
        'reference',
        'user_id',
        'payment_method_id',
        'amount',
        'fee',
        'net_amount',
        'currency',
        'status',
        'provider',
        'provider_reference',
        'provider_response',
        'failure_reason',
        'admin_notes',
        'processed_by',
        'processed_at',
        'completed_at',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'fee'          => 'decimal:2',
        'net_amount'   => 'decimal:2',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'rejected_at'  => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'ETB',
        'status'   => self::STATUS_REQUESTED,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Generate a unique withdrawal reference.
     */
    public static function generateReference(): string
    {
        return 'WDR-' . strtoupper(uniqid());
    }
}
