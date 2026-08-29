<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'submitted_by',
        'description',
        'links',
        'files',
        'status',
        'revision_note',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'links'        => 'array',
        'files'        => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
