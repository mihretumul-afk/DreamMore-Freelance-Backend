<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Credential;
use App\Models\Notification;
use App\Models\Verification;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends BaseApiController
{
    /**
     * List all verifications + credentials.
     * Supports type filter: 'all' (default) | 'identity' | 'credential'.
     */
    public function index(Request $request): JsonResponse
    {
        $status  = $request->input('status');
        $type    = $request->input('type', 'all');
        $perPage = 15;

        if ($type === 'identity' || $type === 'credential') {
            if ($type === 'identity') {
                $query     = Verification::with('user')
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at');
                $paginator = $query->paginate($perPage);
                $items     = $paginator->getCollection()->map(function ($v) {
                    $v->item_type = 'identity';
                    return $v;
                });
            } else {
                $query = Credential::with(['user', 'reviewer'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at')
                    ->with('skillTestAttempts.skillTest');
                $paginator = $query->paginate($perPage);
                $items     = $paginator->getCollection()->map(function ($c) {
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
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                ]
            );
        }

        // 'all' — merge both types.
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
                'last_page'    => (int) ceil($total / $perPage),
                'per_page'     => $perPage,
                'total'        => $total,
            ]
        );
    }

    public function show(Verification $verification): JsonResponse
    {
        $verification->load('user');

        return $this->sendResponse($verification, 'Verification retrieved successfully.');
    }

    public function showCredential(Credential $credential): JsonResponse
    {
        $credential->load(['user', 'reviewer']);

        return $this->sendResponse($credential, 'Credential retrieved successfully.');
    }

    public function downloadCredential(Credential $credential): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk('private')->exists($credential->file_path)
            ? Storage::disk('private')
            : (Storage::disk('local')->exists($credential->file_path)
                ? Storage::disk('local')
                : (Storage::disk('public')->exists($credential->file_path) ? Storage::disk('public') : null));

        if (!$disk) {
            if (Storage::disk('local')->exists('private/' . $credential->file_path)) {
                return Storage::disk('local')->download(
                    'private/' . $credential->file_path,
                    $credential->file_original_name ?? basename($credential->file_path)
                );
            }
            return $this->sendError('Credential file not found.', [], 404);
        }

        return $disk->download(
            $credential->file_path,
            $credential->file_original_name ?? basename($credential->file_path)
        );
    }

    public function approve(Request $request, Verification $verification): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return $this->sendError('Only pending verifications can be approved.', [], 422);
        }

        $admin = $request->user();

        $verification->update([
            'status'      => 'approved',
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now(),
        ]);

        if ($verification->user && !$verification->user->email_verified_at) {
            $verification->user->update(['email_verified_at' => now()]);
        }

        if ($verification->user) {
            \App\Services\NotificationService::verificationApproved(
                $verification->user_id,
                $verification->user->role ?? 'freelancer'
            );
        }

        // Audit log.
        AuditService::verificationApproved($verification->id, $admin->id, [
            'user_id'   => $verification->user_id,
            'user_name' => $verification->user?->name,
            'type'      => $verification->type ?? 'identity',
        ]);

        return $this->sendResponse(
            $verification->fresh()->load(['user', 'reviewer']),
            'Verification approved successfully.'
        );
    }

    public function reject(Request $request, Verification $verification): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return $this->sendError('Only pending verifications can be rejected.', [], 422);
        }

        $request->validate(['reason' => 'nullable|string|max:1000']);

        $admin = $request->user();

        $verification->update([
            'status'      => 'rejected',
            'reason'      => $request->input('reason'),
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now(),
        ]);

        if ($verification->user) {
            \App\Services\NotificationService::verificationRejected(
                $verification->user_id,
                $verification->user->role ?? 'freelancer',
                $request->input('reason')
            );
        }

        // Audit log.
        AuditService::verificationRejected($verification->id, $admin->id, [
            'user_id'   => $verification->user_id,
            'user_name' => $verification->user?->name,
            'reason'    => $request->input('reason'),
            'type'      => $verification->type ?? 'identity',
        ]);

        return $this->sendResponse(
            $verification->fresh()->load(['user', 'reviewer']),
            'Verification rejected successfully.'
        );
    }

    public function approveCredential(Request $request, Credential $credential): JsonResponse
    {
        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be approved.', [], 422);
        }

        if ($credential->auto_verified && $credential->verification_source === 'dream_more_lms') {
            return $this->sendError(
                'Dream More LMS certificates cannot be manually approved. They are auto-verified through the LMS integration.',
                [],
                422
            );
        }

        $admin      = $request->user();
        $updateData = [
            'status'      => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ];

        if ($credential->test_required && $credential->test_status !== 'passed') {
            $updateData['test_status'] = 'passed';
        }

        $credential->update($updateData);

        Notification::create([
            'user_id' => $credential->user_id,
            'type'    => 'credential_approved',
            'title'   => 'Credential Approved',
            'message' => "Your credential '{$credential->title}' has been approved.",
            'link'    => '/freelancer/credentials',
        ]);

        // Audit log.
        AuditService::credentialApproved($credential->id, $admin->id, [
            'user_id'          => $credential->user_id,
            'user_name'        => $credential->user?->name,
            'credential_title' => $credential->title,
        ]);

        return $this->sendResponse(
            $credential->fresh()->load(['user', 'reviewer']),
            'Credential approved successfully.'
        );
    }

    public function rejectCredential(Request $request, Credential $credential): JsonResponse
    {
        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be rejected.', [], 422);
        }

        $request->validate(['reason' => 'nullable|string|max:1000']);

        $admin = $request->user();

        $credential->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'reviewed_by'      => $admin->id,
            'reviewed_at'      => now(),
        ]);

        Notification::create([
            'user_id' => $credential->user_id,
            'type'    => 'credential_rejected',
            'title'   => 'Credential Rejected',
            'message' => "Your credential '{$credential->title}' has been rejected."
                . ($request->input('reason') ? " Reason: {$request->input('reason')}" : ''),
            'link'    => '/freelancer/profile',
        ]);

        // Audit log.
        AuditService::credentialRejected($credential->id, $admin->id, [
            'user_id'          => $credential->user_id,
            'user_name'        => $credential->user?->name,
            'credential_title' => $credential->title,
            'reason'           => $request->input('reason'),
        ]);

        return $this->sendResponse(
            $credential->fresh()->load(['user', 'reviewer']),
            'Credential rejected successfully.'
        );
    }
}
