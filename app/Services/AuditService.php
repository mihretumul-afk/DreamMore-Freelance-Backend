<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * AuditService — central, append-only audit trail writer.
 *
 * Every important admin action should call one of the named wrappers below.
 * The core `log()` method accepts a `description` string that is stored
 * verbatim and shown in the UI instead of a raw action slug.
 *
 * Human-readable description format:
 *   "{Actor role} {Actor name} {past-tense verb} {target description}"
 *   e.g. "Support Admin Sarah suspended user john@example.com"
 *
 * This format makes the audit trail immediately readable without decoding
 * context JSON.
 */
class AuditService
{
    /**
     * Write one audit log entry.
     *
     * @param  string      $action      AuditLog::ACTION_* constant
     * @param  string      $module      AuditLog::MODULE_* constant
     * @param  string|null $subjectType Model class name, e.g. 'User', 'Role'
     * @param  int|null    $subjectId   PK of the subject record
     * @param  array       $context     Old/new values, reason, IDs, etc.
     * @param  int|null    $actorId     Performing user (null = system/CLI)
     * @param  string|null $description Pre-rendered human-readable sentence
     */
    public static function log(
        string $action,
        string $module = AuditLog::MODULE_ADMINS,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
        ?int $actorId = null,
        ?string $description = null,
    ): AuditLog {
        if ($actorId === null) {
            $actorId = RequestFacade::user()?->id;
        }

        $request = app()->runningInConsole() ? null : RequestFacade::instance();

        return AuditLog::create([
            'actor_id'     => $actorId,
            'action'       => $action,
            'module'       => $module,
            'description'  => $description,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'context'      => empty($context) ? null : $context,
            'ip_address'   => $request?->ip(),
            'user_agent'   => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    // ── Internal helper: build actor label ───────────────────────────────

    private static function actorLabel(?int $actorId): string
    {
        if (!$actorId) {
            return 'System';
        }
        $user = \App\Models\User::find($actorId);
        if (!$user) {
            return "Admin #{$actorId}";
        }
        $roleLabel = match ($user->role) {
            'admin' => 'Admin',
            default => ucfirst($user->role),
        };
        return "{$roleLabel} {$user->name}";
    }

    // ═══════════════════════════════════════════════════════════════════
    // ADMIN lifecycle
    // ═══════════════════════════════════════════════════════════════════

    public static function adminCreated(int $newAdminId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "Admin #{$newAdminId}";
        return self::log(
            AuditLog::ACTION_ADMIN_CREATED, AuditLog::MODULE_ADMINS,
            'User', $newAdminId, $context, $actorId,
            "{$label} created admin account for {$name}"
        );
    }

    public static function adminDeactivated(int $adminId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $target = self::actorLabel($adminId);
        return self::log(
            AuditLog::ACTION_ADMIN_DEACTIVATED, AuditLog::MODULE_ADMINS,
            'User', $adminId, $context, $actorId,
            "{$label} deactivated admin account {$target}"
        );
    }

    public static function adminActivated(int $adminId, int $actorId, array $context = []): AuditLog
    {
        $label  = self::actorLabel($actorId);
        $target = self::actorLabel($adminId);
        return self::log(
            AuditLog::ACTION_ADMIN_ACTIVATED, AuditLog::MODULE_ADMINS,
            'User', $adminId, $context, $actorId,
            "{$label} activated admin account {$target}"
        );
    }

    public static function adminDeleted(int $adminId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "Admin #{$adminId}";
        return self::log(
            AuditLog::ACTION_ADMIN_DELETED, AuditLog::MODULE_ADMINS,
            'User', $adminId, $context, $actorId,
            "{$label} permanently deleted admin account for {$name}"
        );
    }

    public static function adminEmailChanged(int $adminId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_ADMIN_EMAIL_CHANGED, AuditLog::MODULE_ADMINS,
            'User', $adminId, $context, $actorId,
            "{$label} changed email for Admin #{$adminId}"
        );
    }

    public static function adminPasswordChanged(int $adminId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_ADMIN_PASSWORD_CHANGED, AuditLog::MODULE_ADMINS,
            'User', $adminId, $context, $actorId,
            "{$label} changed password for Admin #{$adminId}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // ROLE lifecycle
    // ═══════════════════════════════════════════════════════════════════

    public static function roleCreated(int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "Role #{$roleId}";
        return self::log(
            AuditLog::ACTION_ROLE_CREATED, AuditLog::MODULE_ROLES,
            'Role', $roleId, $context, $actorId,
            "{$label} created role \"{$name}\""
        );
    }

    public static function roleUpdated(int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['new']['name'] ?? "Role #{$roleId}";
        return self::log(
            AuditLog::ACTION_ROLE_UPDATED, AuditLog::MODULE_ROLES,
            'Role', $roleId, $context, $actorId,
            "{$label} updated role \"{$name}\""
        );
    }

    public static function roleDeleted(int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "Role #{$roleId}";
        return self::log(
            AuditLog::ACTION_ROLE_DELETED, AuditLog::MODULE_ROLES,
            'Role', $roleId, $context, $actorId,
            "{$label} deleted role \"{$name}\""
        );
    }

    public static function roleAssigned(int $userId, int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label    = self::actorLabel($actorId);
        $roleSlug = $context['role_slug'] ?? "role #{$roleId}";
        $target   = self::actorLabel($userId);
        return self::log(
            AuditLog::ACTION_ROLE_ASSIGNED, AuditLog::MODULE_ROLES,
            'User', $userId, array_merge($context, ['role_id' => $roleId]), $actorId,
            "{$label} assigned role \"{$roleSlug}\" to {$target}"
        );
    }

    public static function roleRevoked(int $userId, int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label    = self::actorLabel($actorId);
        $roleSlug = $context['role_slug'] ?? "role #{$roleId}";
        $target   = self::actorLabel($userId);
        return self::log(
            AuditLog::ACTION_ROLE_REVOKED, AuditLog::MODULE_ROLES,
            'User', $userId, array_merge($context, ['role_id' => $roleId]), $actorId,
            "{$label} revoked role \"{$roleSlug}\" from {$target}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // PERMISSION lifecycle
    // ═══════════════════════════════════════════════════════════════════

    public static function permissionAdded(int $roleId, string $permissionSlug, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_PERMISSION_ADDED, AuditLog::MODULE_PERMISSIONS,
            'Role', $roleId, array_merge($context, ['permission' => $permissionSlug]), $actorId,
            "{$label} added permission \"{$permissionSlug}\" to Role #{$roleId}"
        );
    }

    public static function permissionRemoved(int $roleId, string $permissionSlug, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_PERMISSION_REMOVED, AuditLog::MODULE_PERMISSIONS,
            'Role', $roleId, array_merge($context, ['permission' => $permissionSlug]), $actorId,
            "{$label} removed permission \"{$permissionSlug}\" from Role #{$roleId}"
        );
    }

    public static function permissionChanged(int $roleId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $added   = count($context['new_permissions'] ?? []);
        $removed = count(array_diff($context['old_permissions'] ?? [], $context['new_permissions'] ?? []));
        $desc = "{$label} updated permissions on Role #{$roleId}";
        if ($added !== 0 || $removed !== 0) {
            $desc .= " (now {$added} permissions; {$removed} removed)";
        }
        return self::log(
            AuditLog::ACTION_PERMISSION_CHANGED, AuditLog::MODULE_PERMISSIONS,
            'Role', $roleId, $context, $actorId, $desc
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // USER actions (marketplace user management by admins)
    // ═══════════════════════════════════════════════════════════════════

    public static function userSuspended(int $userId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "User #{$userId}";
        return self::log(
            AuditLog::ACTION_USER_SUSPENDED, AuditLog::MODULE_USERS,
            'User', $userId, $context, $actorId,
            "{$label} suspended user {$name}"
        );
    }

    public static function userActivated(int $userId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "User #{$userId}";
        return self::log(
            AuditLog::ACTION_USER_ACTIVATED, AuditLog::MODULE_USERS,
            'User', $userId, $context, $actorId,
            "{$label} reactivated user {$name}"
        );
    }

    public static function userRoleChanged(int $userId, int $actorId, array $context = []): AuditLog
    {
        $label   = self::actorLabel($actorId);
        $name    = $context['name'] ?? "User #{$userId}";
        $oldRole = $context['old_role'] ?? '?';
        $newRole = $context['new_role'] ?? '?';
        return self::log(
            AuditLog::ACTION_USER_ROLE_CHANGED, AuditLog::MODULE_USERS,
            'User', $userId, $context, $actorId,
            "{$label} changed role of {$name} from {$oldRole} to {$newRole}"
        );
    }

    public static function userDeleted(int $userId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $name  = $context['name'] ?? "User #{$userId}";
        return self::log(
            AuditLog::ACTION_USER_DELETED, AuditLog::MODULE_USERS,
            'User', $userId, $context, $actorId,
            "{$label} deleted user {$name}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // VERIFICATION actions
    // ═══════════════════════════════════════════════════════════════════

    public static function verificationApproved(int $verificationId, int $actorId, array $context = []): AuditLog
    {
        $label    = self::actorLabel($actorId);
        $userName = $context['user_name'] ?? "Verification #{$verificationId}";
        return self::log(
            AuditLog::ACTION_VERIFICATION_APPROVED, AuditLog::MODULE_VERIFICATIONS,
            'Verification', $verificationId, $context, $actorId,
            "{$label} approved identity verification for {$userName}"
        );
    }

    public static function verificationRejected(int $verificationId, int $actorId, array $context = []): AuditLog
    {
        $label    = self::actorLabel($actorId);
        $userName = $context['user_name'] ?? "Verification #{$verificationId}";
        return self::log(
            AuditLog::ACTION_VERIFICATION_REJECTED, AuditLog::MODULE_VERIFICATIONS,
            'Verification', $verificationId, $context, $actorId,
            "{$label} rejected identity verification for {$userName}"
        );
    }

    public static function credentialApproved(int $credentialId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $title = $context['credential_title'] ?? "Credential #{$credentialId}";
        $name  = $context['user_name'] ?? '';
        return self::log(
            AuditLog::ACTION_CREDENTIAL_APPROVED, AuditLog::MODULE_VERIFICATIONS,
            'Credential', $credentialId, $context, $actorId,
            "{$label} approved credential \"{$title}\"" . ($name ? " for {$name}" : '')
        );
    }

    public static function credentialRejected(int $credentialId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        $title = $context['credential_title'] ?? "Credential #{$credentialId}";
        $name  = $context['user_name'] ?? '';
        return self::log(
            AuditLog::ACTION_CREDENTIAL_REJECTED, AuditLog::MODULE_VERIFICATIONS,
            'Credential', $credentialId, $context, $actorId,
            "{$label} rejected credential \"{$title}\"" . ($name ? " for {$name}" : '')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // DISPUTE actions
    // ═══════════════════════════════════════════════════════════════════

    public static function disputeResolved(int $reportId, int $actorId, array $context = []): AuditLog
    {
        $label      = self::actorLabel($actorId);
        $reportDesc = $context['reason'] ?? "Report #{$reportId}";
        return self::log(
            AuditLog::ACTION_DISPUTE_RESOLVED, AuditLog::MODULE_DISPUTES,
            'Report', $reportId, $context, $actorId,
            "{$label} resolved dispute: {$reportDesc}"
        );
    }

    public static function disputeDismissed(int $reportId, int $actorId, array $context = []): AuditLog
    {
        $label      = self::actorLabel($actorId);
        $reportDesc = $context['reason'] ?? "Report #{$reportId}";
        return self::log(
            AuditLog::ACTION_DISPUTE_DISMISSED, AuditLog::MODULE_DISPUTES,
            'Report', $reportId, $context, $actorId,
            "{$label} dismissed dispute: {$reportDesc}"
        );
    }

    public static function disputeDeleted(int $reportId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_DISPUTE_DELETED, AuditLog::MODULE_DISPUTES,
            'Report', $reportId, $context, $actorId,
            "{$label} deleted report #{$reportId}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // SETTINGS actions
    // ═══════════════════════════════════════════════════════════════════

    public static function settingsChanged(int $actorId, array $context = []): AuditLog
    {
        $label   = self::actorLabel($actorId);
        $keys    = array_keys($context['changed'] ?? $context);
        $keyList = implode(', ', array_slice($keys, 0, 4));
        if (count($keys) > 4) {
            $keyList .= ' +' . (count($keys) - 4) . ' more';
        }
        return self::log(
            AuditLog::ACTION_SETTINGS_CHANGED, AuditLog::MODULE_SETTINGS,
            'AdminSetting', null, $context, $actorId,
            "{$label} updated platform settings: {$keyList}"
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAYMENT actions (stubs — wired when payment system is built)
    // ═══════════════════════════════════════════════════════════════════

    public static function paymentVerified(int $paymentId, int $actorId, array $context = []): AuditLog
    {
        $label = self::actorLabel($actorId);
        return self::log(
            AuditLog::ACTION_PAYMENT_VERIFIED, AuditLog::MODULE_PAYMENTS,
            'Payment', $paymentId, $context, $actorId,
            "{$label} verified payment #{$paymentId}"
        );
    }

    public static function paymentRefunded(int $paymentId, int $actorId, array $context = []): AuditLog
    {
        $label  = self::actorLabel($actorId);
        $amount = $context['amount'] ?? '';
        return self::log(
            AuditLog::ACTION_PAYMENT_REFUNDED, AuditLog::MODULE_PAYMENTS,
            'Payment', $paymentId, $context, $actorId,
            "{$label} issued refund" . ($amount ? " of {$amount}" : '') . " for payment #{$paymentId}"
        );
    }
}
