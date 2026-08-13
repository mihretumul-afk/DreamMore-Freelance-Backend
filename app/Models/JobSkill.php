<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class JobSkill extends Pivot
{
    protected $table = 'job_skills';

    protected $fillable = [
        'job_id',
        'skill_id',
    ];
}
