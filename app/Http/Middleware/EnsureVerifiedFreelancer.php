<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedFreelancer
{
    /**
     * Handle an incoming request.
     * Freelancers cannot apply for or bid on jobs until their credentials/verifications
     * have been approved by an administrator.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Admins are exempt and can manage
        if ($user->role === 'admin') {
            return $next($request);
        }

        if ($user->role !== 'freelancer') {
            return response()->json([
                'success' => false,
                'message' => 'Only freelancers can submit proposals.',
            ], 403);
        }

        if (!$user->hasApprovedCredentials()) {
            return response()->json([
                'success' => false,
                'message' => 'Your credentials must be approved by an administrator before you can apply for jobs.',
            ], 403);
        }

        return $next($request);
    }
}
