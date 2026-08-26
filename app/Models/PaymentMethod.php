<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'nickname',
        'provider',
        'provider_token',
        'masked_identifier',
        'display_label',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'cardholder_name',
        'bank_name',
        'account_name',
        'masked_account_number',
        'mobile_provider',
        'masked_phone',
        'is_default',
        'is_verified',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_default'  => 'boolean',
            'is_verified' => 'boolean',
            'metadata'    => 'array',
        ];
    }

    // ── Type constants ───────────────────────────────────────────────────
    public const TYPE_CARD         = 'card';
    public const TYPE_BANK_ACCOUNT = 'bank_account';
    public const TYPE_MOBILE_MONEY = 'mobile_money';

    public const TYPES = [
        self::TYPE_CARD,
        self::TYPE_BANK_ACCOUNT,
        self::TYPE_MOBILE_MONEY,
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * A safe display label for the UI.
     * Never exposes full card/account numbers.
     */
    public function getDisplayLabel(): string
    {
        if ($this->display_label) {
            return $this->display_label;
        }

        return match ($this->type) {
            self::TYPE_CARD => trim(($this->card_brand ?? 'Card') . ' ending in ' . ($this->card_last_four ?? '****')),
            self::TYPE_BANK_ACCOUNT => trim(($this->bank_name ?? 'Bank') . ' ' . ($this->masked_account_number ?? '')),
            self::TYPE_MOBILE_MONEY => trim(($this->mobile_provider ?? 'Mobile') . ' ' . ($this->masked_phone ?? '')),
            default => $this->nickname ?? 'Payment Method',
        };
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
