<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'group',
        'description',
    ];

    // ── All defined permission slugs ────────────────────────────────────

    // Users
    public const USERS_VIEW     = 'users.view';
    public const USERS_EDIT     = 'users.edit';
    public const USERS_SUSPEND  = 'users.suspend';
    public const USERS_ACTIVATE = 'users.activate';
    public const USERS_VERIFY   = 'users.verify';

    // Jobs
    public const JOBS_VIEW     = 'jobs.view';
    public const JOBS_CREATE   = 'jobs.create';
    public const JOBS_EDIT     = 'jobs.edit';
    public const JOBS_APPROVE  = 'jobs.approve';
    public const JOBS_REJECT   = 'jobs.reject';
    public const JOBS_DELETE   = 'jobs.delete';
    public const JOBS_MODERATE = 'jobs.moderate';

    // Financial
    public const PAYMENTS_VIEW      = 'payments.view';
    public const PAYMENTS_PROCESS   = 'payments.process';
    public const PAYMENTS_REFUND    = 'payments.refund';
    public const MILESTONES_FUND    = 'milestones.fund';
    public const MILESTONES_RELEASE = 'milestones.release';
    public const TRANSACTIONS_VIEW  = 'transactions.view';
    public const WITHDRAWALS_VIEW   = 'withdrawals.view';
    public const WITHDRAWALS_MANAGE = 'withdrawals.manage';
    public const FINANCE_VIEW       = 'finance.view';
    public const FINANCE_MANAGE     = 'finance.manage';

    // Disputes
    public const DISPUTES_VIEW     = 'disputes.view';
    public const DISPUTES_REVIEW   = 'disputes.review';
    public const DISPUTES_RESOLVE  = 'disputes.resolve';
    public const DISPUTES_ESCALATE = 'disputes.escalate';

    // Contacts (footer Contact button)
    public const CONTACTS_MANAGE = 'contacts.manage';

    // Administrators
    public const ADMINS_VIEW        = 'admins.view';
    public const ADMINS_CREATE      = 'admins.create';
    public const ADMINS_EDIT        = 'admins.edit';
    public const ADMINS_ACTIVATE    = 'admins.activate';
    public const ADMINS_DEACTIVATE  = 'admins.deactivate';
    public const ADMINS_ASSIGN_ROLE = 'admins.assign_role';

    // Roles
    public const ROLES_VIEW   = 'roles.view';
    public const ROLES_CREATE = 'roles.create';
    public const ROLES_EDIT   = 'roles.edit';
    public const ROLES_DELETE = 'roles.delete';
    public const ROLES_ASSIGN = 'roles.assign';

    // Audit
    public const AUDIT_LOGS_VIEW = 'audit_logs.view';

    // Settings
    public const SETTINGS_VIEW = 'settings.view';
    public const SETTINGS_EDIT = 'settings.edit';

    // Admin Account Security
    public const ADMIN_ACCOUNT_VIEW    = 'admin_account.view';
    public const ADMIN_ACCOUNT_UPDATE_EMAIL = 'admin_account.update_email';
    public const ADMIN_ACCOUNT_CHANGE_PASSWORD = 'admin_account.change_password';

    // ── Relationships ────────────────────────────────────────────────────

    /**
     * Roles that include this permission.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions',
            'permission_id',
            'role_id'
        )->withPivot('granted_by', 'granted_at');
    }
}
