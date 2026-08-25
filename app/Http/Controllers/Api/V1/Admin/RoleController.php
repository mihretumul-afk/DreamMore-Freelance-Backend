<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Role;
use App\Models\Permission;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * RoleController
 *
 * Manages admin sub-roles. All write operations require Super Admin.
 * Viewing roles requires roles.view permission.
 */
class RoleController extends BaseApiController
{
    /**
     * GET /api/v1/admin/roles
     * Required permission: roles.view
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (!$actor->hasPermission('roles.view')) {
            return $this->sendForbidden('You do not have permission to view roles.');
        }

        $roles = Role::with('permissions:id,slug,name,group')
            ->withCount('users')
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', (bool) $request->input('active')))
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        return $this->sendResponse($roles, 'Roles retrieved successfully.');
    }

    /**
     * GET /api/v1/admin/roles/{role}
     * Required permission: roles.view
     */
    public function show(Request $request, Role $role): JsonResponse
    {
        if (!$request->user()->hasPermission('roles.view')) {
            return $this->sendForbidden('You do not have permission to view roles.');
        }

        $role->load('permissions:id,slug,name,group');
        $role->loadCount('users');

        return $this->sendResponse($role, 'Role retrieved successfully.');
    }

    /**
     * POST /api/v1/admin/roles
     * Restricted to Super Admin only (roles.create).
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can create roles.');
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'slug'        => ['required', 'string', 'max:100', 'unique:roles,slug', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        $role = DB::transaction(function () use ($validated, $request) {
            $role = Role::create([
                'slug'        => $validated['slug'],
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_system'   => false,
                'is_active'   => true,
            ]);

            if (!empty($validated['permissions'])) {
                $permissionIds = Permission::whereIn('slug', $validated['permissions'])->pluck('id');
                $syncData = [];
                foreach ($permissionIds as $pid) {
                    $syncData[$pid] = ['granted_by' => $request->user()->id, 'granted_at' => now()];
                }
                $role->permissions()->sync($syncData);
            }

            AuditService::roleCreated($role->id, $request->user()->id, [
                'slug'        => $role->slug,
                'name'        => $role->name,
                'permissions' => $validated['permissions'] ?? [],
            ]);

            return $role;
        });

        $role->load('permissions:id,slug,name,group');

        return $this->sendResponse($role, 'Role created successfully.', 201);
    }

    /**
     * PUT /api/v1/admin/roles/{role}
     * Restricted to Super Admin only (roles.edit).
     * System role names/slugs cannot be changed.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can edit roles.');
        }

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        // System roles cannot be renamed or deactivated via this endpoint.
        if ($role->is_system && isset($validated['name'])) {
            return $this->sendError('System role names cannot be changed.', [], 422);
        }

        // Prevent deactivating the super_admin role.
        if ($role->slug === Role::SUPER_ADMIN && isset($validated['is_active']) && !$validated['is_active']) {
            return $this->sendError('The Super Admin role cannot be deactivated.', [], 422);
        }

        $old = $role->only(['name', 'description', 'is_active']);

        DB::transaction(function () use ($role, $validated, $request, $old) {
            $role->update($validated);

            AuditService::roleUpdated($role->id, $request->user()->id, [
                'old' => $old,
                'new' => $role->fresh()->only(['name', 'description', 'is_active']),
            ]);
        });

        $role->load('permissions:id,slug,name,group');

        return $this->sendResponse($role->fresh(), 'Role updated successfully.');
    }

    /**
     * DELETE /api/v1/admin/roles/{role}
     * Restricted to Super Admin (roles.delete). System roles cannot be deleted.
     */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can delete roles.');
        }

        if ($role->is_system) {
            return $this->sendError('System roles cannot be deleted.', [], 422);
        }

        DB::transaction(function () use ($role, $request) {
            AuditService::roleDeleted($role->id, $request->user()->id, [
                'slug' => $role->slug,
                'name' => $role->name,
            ]);

            $role->permissions()->detach();
            $role->users()->detach();
            $role->delete();
        });

        return $this->sendResponse(null, 'Role deleted successfully.');
    }

    /**
     * PUT /api/v1/admin/roles/{role}/permissions
     * Sync permissions on a role. Restricted to Super Admin (roles.assign).
     */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        if (!$request->user()->isSuperAdmin()) {
            return $this->sendForbidden('Only Super Admin can assign permissions to roles.');
        }

        $validated = $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        // Super Admin role always retains all permissions — cannot be reduced.
        if ($role->slug === Role::SUPER_ADMIN) {
            return $this->sendError('Super Admin role permissions cannot be modified.', [], 422);
        }

        $oldSlugs = $role->permissions()->pluck('slug')->sort()->values()->all();

        $permissionIds = Permission::whereIn('slug', $validated['permissions'])->pluck('id');
        $syncData = [];
        foreach ($permissionIds as $pid) {
            $syncData[$pid] = ['granted_by' => $request->user()->id, 'granted_at' => now()];
        }

        DB::transaction(function () use ($role, $syncData, $validated, $request, $oldSlugs) {
            $role->permissions()->sync($syncData);

            AuditService::permissionChanged($role->id, $request->user()->id, [
                'old_permissions' => $oldSlugs,
                'new_permissions' => $validated['permissions'],
            ]);
        });

        $role->load('permissions:id,slug,name,group');

        return $this->sendResponse($role->fresh(), 'Role permissions updated successfully.');
    }
}
