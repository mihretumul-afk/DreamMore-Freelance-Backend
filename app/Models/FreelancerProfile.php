<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class FreelancerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'approval_status',
        'approved_at',
        'rejection_reason',
        'headline',
        'overview',
        'hourly_rate',
        'experience_level',
        'location',
        'github_url',
        'linkedin_url',
        'website',
        'total_earnings',
        'completed_jobs_count',
        'rating',
        'availability_status',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'rating' => 'decimal:2',
        'completed_jobs_count' => 'integer',
        'approved_at' => 'datetime',
    ];

    // ─── Scopes ──────────────────────────────────────────────────────

    /**
     * Only approved freelancers — visible on the public marketplace.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved');
    }

    /**
     * Only pending freelancers — awaiting admin approval.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('approval_status', 'pending');
    }

    /**
     * Only rejected freelancers.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('approval_status', 'rejected');
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'freelancer_skills')
                    ->withPivot('years_of_experience')
                    ->withTimestamps();
    }

    public function savedByUsers(): HasMany
    {
        return $this->hasMany(SavedFreelancer::class);
    }

    // ─── Helper Methods ──────────────────────────────────────────────

    /**
     * Check if this freelancer is approved for marketplace visibility.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if this freelancer is pending approval.
     */
    public function isPending(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Approve this freelancer for marketplace visibility.
     */
    public function approve(): void
    {
        $this->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Reject this freelancer's application.
     */
    public function reject(?string $reason = null): void
    {
        $this->update([
            'approval_status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_at' => null,
        ]);
    }
}
