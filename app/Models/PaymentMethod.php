<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory;

    // ── Type constants ────────────────────────────────────────────────────
    public const TYPE_CARD        = 'card';
    public const TYPE_BANK        = 'bank';
    public const TYPE_MOBILE_MONEY = 'mobile_money';

    // ── Provider constants ────────────────────────────────────────────────
    public const PROVIDER_TELEBIRR = 'telebirr';
    public const PROVIDER_CBE      = 'cbe';
    public const PROVIDER_STRIPE   = 'stripe';
    public const PROVIDER_SANDBOX  = 'sandbox';

    protected $fillable = [
        'user_id',
        'type',
        'nickname',
        'provider',
        'provider_token',
        'masked_identifier',
        'label',
        'display_label',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'cardholder_name',
        'bank_name',
        'account_name',
        'account_number_encrypted',
        'masked_account_number',
        'bank_code',
        'mobile_provider',
        'masked_phone',
        'is_default',
        'is_verified',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_verified' => 'boolean',
        'metadata'   => 'array',
        'account_number_encrypted' => 'encrypted',
        'bank_code' => 'integer',
    ];

    /**
     * Fields hidden from JSON/array serialization.
     * account_number_encrypted is NEVER exposed to the frontend —
     * it's only decrypted internally at the moment of calling Chapa's payout API.
     */
    protected $hidden = [
        'account_number_encrypted',
        'bank_code',
        'provider_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Set this as the default, unset others.
     */
    public function setAsDefault(): void
    {
        $this->user->paymentMethods()->where('id', '!=', $this->id)->update(['is_default' => false]);
        $this->update(['is_default' => true]);
    }

    /**
     * Get display label for the method.
     */
    public function getDisplayLabelAttribute(): string
    {
        if (!empty($this->attributes['display_label'])) {
            return (string) $this->attributes['display_label'];
        }

        return match ($this->type) {
            self::TYPE_CARD => ($this->card_brand ? ucfirst((string) $this->card_brand) . ' ' : '') . '••••' . ($this->card_last_four ?? '????'),
            self::TYPE_BANK => ($this->bank_name ?? 'Bank') . ' ••••' . ($this->masked_account_number ?? '????'),
            self::TYPE_MOBILE_MONEY => ($this->mobile_provider ?? 'Mobile') . ' •••' . (substr((string) ($this->masked_phone ?? ''), -4) ?: '????'),
            default => (string) ($this->nickname ?? 'Payment Method'),
        };
    }
}
