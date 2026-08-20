<?php

namespace App\Services;

use App\Models\Credential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles verification of Dream More LMS certificates.
 *
 * The LMS is the source of truth for Dream More certificates.
 * This service validates and processes LMS certificate data,
 * creating auto-verified credentials for legitimate completions.
 */
class LmsVerificationService
{
    /**
     * The LMS secret key used to authenticate incoming webhook requests.
     * In production this should be in config('app.lms_secret').
     */
    private static function getLmsSecret(): string
    {
        return config('app.lms_secret', env('LMS_SECRET_KEY', 'dream-more-lms-secret-key-change-in-production'));
    }

    /**
     * Verify an incoming LMS webhook signature.
     */
    public static function verifyLmsSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, self::getLmsSecret());
        return hash_equals($expected, $signature);
    }

    /**
     * Process a verified LMS certificate completion event.
     *
     * @param array{
     *   user_email: string,
     *   certificate_id: string,
     *   course_id: string,
     *   course_name: string,
     *   skill_name: string,
     *   skill_id: int|null,
     *   completion_date: string,
     *   issue_date: string|null,
     * } $data
     */
    public static function processCertificate(array $data): Credential
    {
        // 1. Map LMS user to AppWorks freelancer
        $user = User::where('email', $data['user_email'])->first();

        if (!$user) {
            throw new \InvalidArgumentException("No user found with email: {$data['user_email']}");
        }

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            throw new \InvalidArgumentException("User '{$user->email}' is not a freelancer.");
        }

        // 2. Prevent duplicate LMS certificate linkage
        $existing = Credential::where('user_id', $user->id)
            ->where('lms_certificate_id', $data['certificate_id'])
            ->first();

        if ($existing) {
            Log::info("LMS certificate {$data['certificate_id']} already linked to user {$user->id}");
            return $existing;
        }

        // 3. Create the credential record
        $credential = DB::transaction(function () use ($data, $user) {
            $credential = Credential::create([
                'user_id' => $user->id,
                'title' => $data['course_name'] ?? $data['skill_name'],
                'type' => Credential::TYPE_DREAM_MORE,
                'issuing_organization' => 'Dream More',
                'certificate_identifier' => $data['certificate_id'],
                'description' => "Completed {$data['course_name']} through Dream More Learning Platform.",
                'issue_date' => $data['issue_date'] ?? $data['completion_date'],
                'file_path' => 'lms-verified/no-document-required',
                'file_original_name' => null,
                // Auto-verified from trusted LMS source
                'status' => 'approved',
                'verification_source' => Credential::SOURCE_LMS,
                'lms_certificate_id' => $data['certificate_id'],
                'lms_course_id' => $data['course_id'],
                'lms_course_name' => $data['course_name'],
                'auto_verified' => true,
                'test_required' => false,
                'test_status' => 'not_required',
                'reviewed_at' => now(),
            ]);

            // 4. Link the skill if provided
            if (!empty($data['skill_id'])) {
                $freelancerProfile = $user->freelancerProfile;
                if ($freelancerProfile) {
                    $freelancerProfile->skills()->syncWithoutDetaching([
                        $data['skill_id'] => ['years_of_experience' => 1],
                    ]);
                }
            }

            return $credential;
        });

        // 5. Notify the freelancer
        NotificationService::lmsCertificateVerified(
            $user->id,
            $data['course_name'],
            $data['certificate_id']
        );

        Log::info("LMS certificate verified for user {$user->id}: {$data['certificate_id']}");

        return $credential;
    }

    /**
     * Revoke/update a previously verified LMS certificate.
     */
    public static function revokeCertificate(string $certificateId, ?string $reason = null): bool
    {
        $credential = Credential::where('lms_certificate_id', $certificateId)
            ->where('verification_source', Credential::SOURCE_LMS)
            ->first();

        if (!$credential) {
            return false;
        }

        $credential->update([
            'status' => 'rejected',
            'rejection_reason' => $reason ?? 'Certificate revoked by LMS source.',
            'auto_verified' => false,
            'admin_notes' => "Revoked via LMS webhook. Previous status: approved.",
        ]);

        NotificationService::lmsCertificateRevoked(
            $credential->user_id,
            $credential->title,
            $reason
        );

        return true;
    }

    /**
     * Get a list of all LMS-verified credentials for a user.
     */
    public static function getLmsCredentials(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Credential::where('user_id', $userId)
            ->where('verification_source', Credential::SOURCE_LMS)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Check if a freelancer has a Dream More certificate for a specific skill.
     */
    public static function hasLmsCertificateForSkill(int $userId, int $skillId): bool
    {
        return Credential::where('user_id', $userId)
            ->where('verification_source', Credential::SOURCE_LMS)
            ->where('status', 'approved')
            ->where('auto_verified', true)
            ->whereHas('skillTestAttempts.skillTest', function ($query) use ($skillId) {
                $query->where('skill_id', $skillId);
            })
            ->exists();
    }
}
