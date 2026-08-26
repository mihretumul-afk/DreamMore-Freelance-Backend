<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    // No updated_at — append-only table.
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'action',
        'module',
        'description',
        'subject_type',
        'subject_id',
        'context',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ── Module constants ─────────────────────────────────────────────────

    public const MODULE_ADMINS        = 'admins';
    public const MODULE_ROLES         = 'roles';
    public const MODULE_PERMISSIONS   = 'permissions';
    public const MODULE_USERS         = 'users';
    public const MODULE_VERIFICATIONS = 'verifications';
    public const MODULE_DISPUTES      = 'disputes';
    public const MODULE_SETTINGS      = 'settings';
    public const MODULE_PAYMENTS      = 'payments';
    public const MODULE_MILESTONES    = 'milestones';

    // ── Action constants — Admins ────────────────────────────────────────

    public const ACTION_ADMIN_CREATED         = 'admin.created';
    public const ACTION_ADMIN_DEACTIVATED     = 'admin.deactivated';
    public const ACTION_ADMIN_ACTIVATED       = 'admin.activated';
    public const ACTION_ADMIN_DELETED         = 'admin.deleted';
    public const ACTION_ADMIN_EMAIL_CHANGED   = 'admin.email_changed';
    public const ACTION_ADMIN_PASSWORD_CHANGED = 'admin.password_changed';

    // ── Action constants — Roles ─────────────────────────────────────────

    public const ACTION_ROLE_CREATED  = 'role.created';
    public const ACTION_ROLE_UPDATED  = 'role.updated';
    public const ACTION_ROLE_DELETED  = 'role.deleted';
    public const ACTION_ROLE_ASSIGNED = 'role.assigned';
    public const ACTION_ROLE_REVOKED  = 'role.revoked';

    // ── Action constants — Permissions ────────────────────────────────────

    public const ACTION_PERMISSION_ADDED   = 'permission.added';
    public const ACTION_PERMISSION_REMOVED = 'permission.removed';
    public const ACTION_PERMISSION_CHANGED = 'permission.changed';

    // ── Action constants — Users ─────────────────────────────────────────

    public const ACTION_USER_SUSPENDED  = 'user.suspended';
    public const ACTION_USER_ACTIVATED  = 'user.activated';
    public const ACTION_USER_ROLE_CHANGED = 'user.role_changed';
    public const ACTION_USER_DELETED    = 'user.deleted';

    // ── Action constants — Verifications ──────────────────────────────────

    public const ACTION_VERIFICATION_APPROVED = 'verification.approved';
    public const ACTION_VERIFICATION_REJECTED = 'verification.rejected';
    public const ACTION_CREDENTIAL_APPROVED   = 'credential.approved';
    public const ACTION_CREDENTIAL_REJECTED   = 'credential.rejected';

    // ── Action constants — Disputes ───────────────────────────────────────

    public const ACTION_DISPUTE_RESOLVED  = 'dispute.resolved';
    public const ACTION_DISPUTE_DISMISSED = 'dispute.dismissed';
    public const ACTION_DISPUTE_DELETED   = 'dispute.deleted';

    // ── Action constants — Settings ───────────────────────────────────────

    public const ACTION_SETTINGS_CHANGED = 'settings.changed';

    // ── Action constants — Payments (Stage 3 forward) ─────────────────────

    public const ACTION_PAYMENT_VERIFIED  = 'payment.verified';
    public const ACTION_PAYMENT_PROCESSED = 'payment.processed';
    public const ACTION_PAYMENT_REFUNDED  = 'payment.refunded';

    // ── Action constants — Milestones ────────────────────────────────────

    public const ACTION_MILESTONE_FUNDED    = 'milestone.funded';
    public const ACTION_MILESTONE_RELEASED  = 'milestone.released';

    // ── Action constants — Payment methods ───────────────────────────────

    public const ACTION_PAYMENT_METHOD_ADDED   = 'payment_method.added';
    public const ACTION_PAYMENT_METHOD_REMOVED = 'payment_method.removed';

    // ── Action constants — Withdrawals ──────────────────────────────────

    public const ACTION_WITHDRAWAL_REQUESTED = 'withdrawal.requested';
    public const ACTION_WITHDRAWAL_PROCESSED = 'withdrawal.processed';
    public const ACTION_WITHDRAWAL_COMPLETED = 'withdrawal.completed';
    public const ACTION_WITHDRAWAL_FAILED    = 'withdrawal.failed';
    public const ACTION_WITHDRAWAL_CANCELLED = 'withdrawal.cancelled';

    // ── Action constants — Webhooks ─────────────────────────────────────

    public const ACTION_WEBHOOK_RECEIVED     = 'webhook.received';
    public const ACTION_WEBHOOK_PROCESSED    = 'webhook.processed';
    public const ACTION_WEBHOOK_FAILED       = 'webhook.failed';

    // ── Action constants — Payment failures ──────────────────────────────

    public const ACTION_PAYMENT_FAILED       = 'payment.failed';
    public const ACTION_PAYMENT_CANCELLED    = 'payment.cancelled';

    // ── Module constants (additional) ────────────────────────────────────

    public const MODULE_WITHDRAWALS = 'withdrawals';
    public const MODULE_WEBHOOKS    = 'webhooks';

    // ── Relationships ────────────────────────────────────────────────────

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeForActor($query, int $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeFromDate($query, string $date)
    {
        return $query->where('created_at', '>=', $date);
    }

    public function scopeToDate($query, string $date)
    {
        return $query->where('created_at', '<=', $date . ' 23:59:59');
    }
}
