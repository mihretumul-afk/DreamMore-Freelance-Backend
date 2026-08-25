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

        return $this->sendResponse([
            'user'  => new UserResource($result['user']),
            'token' => $result['token'],
        ], 'User authenticated successfully.');
    }

    /**
     * Get currently authenticated user details.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['freelancerProfile', 'employerProfile']);

        return $this->sendResponse(
            new UserResource($user),
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
