<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Verification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VerificationSubmissionController extends BaseApiController
{
    /**
     * Submit verification documents for review (identity, company registration, certificates, portfolio, etc.).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'type' => 'required|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'document_url' => 'nullable|string|max:500',
        ]);

        $documentUrl = $validated['document_url'] ?? null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $fileName = $user->id . '_verif_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
            $file->storeAs('verifications', $fileName, 'public');
            $documentUrl = Storage::disk('public')->url('verifications/' . $fileName);
        }

        $verification = Verification::create([
            'user_id' => $user->id,
            'type' => $validated['type'],
            'document_url' => $documentUrl,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        // Notify admins of new verification submission
        NotificationService::notifyAdmins(
            'admin_verification_submitted',
            'New Verification Request',
            "User {$user->name} ({$user->role}) submitted {$validated['type']} verification documents for review.",
            '/admin/verifications'
        );

        return $this->sendResponse($verification, 'Verification documents submitted successfully.', 201);
    }

    /**
     * Get the authenticated user's verification status and history.
     */
    public function showMe(Request $request): JsonResponse
    {
        $user = $request->user();

        $verifications = Verification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $latest = $verifications->first();

        return $this->sendResponse([
            'status' => $latest ? $latest->status : 'unverified',
            'latest_verification' => $latest,
            'verifications' => $verifications,
        ], 'Verification status retrieved successfully.');
    }
}
