<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateEmployerProfileRequest;
use App\Http\Resources\EmployerProfileResource;
use App\Models\EmployerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployerProfileController extends BaseApiController
{
    /**
     * Get the authenticated employer's profile.
     */
    public function showCurrent(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only users with employer role have employer profiles.');
        }

        $profile = EmployerProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'total_spent' => 0.00,
                'posted_jobs_count' => 0,
                'rating' => 0.00,
            ]
        );

        $profile->load('user');

        return $this->sendResponse(
            new EmployerProfileResource($profile),
            'Employer profile retrieved successfully.'
        );
    }

    /**
     * Update the authenticated employer's profile.
     */
    public function update(UpdateEmployerProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $profile = EmployerProfile::firstOrCreate(['user_id' => $user->id]);

        $validated = $request->validated();

        // Separate user level fields if present
        $userFields = [];
        if (isset($validated['phone'])) {
            $userFields['phone'] = $validated['phone'];
            unset($validated['phone']);
        }
        if (isset($validated['bio'])) {
            $userFields['bio'] = $validated['bio'];
            unset($validated['bio']);
        }

        if (!empty($userFields)) {
            $user->update($userFields);
        }

        // Update profile model attributes
        $profile->update(array_filter($validated, fn ($value) => !is_null($value)));

        $profile->load('user');

        return $this->sendResponse(
            new EmployerProfileResource($profile),
            'Employer profile updated successfully.'
        );
    }

    /**
     * Get any public employer profile by user ID or profile ID.
     */
    public function showPublic(string $id): JsonResponse
    {
        $profile = EmployerProfile::with('user')
            ->whereHas('user', fn ($userQuery) => $userQuery->where('status', 'active')->where('role', 'employer'))
            ->where(function ($query) use ($id) {
                $query->where('id', $id)
                    ->orWhere('user_id', $id);
            })
            ->first();

        if (!$profile) {
            return $this->sendError('Employer profile not found.', [], 404);
        }

        return $this->sendResponse(
            new EmployerProfileResource($profile),
            'Public employer profile retrieved successfully.'
        );
    }
}
