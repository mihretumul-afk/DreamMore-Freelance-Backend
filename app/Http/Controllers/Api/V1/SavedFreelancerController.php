<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\SavedFreelancerResource;
use App\Models\FreelancerProfile;
use App\Models\SavedFreelancer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedFreelancerController extends BaseApiController
{
    /**
     * List the authenticated user's saved freelancers.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $saved = SavedFreelancer::query()
            ->where('user_id', $user->id)
            ->whereHas('freelancerProfile.user', fn ($u) => $u->where('status', 'active')->where('role', 'freelancer'))
            ->with(['freelancerProfile.user', 'freelancerProfile.skills'])
            ->orderByDesc('saved_freelancers.created_at')
            ->paginate(15);

        return $this->sendResponse(
            SavedFreelancerResource::collection($saved),
            'Saved freelancers retrieved successfully.',
            200,
            $this->paginationMeta($saved)
        );
    }

    /**
     * Save a freelancer profile for the authenticated user.
     */
    public function save(Request $request, FreelancerProfile $freelancer): JsonResponse
    {
        $user = $request->user();

        // Ensure target freelancer exists and is active.
        if (! $freelancer->user || $freelancer->user->status !== 'active' || $freelancer->user->role !== 'freelancer') {
            return $this->sendError('Freelancer profile not found.', [], 404);
        }

        // Prevent saving your own profile.
        if ($user->id === $freelancer->user_id) {
            return $this->sendError('You cannot save your own profile.', [], 422);
        }

        // Check for duplicate save.
        $exists = SavedFreelancer::where('user_id', $user->id)
            ->where('freelancer_profile_id', $freelancer->id)
            ->exists();

        if ($exists) {
            return $this->sendError('Freelancer is already saved.', [], 422);
        }

        $saved = SavedFreelancer::create([
            'user_id' => $user->id,
            'freelancer_profile_id' => $freelancer->id,
        ]);

        $saved->load(['freelancerProfile.user', 'freelancerProfile.skills']);

        return $this->sendResponse(
            new SavedFreelancerResource($saved),
            'Freelancer saved successfully.',
            201
        );
    }

    /**
     * Check whether a freelancer is saved by the authenticated user.
     */
    public function saved(Request $request, FreelancerProfile $freelancer): JsonResponse
    {
        $user = $request->user();

        if (! $freelancer->user || $freelancer->user->status !== 'active' || $freelancer->user->role !== 'freelancer') {
            return $this->sendResponse(
                ['saved' => false],
                'Freelancer is not saved.'
            );
        }

        $isSaved = SavedFreelancer::where('user_id', $user->id)
            ->where('freelancer_profile_id', $freelancer->id)
            ->exists();

        return $this->sendResponse(
            ['saved' => $isSaved],
            $isSaved ? 'Freelancer is saved.' : 'Freelancer is not saved.'
        );
    }

    /**
     * Remove a saved freelancer for the authenticated user.
     */
    public function destroy(Request $request, FreelancerProfile $freelancer): JsonResponse
    {
        $user = $request->user();

        $deleted = SavedFreelancer::where('user_id', $user->id)
            ->where('freelancer_profile_id', $freelancer->id)
            ->delete();

        if (!$deleted) {
            return $this->sendError('This freelancer is not in your saved list.', [], 404);
        }

        return $this->sendResponse(null, 'Freelancer removed from saved list.');
    }

    /**
     * Extract pagination info for the response meta.
     *
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
