<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Milestone;
use App\Models\MilestoneAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Laravel\Sanctum\PersonalAccessToken;

class FileController extends BaseApiController
{
    /**
     * Authenticate the request manually via Bearer token or query param.
     */
    private function authenticate(Request $request): ?User
    {
        $token = $request->bearerToken() ?? $request->query('token');
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return null;
        }

        return $accessToken->tokenable;
    }

    /**
     * Serve a milestone attachment file.
     * URL: /api/v1/milestone-attachments/{path}
     * The {path} is clean: "{milestoneId}/{filename}"
     */
    public function milestoneAttachment(Request $request, string $path): JsonResponse|BinaryFileResponse
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check access via milestone → contract
        $milestoneId = is_numeric(explode('/', $path)[0] ?? null) ? (int) explode('/', $path)[0] : null;
        if ($milestoneId) {
            $milestone = Milestone::with('contract')->find($milestoneId);
            if ($milestone) {
                $contract = $milestone->contract;
                if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }
        }

        // Build full disk path: storage/app/public/milestone-attachments/{path}
        $fullPath = storage_path('app/public/milestone-attachments/' . $path);
        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $mime = mime_content_type($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Serve a milestone submission file.
     * URL: /api/v1/milestone-submissions/{path}
     * The {path} is clean: "{milestoneId}/{filename}"
     */
    public function milestoneSubmission(Request $request, string $path): JsonResponse|BinaryFileResponse
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check access via milestone → contract
        $milestoneId = is_numeric(explode('/', $path)[0] ?? null) ? (int) explode('/', $path)[0] : null;
        if ($milestoneId) {
            $milestone = Milestone::with('contract')->find($milestoneId);
            if ($milestone) {
                $contract = $milestone->contract;
                if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }
        }

        // Build full disk path: storage/app/public/milestone-submissions/{path}
        $fullPath = storage_path('app/public/milestone-submissions/' . $path);
        if (!file_exists($fullPath)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        $mime = mime_content_type($fullPath);

        return response()->file($fullPath, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Delete a milestone attachment file.
     * Only the employer who owns the contract can delete attachments.
     */
    public function destroyAttachment(Request $request, MilestoneAttachment $attachment): JsonResponse
    {
        $user = $this->authenticate($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $milestone = Milestone::with('contract')->find($attachment->milestone_id);
        if (!$milestone) {
            return response()->json(['message' => 'Milestone not found.'], 404);
        }

        $contract = $milestone->contract;
        if ($contract->employer_id !== $user->id && $user->role !== 'admin') {
            return response()->json(['message' => 'Only the employer can delete attachments.'], 403);
        }

        // Delete file from disk
        $fullPath = storage_path('app/public/' . $attachment->stored_path);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        // Delete DB record
        $attachment->delete();

        return response()->json(['message' => 'Attachment deleted successfully.']);
    }
}
