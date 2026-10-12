<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AdminSetting;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends BaseApiController
{
    public function __construct(protected AuthService $authService)
    {
    }

    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // Check if registration is open
        $registrationOpen = AdminSetting::getValue('registration_open', 'true', 'boolean');
        if (!$registrationOpen) {
            return $this->sendError('Registration is currently closed. Please try again later.', [], 403);
        }

        $result = $this->authService->register($request->validated());

        return $this->sendResponse([
            'user'  => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'User registered successfully.', 201);
    }

    /**
     * Authenticate user and issue API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->input('email'),
            $request->input('password')
        );

        $user = $result['user'];
        $userData = (new UserResource($user))->resolve($request);

        // Ensure roles and permissions are attached to the login response for immediate sidebar rendering
        $userData['roles'] = method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [$user->role];
        $userData['permissions'] = method_exists($user, 'getAllPermissions') 
            ? $user->getAllPermissions()->pluck('name')->values() 
            : [];

        return $this->sendResponse([
            'user'  => $userData,
            'token' => $result['token'],
        ], 'User authenticated successfully.');
    }

    /**
     * Get currently authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $relations = ['freelancerProfile', 'employerProfile'];
        $user->load($relations);

        // Convert user resource and inject flat Spatie permission array for frontend sidebar routing
        $userData = (new UserResource($user))->resolve($request);

        // Explicitly attach flat roles and permission string arrays
        $userData['roles'] = method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [$user->role];
        $userData['permissions'] = method_exists($user, 'getAllPermissions') 
            ? $user->getAllPermissions()->pluck('name')->values() 
            : [];

        return $this->sendResponse(
            $userData,
            'User profile retrieved successfully.'
        );
    }

    /**
     * Logout and revoke API token.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->sendResponse(null, 'User logged out successfully.');
    }
}