<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FreelancerResource;
use App\Http\Resources\Api\V1\JobResource;
use App\Models\FreelancerProfile;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends BaseApiController
{
    /**
     * Global marketplace search — searches across jobs AND freelancers
     * simultaneously. Supports partial word matching, case-insensitive
     * search, multiple words, and ignores extra whitespace.
     *
     * GET /api/v1/search?q=video+editor
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->normalizeQuery($request->input('q'));
        $terms = $this->extractTerms($query);

        if (empty($terms)) {
            return $this->sendResponse([
                'jobs' => [],
                'freelancers' => [],
                'jobs_total' => 0,
                'freelancers_total' => 0,
            ], 'Search completed successfully.');
        }

        $perPage = min((int) $request->input('per_page', 10), 50);

        $jobs = $this->searchJobs($terms, $perPage);
        $freelancers = $this->searchFreelancers($terms, $perPage);

        return $this->sendResponse([
            'jobs' => JobResource::collection($jobs),
            'freelancers' => FreelancerResource::collection($freelancers),
            'jobs_total' => $jobs->total(),
            'freelancers_total' => $freelancers->total(),
        ], 'Search completed successfully.');
    }

    /**
     * Normalize the search query: trim, collapse multiple spaces,
     * convert to lowercase.
     */
    private function normalizeQuery(string $query): string
    {
        $query = mb_strtolower(trim($query));
        $query = preg_replace('/\s+/', ' ', $query);

        return $query;
    }

    /**
     * Extract individual search terms from the normalized query.
     * Each word becomes a separate term for partial matching.
     */
    private function extractTerms(string $query): array
    {
        $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($terms, fn ($term) => mb_strlen($term) >= 1));
    }

    /**
     * Search open jobs matching ALL terms (AND logic).
     * Each term must match at least one of: title, description,
     * category name, or skill name (partial, case-insensitive).
     */
    private function searchJobs(array $terms, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Job::query()
            ->with(['category', 'skills', 'employer'])
            ->open()
            ->whereHas('employer', fn ($emp) => $emp->where('status', 'active'));

        foreach ($terms as $term) {
            $escaped = $this->escapeLike($term);
            $pattern = "%{$escaped}%";

            $query->where(function ($q) use ($pattern) {
                $q->where('title', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern)
                    ->orWhereHas('category', fn ($cat) => $cat->where('name', 'like', $pattern))
                    ->orWhereHas('skills', fn ($skill) => $skill->where('name', 'like', $pattern));
            });
        }

        return $query->orderByDesc('published_at')->paginate($perPage);
    }

    /**
     * Search active freelancers matching ALL terms (AND logic).
     * Each term must match at least one of: user name, headline,
     * overview, skill name, or location (partial, case-insensitive).
     */
    private function searchFreelancers(array $terms, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = FreelancerProfile::query()
            ->with(['user', 'skills'])
            ->whereHas('user', fn ($user) => $user->where('status', 'active')->where('role', 'freelancer'));

        foreach ($terms as $term) {
            $escaped = $this->escapeLike($term);
            $pattern = "%{$escaped}%";

            $query->where(function ($q) use ($pattern) {
                $q->where('headline', 'like', $pattern)
                    ->orWhere('overview', 'like', $pattern)
                    ->orWhere('location', 'like', $pattern)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $pattern))
                    ->orWhereHas('skills', fn ($skill) => $skill->where('name', 'like', $pattern));
            });
        }

        return $query->orderByDesc('rating')->paginate($perPage);
    }

    /**
     * Escape LIKE special characters so user input is treated as literal text.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
