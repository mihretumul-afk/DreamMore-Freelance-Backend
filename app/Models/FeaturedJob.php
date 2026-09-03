<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FeaturedJob — tracks paid job visibility boosts.
 *
 * Employers pay to have their jobs appear higher in search results.
 * The boost is active from starts_at until expires_at, after which
 * the status changes to 'expired' via a scheduled Artisan command.
 */
class FeaturedJob extends Model
{
    use HasFactory;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'job_id',
        'employer_id',
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
     * The job being featured.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * The employer who paid for the feature.
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /**
     * Only active (non-expired) featured jobs.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
                     ->where('expires_at', '>', now());
    }

    /**
     * Only expired featured jobs.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED)
                     ->orWhere('expires_at', '<=', now());
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Check if this featured job is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->isFuture();
    }

    /**
     * Check if this featured job has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || $this->expires_at->isPast();
    }

    /**
     * Mark this featured job as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Check if a given job has an active featured listing.
     */
    public static function isJobFeatured(int $jobId): bool
    {
        return static::where('job_id', $jobId)
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
