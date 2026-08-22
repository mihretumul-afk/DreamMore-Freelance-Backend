<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneSubmissionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'uploader_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(MilestoneSubmission::class, 'submission_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }
}
