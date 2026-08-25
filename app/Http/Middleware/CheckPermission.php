<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission middleware
 *
 * Usage in routes:
 *   ->middleware('permission:users.view')
 *   ->middleware('permission:users.edit,users.view')  // requires ANY of them
 *
 * Super Admin always passes.
 * Non-admin users always receive 403.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Admin access required.',
            ], 403);
        }

        // Super Admin bypasses all permission checks.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Check that the user holds at least one of the required permissions.
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden. You do not have the required permission: ' . implode(' or ', $permissions),
        ], 403);
    }
}
