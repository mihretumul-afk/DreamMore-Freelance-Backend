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
        'estimated_duration',
        'status',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }
}
