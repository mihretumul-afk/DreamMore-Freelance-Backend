<?php

namespace App\Http\Middleware;

use App\Models\AdminSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * When maintenance mode is enabled, all public (unauthenticated) requests
     * receive a 503 response. Authenticated admin users bypass this check so
     * they can manage settings and disable maintenance mode.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maintenanceMode = AdminSetting::getValue('maintenance_mode', 'false', 'boolean');

        if ($maintenanceMode) {
            // Allow the platform-settings endpoint (frontend needs it to show maintenance page)
            if ($request->is('api/v1/platform-settings')) {
                return $next($request);
            }

            // Allow the auth endpoints (admin needs to log in)
            if ($request->is('api/v1/auth/*')) {
                return $next($request);
            }

            // Allow health check
            if ($request->is('api/v1/health') || $request->is('api/v1/status')) {
                return $next($request);
            }

            // Resolve the authenticated user via Sanctum token if present
            $user = $request->user();
            if (!$user && $request->bearerToken()) {
                $token = PersonalAccessToken::findToken($request->bearerToken());
                if ($token) {
                    $user = $token->tokenable;
                    if ($user) {
                        Auth::setUser($user);
                    }
                }
            }

            // Allow admin users to bypass maintenance mode
            if ($user && $user->role === 'admin') {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'The platform is currently under maintenance. Please try again later.',
                'maintenance' => true,
            ], 503);
        }

        return $next($request);
    }
}
