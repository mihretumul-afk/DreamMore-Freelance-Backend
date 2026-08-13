<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFreelancer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'freelancer_profile_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function freelancerProfile(): BelongsTo
    {
        return $this->belongsTo(FreelancerProfile::class);
    }
}
