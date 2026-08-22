<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Credential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
     * External credentials go through admin review.
     * Dream More certificates submitted by freelancers are stored as pending
     * and must be verified through the trusted LMS webhook — NOT auto-approved.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can create credentials.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:dream_more_certificate,external_certificate,training_certificate,professional_qualification,other',
            'issuing_organization' => 'nullable|string|max:255',
            'certificate_identifier' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:issue_date',
            'skill_id' => 'nullable|integer|exists:skills,id',
            'document' => [
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
            ],
        ]);

        $file = $request->file('document');
        $fileName = $user->id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->getClientOriginalExtension();
        $safeName = 'credentials/' . $fileName;
        $file->storeAs('credentials', $fileName, 'private');

        // Determine test requirements and source
        $isExternal = in_array($validated['type'], ['external_certificate', 'training_certificate', 'professional_qualification', 'other'], true);

        // External credentials require admin review and may need a skill test
        $testRequired = false;
        $testStatus = 'not_required';

        if ($isExternal) {
            // Check if a skill test exists for this credential's associated skill
            if (!empty($validated['skill_id'])) {
                $testExists = \App\Models\SkillTest::where('skill_id', $validated['skill_id'])
                    ->where('is_active', true)
                    ->exists();
                if ($testExists) {
                    $testRequired = true;
                    $testStatus = 'pending';
                }
            }
        }

        $credential = Credential::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'type' => $validated['type'],
            'issuing_organization' => $validated['issuing_organization'] ?? null,
            'certificate_identifier' => $validated['certificate_identifier'] ?? null,
            'description' => $validated['description'] ?? null,
            'issue_date' => $validated['issue_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'file_path' => $safeName,
            'file_original_name' => $file->getClientOriginalName(),
            'status' => 'pending',
            'verification_source' => $isExternal ? 'external_manual' : null,
            'test_required' => $testRequired,
            'test_status' => $testStatus,
        ]);

        // Notify freelancer if test is required
        if ($testRequired) {
            \App\Models\Notification::create([
                'user_id' => $user->id,
                'type' => 'skill_test_required',
                'title' => 'Skill Assessment Required',
                'message' => "A skill assessment is required for your credential '{$credential->title}' before it can be verified.",
                'link' => '/freelancer/credentials',
            ]);
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
     * Update a credential (owner only, must be pending).
     */
    public function update(Request $request, Credential $credential): JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be updated.', [], 422);
        }

        $validated = $request->validate([
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
                    if (!in_array($value->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                        $fail('The document must be a JPG, PNG, WEBP, or PDF file.');
                    }
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                        $fail('The document file extension must be jpg, jpeg, png, webp, or pdf.');
                    }
                },
            ],
        ]);

        $updateData = array_filter($validated, fn ($v) => $v !== null && $v !== '' && $v !== 'document');

        // Handle optional new document upload
        if ($request->hasFile('document')) {
            // Remove old file
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

        $credential->update($updateData);

        return $this->sendResponse($credential->fresh(), 'Credential updated successfully.');
    }

    /**
     * Delete a credential (owner only, must be pending).
     */
    public function destroy(Request $request, Credential $credential): JsonResponse
    {
        $user = $request->user();

        if ($credential->user_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this credential.');
        }

        if ($credential->status !== 'pending') {
            return $this->sendError('Only pending credentials can be deleted.', [], 422);
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
