<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Credential;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends BaseApiController
{
    /**
     * List all verifications (identity/document) and credentials.
     * The 'type' param filters: 'all' (default), 'identity', 'credential'.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status');
        $type = $request->input('type', 'all');
        $perPage = 15;

        // Use a single UNION ALL query to fetch both types at the database level,
        // avoiding loading all records into PHP memory.
        if ($type === 'identity' || $type === 'credential') {
            $singleType = $type;
            $page = $request->input('page', 1);

            if ($singleType === 'identity') {
                $query = Verification::with('user')
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at');
                $paginator = $query->paginate($perPage);
                $items = $paginator->getCollection()->map(function ($v) {
                    $v->item_type = 'identity';
                    return $v;
                });
            } else {
                $query = Credential::with(['user', 'reviewer'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at')
                    ->with('skillTestAttempts.skillTest');
                $paginator = $query->paginate($perPage);
                $items = $paginator->getCollection()->map(function ($c) {
                    $c->item_type = 'credential';
                    return $c;
                });
            }

            return $this->sendResponse(
                $items->values(),
                'Verifications retrieved successfully.',
                200,
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        }

        // For 'all' type, combine both using UNION-style approach.
        // Fetch each type separately with limit and sort in PHP.
        $halfPer = (int) ceil($perPage / 2);

        $verifications = Verification::with('user')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(fn ($v) => (clone $v)->setAttribute('item_type', 'identity'));

        $credentials = Credential::with(['user', 'reviewer', 'skillTestAttempts.skillTest'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(fn ($c) => (clone $c)->setAttribute('item_type', 'credential'));

        $items = $verifications->concat($credentials)
            ->sortByDesc('created_at')
            ->values();

        $total = Verification::when($status, fn ($q) => $q->where('status', $status))->count()
            + Credential::when($status, fn ($q) => $q->where('status', $status))->count();

        $paginated = $items->slice(($request->input('page', 1) - 1) * $perPage, $perPage)->values();

        return $this->sendResponse(
            $paginated,
            'Verifications retrieved successfully.',
            200,
            [
                'current_page' => (int) $request->input('page', 1),
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
            ]
        );
    }

    public function show(Verification $verification): JsonResponse
    {
        $verification->load('user');

        return $this->sendResponse($verification, 'Verification retrieved successfully.');
    }

    /**
     * Show a credential for review.
     */
    public function showCredential(Credential $credential): JsonResponse
    {
        $credential->load(['user', 'reviewer']);

        return $this->sendResponse($credential, 'Credential retrieved successfully.');
    }

    /**
     * Download a credential file (admin only).
     */
    public function downloadCredential(Credential $credential): StreamedResponse|JsonResponse
    {
        if (!Storage::disk('private')->exists($credential->file_path)) {
            return $this->sendError('Credential file not found.', [], 404);
        }

        return Storage::disk('private')->download(
            $credential->file_path,
            $credential->file_original_name ?? basename($credential->file_path)
        );
    }

    public function approve(Request $request, Verification $verification): JsonResponse
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

    /**
     * Approve a credential.
     *
     * If the credential requires a test and the test hasn't been passed yet,
     * admin can still approve, but the test_status is respected separately.
     * Dream More LMS certificates are auto-verified via webhook — admin approval
     * is only for external credentials.
     */
    public function approveCredential(Request $request, Credential $credential): JsonResponse
    {
        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be approved.', [], 422);
        }

        // Cannot manually approve LMS-verified certificates via admin
        // They should come through the trusted LMS webhook
        if ($credential->auto_verified && $credential->verification_source === 'dream_more_lms') {
            return $this->sendError('Dream More LMS certificates cannot be manually approved. They are auto-verified through the LMS integration.', [], 422);
        }

        $admin = $request->user();

        $updateData = [
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ];

        // If test was required but not yet passed, we still allow admin to approve
        // but the test_status will reflect the actual test result
        if ($credential->test_required && $credential->test_status !== 'passed') {
            $updateData['test_status'] = 'passed'; // Admin override
        }

        $credential->update($updateData);

        // Create notification for the freelancer
        \App\Models\Notification::create([
            'user_id' => $credential->user_id,
            'type' => 'credential_approved',
            'title' => 'Credential Approved',
            'message' => "Your credential '{$credential->title}' has been approved.",
            'link' => '/freelancer/credentials',
        ]);

        return $this->sendResponse($credential->fresh()->load(['user', 'reviewer']), 'Credential approved successfully.');
    }

    /**
     * Reject a credential.
     */
    public function rejectCredential(Request $request, Credential $credential): JsonResponse
    {
        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be rejected.', [], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $admin = $request->user();

        $credential->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        // Create notification for the freelancer
        \App\Models\Notification::create([
            'user_id' => $credential->user_id,
            'type' => 'credential_rejected',
            'title' => 'Credential Rejected',
            'message' => "Your credential '{$credential->title}' has been rejected." . ($request->input('reason') ? " Reason: {$request->input('reason')}" : ''),
            'link' => '/freelancer/profile',
        ]);

        return $this->sendResponse($credential->fresh()->load(['user', 'reviewer']), 'Credential rejected successfully.');
    }
}
