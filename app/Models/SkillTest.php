<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'skill_id',
        'title',
        'description',
        'passing_score',
        'time_limit_minutes',
        'is_active',
    ];

    protected $casts = [
        'passing_score' => 'integer',
        'time_limit_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SkillTestQuestion::class)->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SkillTestAttempt::class);
    }

    /**
     * Check if a user has already passed this test.
     */
    public function userHasPassed(int $userId): bool
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->where('passed', true)
            ->exists();
    }
}
