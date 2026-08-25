<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * AdminSecurityController
 *
 * Allows authenticated admins to securely change their own email address
 * and password. All operations use the currently authenticated user from
 * the Sanctum token — no user ID is accepted from the request body.
 *
 * Security measures:
 *  - Current password is verified before any credential change.
 *  - Only the authenticated admin can change their own credentials.
 *  - Passwords are never returned in API responses.
 *  - Passwords are never logged or stored in plaintext.
 *  - All inputs are validated server-side.
 *  - New password uses Laravel's hashed cast (auto-hashing via model).
 */
class AdminSecurityController extends BaseApiController
{
    /**
     * GET /api/v1/admin/account/security
     *
     * Return the current admin's account information (email only — never password).
     * Requires admin_account.view permission.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasPermission('admin_account.view')) {
            return $this->sendForbidden('You do not have permission to view account settings.');
        }

        return $this->sendResponse([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
            'admin_roles' => $user->adminRoles()->pluck('slug'),
        ], 'Account information retrieved successfully.');
    }

    /**
     * PUT /api/v1/admin/account/email
     *
     * Update the authenticated admin's email address.
     * Requires the current password for verification.
     * Requires admin_account.update_email permission.
     */
    public function updateEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasPermission('admin_account.update_email')) {
            return $this->sendForbidden('You do not have permission to update your email address.');
        }

        $validated = $request->validate([
            'email'            => ['required', 'email', 'max:191', 'unique:users,email'],
            'current_password' => ['required', 'string'],
        ]);

        // Verify current password.
        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->sendError('The current password is incorrect.', [], 422);
        }

        $oldEmail = $user->email;
        $newEmail = strtolower(trim($validated['email']));

        // No-op if the email hasn't actually changed.
        if ($newEmail === strtolower($oldEmail)) {
            return $this->sendError('The new email is the same as the current email.', [], 422);
        }

        $user->update(['email' => $newEmail]);

        // Audit log — never record the email value itself for security.
        AuditService::adminEmailChanged($user->id, $user->id, [
            'old_email_domain' => substr(strrchr($oldEmail, '@'), 1),
            'new_email_domain' => substr(strrchr($newEmail, '@'), 1),
        ]);

        return $this->sendResponse([
            'email' => $user->email,
        ], 'Email address updated successfully.');
    }

    /**
     * PUT /api/v1/admin/account/password
     *
     * Update the authenticated admin's password.
     * Requires the current password for verification.
     * Requires admin_account.change_password permission.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasPermission('admin_account.change_password')) {
            return $this->sendForbidden('You do not have permission to change your password.');
        }

        $validated = $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'password_confirmation' => ['required', 'string'],
        ]);

        // Verify current password.
        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->sendError('The current password is incorrect.', [], 422);
        }

        // Prevent reusing the same password.
        if (Hash::check($validated['password'], $user->password)) {
            return $this->sendError('The new password must be different from the current password.', [], 422);
        }

        $user->update([
            'password' => $validated['password'],
        ]);

        // Revoke all other sessions (tokens) for security — the user must
        // re-authenticate on other devices with the new password.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        // Audit log — never record any password data.
        AuditService::adminPasswordChanged($user->id, $user->id);

        return $this->sendResponse(null, 'Password changed successfully. Other sessions have been invalidated.');
    }
}
