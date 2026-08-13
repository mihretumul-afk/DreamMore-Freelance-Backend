<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function freelancers(): BelongsToMany
    {
        return $this->belongsToMany(FreelancerProfile::class, 'freelancer_skills')
                    ->withPivot('years_of_experience')
                    ->withTimestamps();
    }

    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(Job::class, 'job_skills')
                    ->withTimestamps();
    }
}
