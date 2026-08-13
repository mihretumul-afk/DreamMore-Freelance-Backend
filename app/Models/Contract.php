<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'proposal_id',
        'employer_id',
        'freelancer_id',
        'title',
        'budget_type',
        'agreed_rate',
        'total_amount',
        'status',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'agreed_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
