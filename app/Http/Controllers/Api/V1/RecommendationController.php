<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\JobResource;
use App\Http\Resources\Api\V1\FreelancerResource;
use App\Models\FreelancerProfile;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends BaseApiController
{
    public function __construct(
        private RecommendationService $recommendationService,
    ) {}

    /**
     * Get recommended jobs for the authenticated freelancer.
     *
     * Recommendation logic considers:
     * - Freelancer's skills matching job requirements
     * - Category alignment
     * - Experience level match
     * - Location match
     * - Saved job interests (skill/category overlap)
     * - Keyword relevance
     */
    public function jobs(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can receive job recommendations.');
        }

        $limit = min((int) $request->input('per_page', 10), 25);
        $recommendations = $this->recommendationService->getRecommendedJobs($user, $limit);

        $data = $recommendations->map(fn ($rec) => [
            'job' => new JobResource($rec['job']),
            'reason' => $rec['reason'],
            'score' => $rec['score'],
        ])->values();

        return $this->sendResponse(
            $data,
            $data->isEmpty()
                ? 'No job recommendations yet. Complete your profile and save jobs to get personalized suggestions.'
                : 'Job recommendations retrieved successfully.',
        );
    }

    /**
     * Get recommended freelancers for the authenticated employer.
     *
     * Recommendation logic considers:
     * - Skills matching employer's posted job needs
     * - Category alignment from employer's jobs
     * - Experience level match
     * - Preferred skill overlap from saved freelancers
     * - Location preferences from saved freelancers
     * - High ratings
     */
    public function freelancers(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can receive freelancer recommendations.');
        }

        $limit = min((int) $request->input('per_page', 10), 25);
        $recommendations = $this->recommendationService->getRecommendedFreelancers($user, $limit);

        $data = $recommendations->map(function ($rec) {
            $profile = $rec['freelancer'];
            // Load user relation if not loaded.
            if (! $profile->relationLoaded('user')) {
                $profile->load('user');
            }

            return [
                'freelancer' => new FreelancerResource($profile),
                'reason' => $rec['reason'],
                'score' => $rec['score'],
            ];
        })->values();

        return $this->sendResponse(
            $data,
            $data->isEmpty()
                ? 'No freelancer recommendations yet. Post jobs and save freelancers to get personalized suggestions.'
                : 'Freelancer recommendations retrieved successfully.',
        );
    }
}
