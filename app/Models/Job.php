<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Job extends Model
{
    use HasFactory;

    protected $table = 'marketplace_jobs';

    protected $fillable = [
        'employer_id',
        'category_id',
        'title',
        'slug',
        'description',
        'budget_type',
        'min_budget',
        'max_budget',
        'experience_level',
        'location_type',
        'location',
        'status',
        'currency',
        'proposals_count',
        'deadline',
        'published_at',
    ];

    protected $casts = [
        'min_budget' => 'decimal:2',
        'max_budget' => 'decimal:2',
        'proposals_count' => 'integer',
        'deadline' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * New jobs are created in the open (published) state, in Ethiopian Birr.
     */
    protected $attributes = [
        'currency' => 'ETB',
    ];

    /**
     * Scope a query to only open (published) jobs.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'job_skills')
                    ->withTimestamps();
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function savedByUsers(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }
}
