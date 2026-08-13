<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateFreelancerProfileRequest;
use App\Http\Resources\FreelancerProfileResource;
use App\Models\FreelancerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreelancerProfileController extends BaseApiController
{
    /**
     * Get the authenticated freelancer's profile.
     */
    public function showCurrent(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only users with freelancer role have freelancer profiles.');
        }

        $profile = FreelancerProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'availability_status' => 'available',
                'completed_jobs_count' => 0,
                'total_earnings' => 0.00,
                'rating' => 0.00,
            ]
        );

        $profile->load(['user', 'skills']);

        return $this->sendResponse(
            new FreelancerProfileResource($profile),
            'Freelancer profile retrieved successfully.'
        );
    }

    /**
     * Update the authenticated freelancer's profile.
     */
    public function update(UpdateFreelancerProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $profile = FreelancerProfile::firstOrCreate(['user_id' => $user->id]);

        $validated = $request->validated();

        // Extract skills array if present
        $skills = $validated['skills'] ?? null;
        unset($validated['skills']);

        // Update main profile attributes
        $profile->update(array_filter($validated, fn ($value) => !is_null($value)));

        // Sync skills if provided
        if (is_array($skills)) {
            $syncData = [];
            foreach ($skills as $skillItem) {
                $syncData[$skillItem['id']] = [
                    'years_of_experience' => $skillItem['years_of_experience'] ?? 1,
                ];
            }
            $profile->skills()->sync($syncData);
        }

        $profile->load(['user', 'skills']);

        return $this->sendResponse(
            new FreelancerProfileResource($profile),
            'Freelancer profile updated successfully.'
        );
    }

    /**
     * Get any public freelancer profile by user ID or profile ID.
     */
    public function showPublic(string $id): JsonResponse
    {
        $profile = FreelancerProfile::with(['user', 'skills'])
            ->where('id', $id)
            ->orWhere('user_id', $id)
            ->first();

        if (!$profile) {
            return $this->sendError('Freelancer profile not found.', [], 404);
        }

        return $this->sendResponse(
            new FreelancerProfileResource($profile),
            'Public freelancer profile retrieved successfully.'
        );
    }
}
