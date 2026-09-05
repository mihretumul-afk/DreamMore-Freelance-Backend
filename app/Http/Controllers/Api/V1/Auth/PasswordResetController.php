<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends BaseApiController
{
    /**
     * Send password reset link email.
     * Endpoint: POST /api/v1/auth/forgot-password or POST /api/forgot-password
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $credentials = [
            'email' => strtolower(trim($request->input('email'))),
        ];

        $status = Password::sendResetLink($credentials);

        if ($status === Password::RESET_THROTTLED) {
            return $this->sendError(
                'Please wait before requesting another password reset link.',
                [],
                429
            );
        }

        // Return a uniform success response to prevent email enumeration.
        return $this->sendResponse(
            null,
            'Password reset link sent. Please check your email.'
        );
    }

    /**
     * Reset the user's password using the token.
     * Endpoint: POST /api/v1/auth/reset-password or POST /api/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $credentials = [
            'token'                 => $request->input('token'),
            'email'                 => strtolower(trim($request->input('email'))),
            'password'              => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
        ];

        $status = Password::reset($credentials, function ($user, $password) {
            $user->forceFill([
                'password'       => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            // Revoke all existing access tokens to enforce logging in with the new password
            $user->tokens()->delete();
        });

        if ($status === Password::PASSWORD_RESET) {
            return $this->sendResponse(
                null,
                'Password reset successfully.'
            );
        }

        return $this->sendError(
            __($status),
            ['email' => [__($status)]],
            422
        );
    }
}
