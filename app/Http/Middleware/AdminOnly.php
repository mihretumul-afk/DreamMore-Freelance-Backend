<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Directly checks the database role column — no complex permission tables needed!
        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Forbidden. Requires admin privileges.'
            ], 403);
        }

        return $next($request);
    }
}