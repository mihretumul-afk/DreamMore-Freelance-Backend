<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Credential;
use App\Models\FreelancerProfile;
use App\Models\Verification;
use App\Services\AuditService;
use App\Services\NotificationService;
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
                $query     = Verification::with(['user.freelancerProfile', 'user.verifications', 'user.credentials'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at');
                $paginator = $query->paginate($perPage);
                $items     = $paginator->getCollection()->map(function ($v) {
                    $v->item_type = 'identity';
                    return $v;
                });
            } else {
                $query = Credential::with(['user.freelancerProfile', 'user.verifications', 'user.credentials', 'reviewer', 'skillTestAttempts.skillTest'])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->orderByDesc('created_at');
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

        // 'all' — merge both types and deduplicate combined submissions for the same user.
        $verifications = Verification::with(['user.freelancerProfile', 'user.verifications', 'user.credentials'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(fn ($v) => (clone $v)->setAttribute('item_type', 'identity'));

        $credentials = Credential::with(['user.freelancerProfile', 'user.verifications', 'user.credentials', 'reviewer', 'skillTestAttempts.skillTest'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($perPage)
            ->get()
            ->map(fn ($c) => (clone $c)->setAttribute('item_type', 'credential'));

        $credentialUserIds = $credentials->pluck('user_id')->unique()->toArray();
        $verificationsFiltered = $verifications->reject(function ($v) use ($credentialUserIds) {
            return in_array($v->user_id, $credentialUserIds, true);
        });

        $items = $verificationsFiltered->concat($credentials)
            ->sortByDesc('created_at')
            ->values();

        $total = $items->count();

        $paginated = $items->slice(($request->input('page', 1) - 1) * $perPage, $perPage)->values();

        return $this->sendResponse(
            $paginated,
            'Verifications retrieved successfully.',
            200,
            [
                'current_page' => (int) $request->input('page', 1),
                'last_page'    => (int) ceil(max($total, 1) / $perPage),
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

    public function downloadCredential(Credential $credential)
    {
        $credential->load('user');
        $filePath = $credential->file_path;
        $originalName = $credential->file_original_name ?? basename($filePath ?: 'certificate.pdf');

        if (!empty($filePath)) {
            $candidates = [
                Storage::disk('private')->path($filePath),
                Storage::disk('local')->path($filePath),
                Storage::disk('public')->path($filePath),
                storage_path('app/private/' . $filePath),
                storage_path('app/' . $filePath),
                storage_path('app/public/' . $filePath),
                public_path('storage/' . $filePath),
            ];

            foreach ($candidates as $candidate) {
                if (file_exists($candidate) && is_file($candidate)) {
                    return response()->download($candidate, $originalName);
                }
            }
        }

        // Generate official PDF certificate for digital / DreamMore certificates or missing uploads
        $userName = $credential->user?->name ?? 'Freelancer';
        $title = $credential->title ?? 'Professional Certificate';
        $certId = $credential->certificate_identifier ?? ('DM-' . $credential->id);
        $issueDate = $credential->issue_date ? (is_string($credential->issue_date) ? $credential->issue_date : $credential->issue_date->format('Y-m-d')) : date('Y-m-d');

        $pdfContent = $this->generateCertificatePdf($title, $userName, $certId, $issueDate);
        $downloadFilename = str_replace(' ', '_', $title) . '_Certificate.pdf';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $downloadFilename . '"',
        ]);
    }

    private function generateCertificatePdf(string $title, string $userName, string $certId, string $issueDate): string
    {
        $titleEsc = addslashes($title);
        $userEsc = addslashes($userName);
        $certIdEsc = addslashes($certId);
        $dateEsc = addslashes($issueDate);

        $text = "BT /F1 22 Tf 80 700 Td (DREAMMORE OFFICIAL CERTIFICATE) Tj ET\n" .
            "BT /F1 13 Tf 80 660 Td (Verified Professional Credential) Tj ET\n" .
            "BT /F1 11 Tf 80 610 Td (This is to certify that) Tj ET\n" .
            "BT /F1 18 Tf 80 570 Td ({$userEsc}) Tj ET\n" .
            "BT /F1 11 Tf 80 530 Td (Has completed and verified:) Tj ET\n" .
            "BT /F1 14 Tf 80 500 Td ({$titleEsc}) Tj ET\n" .
            "BT /F1 11 Tf 80 450 Td (Certificate ID: {$certIdEsc}) Tj ET\n" .
            "BT /F1 11 Tf 80 425 Td (Issue Date: {$dateEsc}) Tj ET\n" .
            "BT /F1 10 Tf 80 380 Td (Status: Verified & Approved on DreamMore Platform) Tj ET\n";

        $streamLen = strlen($text);
        return "%PDF-1.4\n" .
            "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n" .
            "2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n" .
            "3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>> endobj\n" .
            "4 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj\n" .
            "5 0 obj <</Length {$streamLen}>> stream\n{$text}\nendstream endobj\n" .
            "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000244 00000 n \n0000000313 00000 n \n" .
            "trailer <</Size 6 /Root 1 0 R>>\nstartxref 450\n%%EOF";
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

        // Auto-approve associated pending credentials for this user so admin only approves once
        Credential::where('user_id', $verification->user_id)
            ->where('status', 'pending')
            ->update([
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

        // Auto-promote freelancer profile to approved so they appear on the marketplace
        $this->maybePromoteFreelancerProfile($verification->user_id);

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

        // Auto-reject associated pending credentials for this user
        Credential::where('user_id', $verification->user_id)
            ->where('status', 'pending')
            ->update([
                'status'           => 'rejected',
                'rejection_reason' => $request->input('reason'),
                'reviewed_by'      => $admin?->id,
                'reviewed_at'      => now(),
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

        // Auto-approve associated pending identity verifications for this user so admin only approves once
        Verification::where('user_id', $credential->user_id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'approved',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

        if ($credential->user && !$credential->user->email_verified_at) {
            $credential->user->update(['email_verified_at' => now()]);
        }

        NotificationService::credentialApproved(
            $credential->user_id,
            $credential->title
        );

        // Auto-promote freelancer profile to approved so they appear on the marketplace
        $this->maybePromoteFreelancerProfile($credential->user_id);

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

        // Auto-reject associated pending identity verifications for this user
        Verification::where('user_id', $credential->user_id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'rejected',
                'reason'      => $request->input('reason'),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

        NotificationService::credentialRejected(
            $credential->user_id,
            $credential->title,
            $request->input('reason')
        );

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

    public function requestResubmissionCredential(Request $request, Credential $credential): JsonResponse
    {
        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be requested for resubmission.', [], 422);
        }

        $request->validate(['reason' => 'required|string|max:1000']);

        $admin = $request->user();

        $credential->update([
            'status'           => 'resubmission_required',
            'rejection_reason' => $request->input('reason'),
            'reviewed_by'      => $admin->id,
            'reviewed_at'      => now(),
        ]);

        // Auto-update associated pending identity verifications for this user
        Verification::where('user_id', $credential->user_id)
            ->where('status', 'pending')
            ->update([
                'status'      => 'resubmission_required',
                'reason'      => $request->input('reason'),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

        NotificationService::create(
            $credential->user_id,
            'credential_resubmission_required',
            'Credential Resubmission Required',
            "Resubmission required for credential '{$credential->title}'. Reason: {$request->input('reason')}",
            '/freelancer/credentials'
        );

        // Audit log.
        AuditService::credentialResubmissionRequested($credential->id, $admin->id, [
            'user_id'          => $credential->user_id,
            'user_name'        => $credential->user?->name,
            'credential_title' => $credential->title,
            'reason'           => $request->input('reason'),
        ]);

        return $this->sendResponse(
            $credential->fresh()->load(['user', 'reviewer']),
            'Resubmission requested successfully.'
        );
    }

    /**
     * If the freelancer has at least one approved credential or verification,
     * promote their profile from 'pending' to 'approved' so they appear
     * on the public marketplace.
     */
    private function maybePromoteFreelancerProfile(int $userId): void
    {
        $profile = FreelancerProfile::where('user_id', $userId)->first();

        if (! $profile || $profile->approval_status === 'approved') {
            return;
        }

        $user = $profile->user;

        if ($user && $user->hasApprovedCredentials()) {
            $profile->update([
                'approval_status' => 'approved',
                'approved_at'     => now(),
                'rejection_reason' => null,
            ]);

            // Ensure the user account is active
            if ($user->status !== 'active') {
                $user->update(['status' => 'active']);
            }

            NotificationService::freelancerApproved($userId);
        }
    }
}
