<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PermissionController
 *
 * Read-only listing of all platform permissions.
 * Permissions are defined at code level and seeded; they cannot be created
 * or deleted via the API — only their assignment to roles can change.
 */
class PermissionController extends BaseApiController
{
    /**
     * GET /api/v1/admin/permissions
     * Required permission: roles.view
     * Returns permissions grouped by their domain.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('roles.view')) {
            return $this->sendForbidden('You do not have permission to view permissions.');
        }

        $permissions = Permission::orderBy('group')->orderBy('name')->get();

        $grouped = $permissions->groupBy('group')->map(fn ($group) => $group->values());

        return $this->sendResponse([
            'all'     => $permissions,
            'grouped' => $grouped,
        ], 'Permissions retrieved successfully.');
    }
}
