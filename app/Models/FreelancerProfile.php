<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FreelancerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'freelancer_skills')
                    ->withPivot('years_of_experience')
                    ->withTimestamps();
    }
}
