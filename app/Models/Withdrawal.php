<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'decimal:2',
            'fee'               => 'decimal:2',
            'net_amount'        => 'decimal:2',
            'provider_response' => 'array',
            'processed_at'      => 'datetime',
            'completed_at'      => 'datetime',
        ];
    }

    // ── Status constants ─────────────────────────────────────────────

    public const STATUS_REQUESTED  = 'requested';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    // ── Boot ─────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Withdrawal $w) {
            if (empty($w->reference)) {
                $w->reference = self::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        $year = now()->year;
        $seq  = str_pad((string) (self::whereYear('created_at', $year)->count() + 1), 6, '0', STR_PAD_LEFT);
        return "WTH-{$year}-{$seq}";
    }

    // ── Relationships ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_REQUESTED, self::STATUS_PROCESSING]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
}
