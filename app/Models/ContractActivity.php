<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'milestone_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    // ── Action Constants ──────────────────────────────────────────────

    // Contract actions
    public const ACTION_CONTRACT_CREATED = 'contract.created';
    public const ACTION_CONTRACT_STARTED = 'contract.started';
    public const ACTION_CONTRACT_PAUSED = 'contract.paused';
    public const ACTION_CONTRACT_RESUMED = 'contract.resumed';
    public const ACTION_CONTRACT_COMPLETED = 'contract.completed';
    public const ACTION_CONTRACT_CANCELLED = 'contract.cancelled';

    // Milestone actions
    public const ACTION_MILESTONE_CREATED = 'milestone.created';
    public const ACTION_MILESTONE_UPDATED = 'milestone.updated';
    public const ACTION_MILESTONE_DELETED = 'milestone.deleted';
    public const ACTION_MILESTONE_FUNDED = 'milestone.funded';
    public const ACTION_MILESTONE_STARTED = 'milestone.started';
    public const ACTION_MILESTONE_SUBMITTED = 'milestone.submitted';
    public const ACTION_MILESTONE_APPROVED = 'milestone.approved';
    public const ACTION_MILESTONE_RELEASED = 'milestone.released';
    public const ACTION_MILESTONE_REVISION_REQUESTED = 'milestone.revision_requested';

    // Payment actions
    public const ACTION_PAYMENT_RELEASED = 'payment.released';
    public const ACTION_REFUND_REQUESTED = 'refund.requested';

    // ── Relationships ─────────────────────────────────────────────────

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────

    public function scopeForContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    public function scopeForMilestone($query, int $milestoneId)
    {
        return $query->where('milestone_id', $milestoneId);
    }

    public function scopeForActor($query, int $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeRecent($query, int $limit = 50)
    {
        return $query->latest()->limit($limit);
    }
}
