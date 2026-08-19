<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Verification::with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $verifications = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            $verifications,
            'Verifications retrieved successfully.',
            200,
            [
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'per_page' => $verifications->perPage(),
                'total' => $verifications->total(),
            ]
        );
    }

    public function show(Verification $verification): JsonResponse
    {
        $verification->load('user');

        return $this->sendResponse($verification, 'Verification retrieved successfully.');
    }

    public function approve(Verification $verification): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return $this->sendError('Only pending verifications can be approved.', [], 422);
        }

        $verification->update([
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        // Mark the user's email as verified if not already.
        if ($verification->user && !$verification->user->email_verified_at) {
            $verification->user->update(['email_verified_at' => now()]);
        }

        return $this->sendResponse($verification->fresh()->load('user'), 'Verification approved successfully.');
    }

    public function reject(Request $request, Verification $verification): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return $this->sendError('Only pending verifications can be rejected.', [], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $verification->update([
            'status' => 'rejected',
            'reason' => $request->input('reason'),
            'reviewed_at' => now(),
        ]);

        return $this->sendResponse($verification->fresh()->load('user'), 'Verification rejected successfully.');
    }
}
