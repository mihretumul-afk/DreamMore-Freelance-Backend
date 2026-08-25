<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * AdminUserController
 *
 * Manages admin-tier user accounts (users whose `role` = 'admin').
 * All write operations require Super Admin or specific admins.* permissions.
 *
 * Privilege escalation protections:
 *  - Only Super Admin can create admins (admins.create).
 *  - Only Super Admin can assign/revoke admin roles (admins.assign_role).
 *  - No non-Super-Admin can assign the super_admin role to anyone.
 *  - The last active Super Admin cannot be deactivated or deleted.
 */
class AdminUserController extends BaseApiController
{
    /**
     * GET /api/v1/admin/admins
     * Required permission: admins.view
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('admins.view')) {
            return $this->sendForbidden('You do not have permission to view admin users.');
        }

        $query = User::where('role', 'admin')
            ->with(['adminRoles:id,slug,name,is_system'])
            ->withCount('adminRoles');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('admin_role')) {
            $query->whereHas('adminRoles', fn ($q) => $q->where('slug', $request->input('admin_role')));
        }

        $admins = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            UserResource::collection($admins),
            'Admin users retrieved successfully.',
            200,
            [
                'current_page' => $admins->currentPage(),
                'last_page'    => $admins->lastPage(),
                'per_page'     => $admins->perPage(),
                'total'        => $admins->total(),
            ]
        );
    }

    /**
     * GET /api/v1/admin/admins/{user}
     * Required permission: admins.view
     */
    public function show(Request $request, User $user): JsonResponse
    {
        if (!$request->user()->hasPermission('admins.view')) {
            return $this->sendForbidden('You do not have permission to view admin users.');
        }

        if ($user->role !== 'admin') {
            return $this->sendError('User is not an admin.', [], 404);
        }

        $user->load('adminRoles.permissions:id,slug,name,group');

        return $this->sendResponse(
            array_merge((new UserResource($user))->resolve($request), [
                'admin_roles'       => $user->adminRoles,
                'all_permissions'   => $user->getAllPermissions(),
                'is_super_admin'    => $user->isSuperAdmin(),
            ]),
            'Admin user retrieved successfully.'
        );
    }

    /**
     * POST /api/v1/admin/admins
     * Restricted to Super Admin (admins.create).
     * Creates a new admin account with an optional initial role.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can create admin accounts.');
        }

        $validated = $request->validate([
            'name'       => ['required', 'string', 'max:191'],
            'email'      => ['required', 'email', 'max:191', 'unique:users,email'],
            'password'   => ['required', Password::min(8)->mixedCase()->numbers()],
            'admin_role' => ['nullable', 'string', 'exists:roles,slug'],
        ]);

        // Cannot assign super_admin role to anyone except through explicit escalation.
        if (isset($validated['admin_role']) && $validated['admin_role'] === Role::SUPER_ADMIN) {
            return $this->sendError('The Super Admin role cannot be assigned during account creation.', [], 422);
        }

        $admin = DB::transaction(function () use ($validated, $request) {
            $admin = User::create([
                'name'     => $validated['name'],
                'email'    => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role'     => 'admin',
                'status'   => 'active',
            ]);

            if (!empty($validated['admin_role'])) {
                $role = Role::where('slug', $validated['admin_role'])->first();
                if ($role) {
                    $admin->adminRoles()->attach($role->id, [
                        'assigned_by' => $request->user()->id,
                        'assigned_at' => now(),
                    ]);

                    AuditService::roleAssigned($admin->id, $role->id, $request->user()->id, [
                        'role_slug' => $role->slug,
                        'reason'    => 'Initial role during account creation',
                    ]);
                }
            }

            AuditService::adminCreated($admin->id, $request->user()->id, [
                'name'       => $admin->name,
                'email'      => $admin->email,
                'admin_role' => $validated['admin_role'] ?? null,
            ]);

            return $admin;
        });

        $admin->load('adminRoles:id,slug,name');

        return $this->sendResponse(
            new UserResource($admin),
            'Admin account created successfully.',
            201
        );
    }

    /**
     * PUT /api/v1/admin/admins/{user}/status
     * Required permission: admins.activate | admins.deactivate
     * Cannot deactivate self. Cannot deactivate the last active Super Admin.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($user->role !== 'admin') {
            return $this->sendError('User is not an admin.', [], 404);
        }

        $activate = $request->boolean('is_active');
        $requiredPermission = $activate ? 'admins.activate' : 'admins.deactivate';

        if (!$actor->hasPermission($requiredPermission)) {
            return $this->sendForbidden("You do not have permission to {$requiredPermission}.");
        }

        // Self-protection.
        if ($actor->id === $user->id && !$activate) {
            return $this->sendError('You cannot deactivate your own admin account.', [], 422);
        }

        // Last Super Admin protection.
        if (!$activate && $user->isSuperAdmin()) {
            $activeSuperAdminCount = User::where('role', 'admin')
                ->where('status', 'active')
                ->whereHas('adminRoles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
                ->count();

            if ($activeSuperAdminCount <= 1) {
                return $this->sendError('Cannot deactivate the last active Super Admin.', [], 422);
            }
        }

        $oldStatus = $user->status;
        $newStatus = $activate ? 'active' : 'suspended';

        DB::transaction(function () use ($user, $newStatus, $activate, $actor, $oldStatus) {
            $user->update(['status' => $newStatus]);

            $action = $activate ? AuditLog::ACTION_ADMIN_ACTIVATED : AuditLog::ACTION_ADMIN_DEACTIVATED;
            AuditService::log($action, AuditLog::MODULE_ADMINS, 'User', $user->id, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ], $actor->id);
        });

        return $this->sendResponse(
            new UserResource($user->fresh()),
            'Admin status updated successfully.'
        );
    }

    /**
     * PUT /api/v1/admin/admins/{user}/roles
     * Restricted to Super Admin (admins.assign_role).
     * Syncs the full set of admin roles for a given user.
     * Cannot assign super_admin to anyone unless the actor is Super Admin.
     * Cannot remove super_admin role from self if you are the last Super Admin.
     */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (!$actor->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can assign admin roles.');
        }

