<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'freelancer_id',
        'cover_letter',
        'bid_amount',
        'currency',
        'estimated_duration',
        'proposed_milestones',
        'status',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'proposed_milestones' => 'array',
    ];

    /**
     * New proposals start as pending, in Ethiopian Birr.
     */
    protected $attributes = [
        'currency' => 'ETB',
        'status' => 'pending',
    ];

    /**
     * Scope a query to active (not yet rejected or withdrawn) proposals.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'shortlisted']);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function portfolioItems()
    {
        return $this->belongsToMany(PortfolioItem::class, 'proposal_portfolio_items')
            ->withTimestamps();
    }
}
