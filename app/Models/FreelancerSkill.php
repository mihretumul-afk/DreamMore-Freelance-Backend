<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class FreelancerSkill extends Pivot
{
    protected $table = 'freelancer_skills';

    protected $fillable = [
        'freelancer_profile_id',
        'skill_id',
        'years_of_experience',
    ];
}
