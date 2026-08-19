<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateFreelancerProfileRequest;
use App\Http\Resources\Api\V1\FreelancerResource;
use App\Http\Resources\FreelancerProfileResource;
use App\Models\FreelancerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreelancerProfileController extends BaseApiController
{
    /**
     * Discover freelancers. Public endpoint with search, filtering, sorting
     * and pagination. Only public profile data is returned.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'category_id' => ['nullable', 'integer'],
            'skill_id' => ['nullable', 'integer'],
            'min_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'max_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'experience_level' => ['nullable', 'in:entry,intermediate,expert'],
            'availability_status' => ['nullable', 'in:available,busy,not_available'],
        ]);

        $search = $request->input('search');

        $query = FreelancerProfile::query()
            ->with(['user', 'skills'])
            ->when($request->filled('search'), function ($query) use ($search) {
                return $query->where(function ($query) use ($search) {
                    $query->where('headline', 'like', "%{$search}%")
                        ->orWhere('overview', 'like', "%{$search}%")
                        ->orWhere('location', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('skills', fn ($skillQuery) => $skillQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->whereHas('skills', fn ($skillQuery) => $skillQuery->where('category_id', $request->input('category_id'))))
            ->when($request->filled('skill_id'), fn ($query) => $query->whereHas('skills', fn ($skillQuery) => $skillQuery->where('skills.id', $request->input('skill_id'))))
            ->when($request->filled('experience_level'), fn ($query) => $query->where('experience_level', $request->input('experience_level')))
            ->when($request->filled('availability_status'), fn ($query) => $query->where('availability_status', $request->input('availability_status')))
            ->when($request->filled('min_hourly_rate'), fn ($query) => $query->where('hourly_rate', '>=', $request->input('min_hourly_rate')))
            ->when($request->filled('max_hourly_rate'), fn ($query) => $query->where('hourly_rate', '<=', $request->input('max_hourly_rate')));

        // Sorting: rating desc by default; also support created_at / hourly_rate.
        $sort = $request->input('sort', 'rating');
        $direction = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $sortable = ['rating', 'created_at', 'hourly_rate'];
        $sort = in_array($sort, $sortable, true) ? $sort : 'rating';
        $query->orderBy($sort, $direction);

        $perPage = $request->input('per_page', 15);
        $freelancers = $query->paginate($perPage);

        return $this->sendResponse(
            FreelancerResource::collection($freelancers),
            'Freelancers retrieved successfully.',
            200,
            $this->paginationMeta($freelancers)
        );
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
        $profile->update(array_filter($validated, fn ($value) => ! is_null($value)));

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

        if (! $profile) {
            return $this->sendError('Freelancer profile not found.', [], 404);
        }

        return $this->sendResponse(
            new FreelancerProfileResource($profile),
            'Public freelancer profile retrieved successfully.'
        );
    }
}
