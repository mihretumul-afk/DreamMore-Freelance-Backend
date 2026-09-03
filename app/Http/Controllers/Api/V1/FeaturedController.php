<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AdminSetting;
use App\Models\FeaturedJob;
use App\Models\FeaturedProfile;
use App\Models\Job;
use App\Services\Payment\PaymentService;
use App\Services\Payment\Providers\SandboxProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeaturedController extends BaseApiController
{
    /**
     * Feature a job listing (employer only).
     *
     * POST /api/v1/jobs/{job}/feature
     *
     * Checks:
     * 1. Feature flag enabled
     * 2. User is authenticated employer
     * 3. User owns the job
     * 4. Job is not already featured
     * 5. Sufficient wallet balance
     */
    public function featureJob(Request $request, Job $job): JsonResponse
    {
        // Check feature flag first (before any auth/ownership checks)
        $enabled = AdminSetting::getValue('featured_jobs_enabled', 'false', 'boolean');
        if (!$enabled) {
            return $this->sendForbidden('This feature is not currently available.');
        }

        $user = $request->user();

        // Check role
        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can feature jobs.');
        }

        // Check ownership (admins can feature any job)
        if ($user->role !== 'admin' && $job->employer_id !== $user->id) {
            return $this->sendForbidden('You can only feature your own jobs.');
        }

        // Check if already featured
        $existingFeatured = FeaturedJob::where('job_id', $job->id)
            ->where('status', FeaturedJob::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingFeatured) {
            return $this->sendError('This job is already featured until ' . $existingFeatured->expires_at->format('M d, Y') . '.', [], 422);
        }

        // Charge the employer
        try {
            $service = new PaymentService(new SandboxProvider());
            $featuredJob = $service->chargeForFeaturedJob($job->id, $user->id);

            return $this->sendResponse([
                'featured_job' => [
                    'id' => $featuredJob->id,
                    'job_id' => $featuredJob->job_id,
                    'amount_paid' => (float) $featuredJob->amount_paid,
                    'duration_days' => $featuredJob->duration_days,
                    'starts_at' => $featuredJob->starts_at->toIso8601String(),
                    'expires_at' => $featuredJob->expires_at->toIso8601String(),
                    'status' => $featuredJob->status,
                ],
            ], 'Job featured successfully.', 201);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Feature the freelancer's own profile.
     *
     * POST /api/v1/freelancer-profile/feature
     *
     * Checks:
     * 1. Feature flag enabled
     * 2. User is authenticated freelancer
     * 3. Profile is not already featured
     * 4. Sufficient wallet balance
     */
    public function featureProfile(Request $request): JsonResponse
    {
        // Check feature flag first
        $enabled = AdminSetting::getValue('featured_profiles_enabled', 'false', 'boolean');
        if (!$enabled) {
            return $this->sendForbidden('This feature is not currently available.');
        }

        $user = $request->user();

        // Check role
        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can feature their profiles.');
        }

        // Check if already featured
        $existingFeatured = FeaturedProfile::where('user_id', $user->id)
            ->where('status', FeaturedProfile::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingFeatured) {
            return $this->sendError('Your profile is already featured until ' . $existingFeatured->expires_at->format('M d, Y') . '.', [], 422);
        }

        // Charge the freelancer
        try {
            $service = new PaymentService(new SandboxProvider());
            $featuredProfile = $service->chargeForFeaturedProfile($user->id);

            return $this->sendResponse([
                'featured_profile' => [
                    'id' => $featuredProfile->id,
                    'user_id' => $featuredProfile->user_id,
                    'amount_paid' => (float) $featuredProfile->amount_paid,
                    'duration_days' => $featuredProfile->duration_days,
                    'starts_at' => $featuredProfile->starts_at->toIso8601String(),
                    'expires_at' => $featuredProfile->expires_at->toIso8601String(),
                    'status' => $featuredProfile->status,
                ],
            ], 'Profile featured successfully.', 201);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Get featured status for a job.
     *
     * GET /api/v1/jobs/{job}/featured
     */
    public function jobFeaturedStatus(Job $job): JsonResponse
    {
        $featuredJob = FeaturedJob::where('job_id', $job->id)
            ->where('status', FeaturedJob::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        return $this->sendResponse([
            'is_featured' => $featuredJob !== null,
            'featured_until' => $featuredJob?->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Get featured status for the freelancer's profile.
     *
     * GET /api/v1/freelancer-profile/featured
     */
    public function profileFeaturedStatus(Request $request): JsonResponse
    {
        $featuredProfile = FeaturedProfile::where('user_id', $request->user()->id)
            ->where('status', FeaturedProfile::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->first();

        return $this->sendResponse([
            'is_featured' => $featuredProfile !== null,
            'featured_until' => $featuredProfile?->expires_at?->toIso8601String(),
        ]);
    }
}
