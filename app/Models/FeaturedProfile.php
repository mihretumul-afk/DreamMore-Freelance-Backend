<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeaturedProfile — tracks paid freelancer profile visibility boosts.
 *
 * Freelancers pay to have their profiles appear higher in search results.
 * The boost is active from starts_at until expires_at, after which
 * the status changes to 'expired' via a scheduled Artisan command.
 */
class FeaturedProfile extends Model
{
    use HasFactory;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'amount_paid',
        'duration_days',
        'starts_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'amount_paid'   => 'decimal:2',
        'duration_days' => 'integer',
        'starts_at'     => 'datetime',
        'expires_at'    => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    /**
     * The freelancer user who paid for the feature.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Only active (non-expired) featured profiles.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('expires_at', '>', now());
    }

    /**
     * Only expired featured profiles.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED)
                     ->orWhere('expires_at', '<=', now());
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Check if this featured profile is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture();
    }

    /**
     * Check if this featured profile has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || $this->expires_at->isPast();
    }

    /**
     * Mark this featured profile as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Check if a given user has an active featured profile.
     */
    public static function isProfileFeatured(int $userId): bool
    {
        return static::where('user_id', $userId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
