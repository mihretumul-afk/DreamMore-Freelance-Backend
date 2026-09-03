<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\JobRequest;
use App\Http\Resources\Api\V1\JobResource;
use App\Models\FeaturedJob;
use App\Models\Job;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class JobController extends BaseApiController
{
    /**
     * Job statuses the owner may still edit or delete.
     */
    private const EDITABLE_STATUSES = ['draft', 'open', 'closed'];

    /**
     * Job statuses the owner may delete (includes history jobs).
     */
    private const DELETABLE_STATUSES = ['draft', 'open', 'closed', 'in_progress', 'completed'];

    /**
     * Allowed job status transitions (Stage 13: close / reopen).
     */
    private const STATUS_TRANSITIONS = [
        'open' => ['closed'],
        'closed' => ['open'],
    ];

    /**
     * Sortable columns for the public job browse endpoint.
     */
    private const SORTABLE = ['created_at', 'max_budget', 'min_budget', 'proposals_count', 'published_at'];

    /**
     * Browse open (published) jobs. Public endpoint with advanced
     * filtering, skill search, and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'skill_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:created_at,budget,newest'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $jobs = Job::query()
            ->with(['category', 'skills', 'employer'])
            ->open()
            ->whereHas('employer', fn ($empQuery) => $empQuery->where('status', 'active'))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                return $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('skills', fn ($skillQuery) => $skillQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('category', fn ($catQuery) => $catQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->input('category_id')))
            ->when($request->filled('skill_id'), fn ($query) => $query->whereHas('skills', fn ($skillQuery) => $skillQuery->where('skills.id', $request->input('skill_id'))))
            ->when($request->filled('budget_type'), fn ($query) => $query->where('budget_type', $request->input('budget_type')))
            ->when($request->filled('experience_level'), fn ($query) => $query->where('experience_level', $request->input('experience_level')))
            ->when($request->filled('location_type'), fn ($query) => $query->where('location_type', $request->input('location_type')))
            ->when($request->filled('location'), fn ($query) => $query->where('location', 'like', '%' . $request->input('location') . '%'))
            ->when($request->filled('min_budget'), fn ($query) => $query->where('max_budget', '>=', $request->input('min_budget')))
            ->when($request->filled('max_budget'), fn ($query) => $query->where('min_budget', '<=', $request->input('max_budget')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->select('marketplace_jobs.*')
            ->selectRaw('(SELECT CASE WHEN EXISTS (
                SELECT 1 FROM featured_jobs
                WHERE featured_jobs.job_id = marketplace_jobs.id
                AND featured_jobs.status = ?
                AND featured_jobs.expires_at > UTC_TIMESTAMP()
            ) THEN 1 ELSE 0 END) as is_featured', [FeaturedJob::STATUS_ACTIVE])
            ->orderByRaw('(SELECT CASE WHEN EXISTS (
                SELECT 1 FROM featured_jobs
                WHERE featured_jobs.job_id = marketplace_jobs.id
                AND featured_jobs.status = ?
                AND featured_jobs.expires_at > UTC_TIMESTAMP()
            ) THEN 0 ELSE 1 END)', [FeaturedJob::STATUS_ACTIVE])
            ->orderBy(...$this->resolveSort($request))
            ->paginate(15);

        // Use ->resolve() to get a plain array instead of a ResourceCollection
        // object. This prevents Laravel from nesting the items under an extra
        // "data" key during JSON serialization, which would break the frontend's
        // response.data mapping (making every job.id undefined).
        return $this->sendResponse(
            JobResource::collection($jobs)->resolve($request),
            'Jobs retrieved successfully.',
            200,
            $this->paginationMeta($jobs)
        );
    }

    /**
     * Resolve the sort column and direction from the request.
     * 'budget' maps to max_budget (descending shows highest-paying first).
     * 'newest' is an alias for created_at desc.
     *
     * @return array{string, string}
     */
    private function resolveSort(Request $request): array
    {
        $sort = $request->input('sort', 'newest');
        $direction = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';

        return match ($sort) {
            'budget' => ['max_budget', $direction],
            default => ['created_at', 'desc'],
        };
    }

    /**
     * Show a single job. Open jobs from active employers are public; owners and admins may view any status.
     */
    public function show(Request $request, Job $job): JsonResponse
    {
        $job->load(['category', 'skills', 'employer']);

        // Attach featured status
        $job->is_featured = FeaturedJob::where('job_id', $job->id)
            ->where('status', FeaturedJob::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->exists();

        $isPubliclyAvailable = $job->status === 'open' && $job->employer && $job->employer->status === 'active';

        if (! $isPubliclyAvailable) {
            // Try to resolve the authenticated user from Bearer token even on this public route
            $user = $request->user();
            if (!$user && $request->bearerToken()) {
                $user = Sanctum::actingAs() ?? $request->user();
            }

            if (!$user || ($user->id !== $job->employer_id && $user->role !== 'admin')) {
                return $this->sendError('Job not found.', [], 404);
            }
        }

        return $this->sendResponse(
            (new JobResource($job))->resolve($request),
            'Job retrieved successfully.'
        );
    }

    /**
     * Employer lists their own jobs (all statuses); admins see all jobs.
     */
    public function mine(Request $request): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $query = Job::with(['category', 'skills', 'employer'])
            ->when($request->user()->role !== 'admin', fn ($q) => $q->where('employer_id', $request->user()->id));

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $jobs = $query
            ->select('marketplace_jobs.*')
            ->selectRaw('(SELECT CASE WHEN EXISTS (
                SELECT 1 FROM featured_jobs
                WHERE featured_jobs.job_id = marketplace_jobs.id
                AND featured_jobs.status = ?
                AND featured_jobs.expires_at > UTC_TIMESTAMP()
            ) THEN 1 ELSE 0 END) as is_featured', [FeaturedJob::STATUS_ACTIVE])
            ->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            JobResource::collection($jobs)->resolve($request),
            'Your jobs retrieved successfully.',
            200,
            $this->paginationMeta($jobs)
        );
    }

    /**
     * Employer creates a job. New jobs are published (open) immediately.
     */
    public function store(JobRequest $request): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $validated = $request->validated();
        $skills = $validated['skills'] ?? [];
        unset($validated['skills']);

        $job = DB::transaction(function () use ($request, $validated, $skills) {
            $job = Job::create(array_merge($validated, [
                'employer_id' => $request->user()->id,
                'slug' => $this->uniqueSlug($validated['title']),
                'status' => 'open',
                'published_at' => now(),
            ]));

            if (!empty($skills)) {
                $job->skills()->sync($skills);
            }

            return $job;
        });

        $job->load(['category', 'skills', 'employer']);

        return $this->sendResponse(
            (new JobResource($job))->resolve($request),
            'Job created successfully.',
            201
        );
    }

    /**
     * Employer updates their own job while it is draft, open or closed.
     */
    public function update(JobRequest $request, Job $job): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        if (!in_array($job->status, self::EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only draft, open or closed jobs can be updated. Current status: '{$job->status}'.",
                [],
                422
            );
        }

        $validated = $request->validated();
        $skills = $validated['skills'] ?? null;
        unset($validated['skills']);

        DB::transaction(function () use ($job, $validated, $skills) {
            $job->update($validated);

            if (!is_null($skills)) {
                $job->skills()->sync($skills);
            }
        });

        $job->load(['category', 'skills', 'employer']);

        return $this->sendResponse(
            (new JobResource($job->fresh()))->resolve($request),
            'Job updated successfully.'
        );
    }

    /**
     * Employer deletes their own job.
     * Draft/open jobs with proposals cannot be deleted; closed/in-progress/completed jobs can.
     */
    public function destroy(Request $request, Job $job): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        if (!in_array($job->status, self::DELETABLE_STATUSES, true)) {
            return $this->sendError(
                "Only draft, open, closed, in-progress or completed jobs can be deleted. Current status: '{$job->status}'.",
                [],
                422
            );
        }

        // Draft and open jobs with proposals cannot be deleted (they may have active interest).
        // Closed, in-progress and completed jobs can be deleted as history cleanup.
        if (in_array($job->status, ['draft', 'open'], true) && $job->proposals()->exists()) {
            return $this->sendError('Jobs with proposals cannot be deleted. Close the job first.', [], 422);
        }

        DB::transaction(function () use ($job) {
            $job->delete();
        });

        return $this->sendResponse(null, 'Job deleted successfully.');
    }

    /**
     * Employer closes their open job (no longer visible to job seekers).
     */
    public function close(Request $request, Job $job): JsonResponse
    {
        return $this->changeStatus($request, $job, 'closed');
    }

    /**
     * Employer reopens their closed job (republished as open).
     */
    public function reopen(Request $request, Job $job): JsonResponse
    {
        return $this->changeStatus($request, $job, 'open');
    }

    /**
     * Apply a guarded status change using the allowed transitions table.
     */
    private function changeStatus(Request $request, Job $job, string $newStatus): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $job = $this->loadOwnedJob($request, $job);
        if ($job instanceof JsonResponse) {
            return $job;
        }

        if ($job->status === $newStatus) {
            return $this->sendError("Job is already '{$job->status}'.", [], 422);
        }

        if (!in_array($job->status, self::STATUS_TRANSITIONS[$newStatus] ?? [], true)) {
            return $this->sendError(
                "Job cannot be moved from '{$job->status}' to '{$newStatus}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($job, $newStatus) {
            $job->update([
                'status' => $newStatus,
                'published_at' => $newStatus === 'open' ? now() : $job->published_at,
            ]);
        });

        $job->load(['category', 'skills', 'employer']);

        return $this->sendResponse(
            (new JobResource($job->fresh()))->resolve($request),
            $newStatus === 'closed' ? 'Job closed successfully.' : 'Job reopened successfully.'
        );
    }

    /**
     * Only employers and admins may create/update/close/reopen/delete jobs.
     */
    private function requireEmployer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can manage jobs.');
        }

        return null;
    }

    /**
     * Ensure the job belongs to the authenticated user (admins bypass).
     */
    private function loadOwnedJob(Request $request, Job $job): Job|JsonResponse
    {
        $user = $request->user();

        if ($job->employer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this job.');
        }

        return $job;
    }

    /**
     * Build a unique URL slug from the title.
     */
    private function uniqueSlug(string $title): string
    {
        $slug = Str::slug($title);

        if (Job::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(6));
        }

        return $slug;
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
