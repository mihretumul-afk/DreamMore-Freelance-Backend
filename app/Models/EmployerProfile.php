<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'company_description',
        'website',
        'industry',
        'company_size',
        'location',
        'total_spent',
        'posted_jobs_count',
        'rating',
    ];

    protected $casts = [
        'total_spent' => 'decimal:2',
        'rating' => 'decimal:2',
        'posted_jobs_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
