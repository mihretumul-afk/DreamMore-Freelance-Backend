<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    use HasFactory;

    // ── Status constants ──────────────────────────────────────────────────
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_UNFUNDED = 'unfunded';
    public const STATUS_FUNDED   = 'funded';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_IN_REVIEW   = 'in_review';
    public const STATUS_REVISION    = 'revision_requested';
    public const STATUS_APPROVED    = 'approved';
    public const STATUS_RELEASED    = 'released';
    public const STATUS_DISPUTED    = 'disputed';
    public const STATUS_CANCELLED   = 'cancelled';

    protected $fillable = [
        'contract_id',
        'title',
        'description',
        'deliverables',
        'amount',
        'status',
        'due_date',
        'created_by',
        'submitted_at',
        'approved_at',
        'accepted_at',
        'started_at',
        'funded_at',
        'released_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'due_date'      => 'datetime',
        'submitted_at'  => 'datetime',
        'approved_at'   => 'datetime',
        'accepted_at'   => 'datetime',
        'started_at'    => 'datetime',
        'funded_at'     => 'datetime',
        'released_at'   => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(MilestoneSubmission::class)->orderByDesc('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MilestoneAttachment::class)->orderByDesc('created_at');
    }

    // ── Helper methods ────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canStartWork(): bool
    {
        return $this->status === self::STATUS_FUNDED;
    }

    public function canBeFunded(): bool
    {
        return $this->status === self::STATUS_DRAFT || $this->status === self::STATUS_UNFUNDED;
    }

    public function isFunded(): bool
    {
        return $this->status === self::STATUS_FUNDED || $this->status === self::STATUS_IN_PROGRESS
            || $this->status === self::STATUS_SUBMITTED || $this->status === self::STATUS_IN_REVIEW
            || $this->status === self::STATUS_REVISION || $this->status === self::STATUS_APPROVED
            || $this->status === self::STATUS_RELEASED;
    }

    public function canSubmitWork(): bool
    {
        return in_array($this->status, [self::STATUS_IN_PROGRESS, self::STATUS_REVISION], true);
    }

    public function canReview(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_IN_REVIEW], true);
    }
}
