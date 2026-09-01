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
            'issuing_organization' => $isDreamMore ? 'nullable|string|max:255' : 'required|string|max:255',
            'certificate_identifier' => $isDreamMore ? 'required|string|max:255' : 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issue_date',
            'skill_id' => 'nullable|integer|exists:skills,id',
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
            // Check if matching LMS certificate already exists in database
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

        if ($testRequired) {
            NotificationService::skillTestRequired($user->id, $credential->title, 'Skill Assessment');
        }

        // Notify all admins about new credential submission (unless auto-verified)
        if ($status !== 'approved') {
            NotificationService::notifyAdmins(
                'credential_submitted',
                'New Credential Submitted',
                "{$user->name} submitted credential '{$credential->title}' ({$credential->type}) for review.",
                '/admin/verifications?type=credential&status=pending'
            );

            // Broadcast real-time event to admin dashboard
            broadcast(new CredentialSubmitted($credential, $user->name));
        }

        return $this->sendResponse($credential, 'Credential submitted successfully for verification.', 201);
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
     * Download a credential file (owner or admin only).
     */
    public function download(Request $request, Credential $credential): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this credential.');
        }

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
}
