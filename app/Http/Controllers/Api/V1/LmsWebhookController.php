<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\LmsVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming webhooks from the Dream More LMS.
 *
 * Security:
 * - HMAC signature verification using shared secret
 * - Only accepts POST requests
 * - Validates required fields
 * - Prevents replay attacks via certificate_id dedup
 *
 * This endpoint is NOT public — it should be protected by:
 * - LMS_SECRET_KEY HMAC signature in X-LMS-Signature header
 * - Rate limiting (recommended: throttle:api)
 */
class LmsWebhookController extends BaseApiController
{
    /**
     * Handle LMS certificate completion webhook.
     *
     * Headers:
     *   X-LMS-Signature: HMAC-SHA256 signature of the raw request body
     *
     * Body:
     *   user_email: string (required)
     *   certificate_id: string (required)
     *   course_id: string (required)
     *   course_name: string (required)
     *   skill_name: string (required)
     *   skill_id: int|null
     *   completion_date: string (required, Y-m-d)
     *   issue_date: string|null
     */
    public function certificateCompleted(Request $request): JsonResponse
    {
        // 1. Verify LMS signature
        $signature = $request->header('X-LMS-Signature', '');

        if (!$signature) {
            return $this->sendError('Missing LMS signature.', [], 401);
        }

        $payload = $request->getContent();

        if (!LmsVerificationService::verifyLmsSignature($payload, $signature)) {
            Log::warning('LMS webhook: Invalid signature received.');
            return $this->sendError('Invalid LMS signature.', [], 401);
        }

        // 2. Validate required fields
        $validated = $request->validate([
            'user_email' => 'required|email|max:255',
            'certificate_id' => 'required|string|max:255',
            'course_id' => 'required|string|max:255',
            'course_name' => 'required|string|max:255',
            'skill_name' => 'required|string|max:255',
            'skill_id' => 'nullable|integer|exists:skills,id',
            'completion_date' => 'required|date',
            'issue_date' => 'nullable|date',
        ]);

        // 3. Process certificate
        try {
            $credential = LmsVerificationService::processCertificate($validated);

            return $this->sendResponse([
                'credential_id' => $credential->id,
                'status' => $credential->status,
                'auto_verified' => $credential->auto_verified,
                'user_id' => $credential->user_id,
            ], 'LMS certificate processed successfully.', 200);
        } catch (\InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (\Exception $e) {
            Log::error('LMS webhook: Processing error', [
                'error' => $e->getMessage(),
                'certificate_id' => $validated['certificate_id'],
            ]);
            return $this->sendError('Internal error processing LMS certificate.', [], 500);
        }
    }

    /**
     * Handle LMS certificate revocation webhook.
     */
    public function certificateRevoked(Request $request): JsonResponse
    {
        // Verify signature
        $signature = $request->header('X-LMS-Signature', '');
        if (!$signature) {
            return $this->sendError('Missing LMS signature.', [], 401);
        }

        $payload = $request->getContent();
        if (!LmsVerificationService::verifyLmsSignature($payload, $signature)) {
            Log::warning('LMS webhook: Invalid signature on revocation.');
            return $this->sendError('Invalid LMS signature.', [], 401);
        }

        $validated = $request->validate([
            'certificate_id' => 'required|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $revoked = LmsVerificationService::revokeCertificate(
            $validated['certificate_id'],
            $validated['reason'] ?? null
        );

        if (!$revoked) {
            return $this->sendError('Certificate not found.', [], 404);
        }

        return $this->sendResponse(null, 'Certificate revoked successfully.');
    }
}
