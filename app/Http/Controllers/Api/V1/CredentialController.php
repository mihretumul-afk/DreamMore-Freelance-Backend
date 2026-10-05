<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CredentialSubmitted;
use App\Models\Credential;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CredentialController extends BaseApiController
{
    /**
     * Allowed MIME types for credential uploads.
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Maximum file size in kilobytes (10MB).
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * List the authenticated freelancer's credentials.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can manage credentials.');
        }

        $query = Credential::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $credentials = $query->paginate(15);

        return $this->sendResponse(
            $credentials->items(),
            'Credentials retrieved successfully.',
            200,
            [
                'current_page' => $credentials->currentPage(),
                'last_page' => $credentials->lastPage(),
                'per_page' => $credentials->perPage(),
                'total' => $credentials->total(),
            ]
        );
    }

    /**
     * Store a new credential.
     *
     * Supports DreamMore Certificates (Certificate ID required) and
     * External Certificates (Title & Document required).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can create credentials.');
        }

        $type = $request->input('type', 'external_certificate');
        $isDreamMore = $type === 'dream_more_certificate';

        $rules = [
            'title' => 'required|string|max:255',
            'type' => 'required|in:dream_more_certificate,external_certificate,training_certificate,professional_qualification,other',
            'issuing_organization' => 'nullable|string|max:255',
            'certificate_identifier' => $isDreamMore ? 'required|string|max:255' : 'nullable|string|max:255',
            'certificate_identifier' => $isDreamMore ? 'required|string|max:255' : 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issue_date',
            'skill_id' => 'nullable|integer|exists:skills,id',
            'verification_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
            'verification_notes' => 'nullable|string|max:2000',
        ];

        // Document required for external certificate; optional for DreamMore certificate
        if ($isDreamMore) {
            $rules['document'] = [
                'nullable',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                function ($attribute, $value, $fail) {
                    if ($value && !in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                        $fail('The document must be a JPG, PNG, WEBP, or PDF file.');
                    }
                    if ($value) {
                        $extension = strtolower($value->getClientOriginalExtension());
                        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                            $fail('The document file extension must be jpg, jpeg, png, webp, or pdf.');
                        }
                    }
                },
            ];
        } else {
            $rules['document'] = [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                function ($attribute, $value, $fail) {
                    if (!in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                        $fail('The document must be a JPG, PNG, WEBP, or PDF file.');
                    }
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                        $fail('The document file extension must be jpg, jpeg, png, webp, or pdf.');
                    }
                },
            ];
        }

        $validated = $request->validate($rules);

        // Handle optional combined identity verification document
        $hasVerificationDoc = false;
        if ($request->hasFile('verification_document')) {
            $verifFile = $request->file('verification_document');
            $verifFileName = $user->id . '_verif_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $verifFile->getClientOriginalExtension();
            $verifFile->storeAs('verifications', $verifFileName, 'public');
            $verifDocUrl = Storage::disk('public')->url('verifications/' . $verifFileName);

            \App\Models\Verification::create([
                'user_id' => $user->id,
                'type' => 'identity',
                'document_url' => $verifDocUrl,
                'notes' => $request->input('verification_notes'),
                'status' => 'pending',
            ]);
            $hasVerificationDoc = true;
        }

        // Handle file storage
        $safeName = null;
        $originalName = null;

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $fileName = $user->id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->getClientOriginalExtension();
            $safeName = 'credentials/' . $fileName;
            $file->storeAs('credentials', $fileName, 'private');
            $originalName = $file->getClientOriginalName();
        } else {
            $safeName = 'dream_more_certificates/id_' . Str::slug($validated['certificate_identifier'] ?? 'DM-' . time());
            $originalName = 'DreamMore_Certificate_' . ($validated['certificate_identifier'] ?? 'ID') . '.pdf';
        }

        // DreamMore certificate validation against existing LMS records
        $verificationSource = $isDreamMore ? 'dream_more_lms' : 'external_manual';
        $status = 'pending';
        $autoVerified = false;

        if ($isDreamMore) {
            // Check if this user already submitted a credential with this certificate ID
            $existingForUser = Credential::where('user_id', $user->id)
                ->where('lms_certificate_id', $validated['certificate_identifier'])
                ->orWhere(function ($q) use ($user, $validated) {
                    $q->where('user_id', $user->id)
                      ->where('certificate_identifier', $validated['certificate_identifier']);
                })
                ->first();

            if ($existingForUser) {
                return $this->sendError(
                    'You have already submitted a credential with this certificate ID. You cannot submit duplicates.',
                    [],
                    422
                );
            }

            // Check if matching LMS certificate already exists in database (from other users)
            $lmsMatch = Credential::where('lms_certificate_id', $validated['certificate_identifier'])
                ->orWhere('certificate_identifier', $validated['certificate_identifier'])
                ->first();

            if ($lmsMatch && $lmsMatch->auto_verified) {
                $status = 'approved';
                $autoVerified = true;
            }
        }

        // External credentials test requirements check
        $testRequired = false;
        $testStatus = 'not_required';

        if (!$isDreamMore && !empty($validated['skill_id'])) {
            $testExists = \App\Models\SkillTest::where('skill_id', $validated['skill_id'])
                ->where('is_active', true)
                ->exists();
            if ($testExists) {
                $testRequired = true;
                $testStatus = 'pending';
            }
        }

        try {
            $credential = Credential::create([
                'user_id' => $user->id,
                'title' => $validated['title'],
                'type' => $validated['type'],
                'issuing_organization' => $isDreamMore ? 'DreamMore' : ($validated['issuing_organization'] ?? null),
                'certificate_identifier' => $validated['certificate_identifier'] ?? null,
                'description' => $validated['description'] ?? null,
                'issue_date' => $validated['issue_date'] ?? null,
                'expiry_date' => $validated['expiry_date'] ?? null,
                'file_path' => $safeName,
                'file_original_name' => $originalName,
                'status' => $status,
                'verification_source' => $verificationSource,
                'lms_certificate_id' => $isDreamMore ? ($validated['certificate_identifier'] ?? null) : null,
                'auto_verified' => $autoVerified,
                'test_required' => $testRequired,
                'test_status' => $testStatus,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Catch duplicate entry constraint violations gracefully
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->sendError(
                    'You have already submitted a credential with this certificate ID. You cannot submit duplicates.',
                    [],
                    422
                );
            }
            throw $e;
        }

        if ($testRequired) {
            NotificationService::skillTestRequired($user->id, $credential->title, 'Skill Assessment');
        }

        // Notify admins about new credential submission (single merged notification if identity doc attached)
        if ($status !== 'approved') {
            $notificationTitle = $hasVerificationDoc
                ? 'New Credential & Identity Document Submitted'
                : 'New Credential Submitted';

            $notificationMessage = $hasVerificationDoc
                ? "{$user->name} submitted credential '{$credential->title}' and identity verification document for review."
                : "{$user->name} submitted credential '{$credential->title}' ({$credential->type}) for review.";

            NotificationService::notifyAdmins(
                'users.verify',
                'credential_submitted',
                $notificationTitle,
                $notificationMessage,
                '/admin/verifications?status=pending'
            );

            // Broadcast real-time event to admin dashboard
            broadcast(new CredentialSubmitted($credential, $user->name));
        }

        return $this->sendResponse($credential, 'Credential submitted successfully.', 201);
    }

    /**
     * Show a single credential (owner or admin only).
     */
    public function show(Request $request, Credential $credential): JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        return $this->sendResponse($credential, 'Credential retrieved successfully.');
    }

    /**
     * Update a credential (owner only, must be pending, rejected, or resubmission_required).
     */
    public function update(Request $request, Credential $credential): JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        if (!in_array($credential->status, ['pending', 'rejected', 'resubmission_required'], true)) {
            return $this->sendError('Only credentials in pending, rejected, or resubmission state can be updated.', [], 422);
        }

        $type = $request->input('type', $credential->type);
        $isDreamMore = $type === 'dream_more_certificate';

        $rules = [
            'title' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:dream_more_certificate,external_certificate,training_certificate,professional_qualification,other',
            'issuing_organization' => 'nullable|string|max:255',
            'certificate_identifier' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issue_date',
            'document' => [
                'nullable',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                function ($attribute, $value, $fail) {
                    if ($value && !in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                        $fail('The document must be a JPG, PNG, WEBP, or PDF file.');
                    }
                    if ($value) {
                        $extension = strtolower($value->getClientOriginalExtension());
                        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                            $fail('The document file extension must be jpg, jpeg, png, webp, or pdf.');
                        }
                    }
                },
            ],
        ];

        $validated = $request->validate($rules);
        $updateData = array_filter($validated, fn ($v) => $v !== null && $v !== '' && $v !== 'document');

        if ($isDreamMore) {
            $updateData['issuing_organization'] = 'DreamMore';
            $updateData['lms_certificate_id'] = $validated['certificate_identifier'] ?? $credential->certificate_identifier;
        }

        // Handle optional new document upload
        if ($request->hasFile('document')) {
            if ($credential->file_path && Storage::disk('private')->exists($credential->file_path)) {
                Storage::disk('private')->delete($credential->file_path);
            }

            $file = $request->file('document');
            $fileName = $user->id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->getClientOriginalExtension();
            $safeName = 'credentials/' . $fileName;
            $file->storeAs('credentials', $fileName, 'private');

            $updateData['file_path'] = $safeName;
            $updateData['file_original_name'] = $file->getClientOriginalName();
        }

        // When resubmitting a rejected or resubmission_required credential, reset to pending
        $updateData['status'] = 'pending';
        $updateData['rejection_reason'] = null;
        $updateData['reviewed_by'] = null;
        $updateData['reviewed_at'] = null;

        $credential->update($updateData);

        // Notify admins about credential resubmission
        NotificationService::notifyAdmins(
            'users.verify',
            'credential_resubmitted',
            'Credential Resubmitted',
            "{$user->name} resubmitted credential '{$credential->title}' for review after previous {$credential->status} status.",
            '/admin/verifications?type=credential&status=pending'
        );

        return $this->sendResponse($credential->fresh(), 'Credential resubmitted for verification successfully.');
    }

    /**
     * Delete a credential (owner only, must be pending, rejected, or resubmission_required).
     */
    public function destroy(Request $request, Credential $credential): JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        if (!in_array($credential->status, ['pending', 'rejected', 'resubmission_required'], true)) {
            return $this->sendError('Only pending, rejected, or resubmission credentials can be deleted.', [], 422);
        }

        // Remove file
        if ($credential->file_path && Storage::disk('private')->exists($credential->file_path)) {
            Storage::disk('private')->delete($credential->file_path);
        }

        $credential->delete();

        return $this->sendResponse(null, 'Credential deleted successfully.');
    }

    /**
     * Get verified credentials for a freelancer's public profile.
     * Only shows approved credentials with metadata — no file paths.
     * Includes trust level information for Dream More vs External distinction.
     */
    public function publicCredentials(string $userId): JsonResponse
    {
        $credentials = Credential::where('user_id', $userId)
            ->where('status', 'approved')
            ->select(
                'id', 'title', 'type', 'issuing_organization', 'certificate_identifier',
                'description', 'issue_date', 'expiry_date',
                'verification_source', 'lms_course_name', 'auto_verified'
            )
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($credentials, 'Verified credentials retrieved successfully.');
    }

    /**
    /**
     * Download a credential file (owner or admin only).
     */
    public function download(Request $request, Credential $credential)
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        $credential->load('user');
        return $this->respondWithCredentialFile($credential);
    }

    /**
     * Resolve file on disk or return generated digital certificate PDF.
     */
    private function respondWithCredentialFile(Credential $credential)
    {
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

    /**
     * Minimalist valid PDF 1.4 generator in pure PHP.
     */
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
        $pdf = "%PDF-1.4\n" .
            "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n" .
            "2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n" .
            "3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>> endobj\n" .
            "4 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj\n" .
            "5 0 obj <</Length {$streamLen}>> stream\n{$text}\nendstream endobj\n" .
            "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000244 00000 n \n0000000313 00000 n \n" .
            "trailer <</Size 6 /Root 1 0 R>>\nstartxref 450\n%%EOF";

        return $pdf;
    }
}
