<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('freelancerProfile', 'employerProfile');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $users = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            UserResource::collection($users)->resolve($request),
            'Users retrieved successfully.',
            200,
            [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ]
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->load('freelancerProfile', 'employerProfile');

        return $this->sendResponse(new UserResource($user), 'User retrieved successfully.');
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $actor = $request->user();

        if ($actor->id === $user->id && !$request->boolean('is_active')) {
            return $this->sendError('You cannot deactivate your own admin account.', [], 422);
        }

        $oldStatus = $user->status;
        $activate  = $request->boolean('is_active');
        $newStatus = $activate ? 'active' : 'suspended';

        DB::transaction(function () use ($user, $newStatus, $activate, $actor, $oldStatus) {
            $user->update(['status' => $newStatus]);

            // Audit log — use the appropriate wrapper based on direction.
            $context = [
                'name'       => $user->name,
                'email'      => $user->email,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ];

            if ($activate) {
                AuditService::userActivated($user->id, $actor->id, $context);
            } else {
                AuditService::userSuspended($user->id, $actor->id, $context);
            }
        });

        return $this->sendResponse(
            new UserResource($user->fresh()),
            'User status updated successfully.'
        );
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'role' => 'required|in:freelancer,employer,admin',
        ]);

        $actor   = $request->user();
        $oldRole = $user->role;
        $newRole = $request->input('role');

        if ($actor->id === $user->id && $newRole !== 'admin') {
            return $this->sendError('You cannot remove your own admin role.', [], 422);
        }

        DB::transaction(function () use ($user, $newRole, $oldRole, $actor) {
            $user->update(['role' => $newRole]);

            if ($newRole === 'freelancer' && !$user->freelancerProfile) {
                $user->freelancerProfile()->create([]);
            } elseif ($newRole === 'employer' && !$user->employerProfile) {
                $user->employerProfile()->create([]);
            }

            AuditService::userRoleChanged($user->id, $actor->id, [
                'name'     => $user->name,
                'email'    => $user->email,
                'old_role' => $oldRole,
                'new_role' => $newRole,
            ]);
        });

        return $this->sendResponse(
            new UserResource($user->fresh()->load('freelancerProfile', 'employerProfile')),
            "User role changed from '{$oldRole}' to '{$newRole}'."
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return $this->sendError('You cannot delete your own admin account.', [], 422);
        }

        DB::transaction(function () use ($user, $actor) {
            AuditService::userDeleted($user->id, $actor->id, [
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]);

            $user->tokens()->delete();
            $user->delete();
        });

        return $this->sendResponse(null, 'User deleted successfully.');
    }
}