        if ($user->role !== 'admin') {
            return $this->sendError('User is not an admin.', [], 422);
        }

        $validated = $request->validate([
            'roles'   => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);

        $requestedSlugs = $validated['roles'];

        // Last Super Admin protection — cannot remove super_admin from self
        // if they are the only active Super Admin.
        if ($actor->id === $user->id && !in_array(Role::SUPER_ADMIN, $requestedSlugs, true)) {
            $activeSuperAdminCount = User::where('role', 'admin')
                ->where('status', 'active')
                ->whereHas('adminRoles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
                ->count();

            if ($activeSuperAdminCount <= 1 && $user->isSuperAdmin()) {
                return $this->sendError(
                    'Cannot remove Super Admin role: you are the last active Super Admin.',
                    [],
                    422
                );
            }
        }

        $roleIds = Role::whereIn('slug', $requestedSlugs)
            ->where('is_active', true)
            ->pluck('id');

        $oldSlugs = $user->getAdminRoleSlugs()->sort()->values()->all();

        DB::transaction(function () use ($user, $roleIds, $actor, $oldSlugs, $requestedSlugs) {
            $syncData = [];
            foreach ($roleIds as $rid) {
                $syncData[$rid] = ['assigned_by' => $actor->id, 'assigned_at' => now()];
            }
            $user->adminRoles()->sync($syncData);

            AuditService::log(AuditLog::ACTION_ROLE_ASSIGNED, AuditLog::MODULE_ROLES, 'User', $user->id, [
                'old_roles' => $oldSlugs,
                'new_roles' => $requestedSlugs,
            ], $actor->id);
        });

        $user->load('adminRoles:id,slug,name');

        return $this->sendResponse(
            new UserResource($user->fresh()),
            'Admin roles updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/admin/admins/{user}
     * Restricted to Super Admin (admins.create permission covers lifecycle management).
     * Cannot delete self. Cannot delete the last active Super Admin.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (!$actor->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can delete admin accounts.');
        }

        if ($user->role !== 'admin') {
            return $this->sendError('User is not an admin.', [], 404);
        }

        if ($actor->id === $user->id) {
            return $this->sendError('You cannot delete your own admin account.', [], 422);
        }

        // Last Super Admin protection.
        if ($user->isSuperAdmin()) {
            $activeSuperAdminCount = User::where('role', 'admin')
                ->where('status', 'active')
                ->whereHas('adminRoles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))
                ->count();

            if ($activeSuperAdminCount <= 1) {
                return $this->sendError('Cannot delete the last active Super Admin.', [], 422);
            }
        }

        DB::transaction(function () use ($user, $actor) {
            AuditService::adminDeleted($user->id, $actor->id, [
                'name'  => $user->name,
                'email' => $user->email,
            ]);

            $user->adminRoles()->detach();
            $user->tokens()->delete();
            $user->delete();
        });

        return $this->sendResponse(null, 'Admin account deleted successfully.');
    }

    /**
     * GET /api/v1/admin/audit-logs
     * Required permission: audit_logs.view
     * Filters: action, module, actor_id, subject_type, date_from, date_to, page
     */
    public function auditLogs(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('audit_logs.view')) {
            return $this->sendForbidden('You do not have permission to view audit logs.');
        }

        $query = AuditLog::with('actor:id,name,email,role')
            ->when($request->filled('action'),       fn ($q) => $q->where('action',       $request->input('action')))
            ->when($request->filled('module'),       fn ($q) => $q->where('module',       $request->input('module')))
            ->when($request->filled('actor_id'),     fn ($q) => $q->where('actor_id',     $request->input('actor_id')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->input('subject_type')))
            ->when($request->filled('date_from'),    fn ($q) => $q->where('created_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'),      fn ($q) => $q->where('created_at', '<=', $request->input('date_to') . ' 23:59:59'))
            ->orderByDesc('created_at');

        $logs = $query->paginate(25);

        return $this->sendResponse(
            $logs->items(),
            'Audit logs retrieved successfully.',
            200,
            [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ]
        );
    }
}
