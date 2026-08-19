<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            UserResource::collection($users),
            'Users retrieved successfully.',
            200,
            [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->load('freelancerProfile', 'employerProfile');

        return $this->sendResponse(
            new UserResource($user),
            'User retrieved successfully.'
        );
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        if ($request->user()->id === $user->id && !$request->boolean('is_active')) {
            return $this->sendError('You cannot deactivate your own admin account.', [], 422);
        }

        $user->update([
            'status' => $request->boolean('is_active') ? 'active' : 'suspended',
        ]);

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

        $oldRole = $user->role;
        $newRole = $request->input('role');

        if ($request->user()->id === $user->id && $newRole !== 'admin') {
            return $this->sendError('You cannot remove your own admin role.', [], 422);
        }

        $user->update(['role' => $newRole]);

        // Ensure the user has the appropriate profile for their role.
        if ($newRole === 'freelancer' && !$user->freelancerProfile) {
            $user->freelancerProfile()->create([]);
        } elseif ($newRole === 'employer' && !$user->employerProfile) {
            $user->employerProfile()->create([]);
        }

        return $this->sendResponse(
            new UserResource($user->fresh()->load('freelancerProfile', 'employerProfile')),
            "User role changed from '{$oldRole}' to '{$newRole}'."
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->sendError('You cannot delete your own admin account.', [], 422);
        }

        $user->delete();

        return $this->sendResponse(null, 'User deleted successfully.');
    }
}
