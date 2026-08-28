<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'type',
        'issuing_organization',
        'certificate_identifier',
        'description',
        'issue_date',
        'expiry_date',
        'file_path',
        'file_original_name',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        // LMS integration fields
        'verification_source',
        'lms_certificate_id',
        'lms_course_id',
        'lms_course_name',
        'auto_verified',
        'test_required',
        'test_status',
        'admin_notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'reviewed_at' => 'datetime',
        'auto_verified' => 'boolean',
        'test_required' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'pending',
        'type' => 'external_certificate',
        'test_status' => 'not_required',
    ];

    // LMS certificate types that are auto-verified from trusted source
    const TYPE_DREAM_MORE = 'dream_more_certificate';
    const TYPE_EXTERNAL = 'external_certificate';
    const SOURCE_LMS = 'dream_more_lms';
    const SOURCE_EXTERNAL = 'external_manual';

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_RESUBMISSION_REQUIRED = 'resubmission_required';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function skillTestAttempts(): HasMany
    {
        return $this->hasMany(SkillTestAttempt::class);
    }

    /**
     * Check if this is a Dream More LMS-verified certificate.
     */
    public function isDreamMoreCertified(): bool
    {
        return $this->type === self::TYPE_DREAM_MORE
            && $this->verification_source === self::SOURCE_LMS
            && $this->auto_verified
            && $this->status === 'approved';
    }

    /**
     * Check if this credential requires a skill test.
     */
    public function needsTest(): bool
    {
        return $this->test_required && $this->test_status !== 'passed';
    }

    /**
     * Check if the test exemption applies (LMS certified = no test needed).
     */
    public function isTestExempt(): bool
    {
        return $this->isDreamMoreCertified();
    }
}
