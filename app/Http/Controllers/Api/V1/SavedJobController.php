<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\SavedJobResource;
use App\Models\Job;
use App\Models\SavedJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedJobController extends BaseApiController
{
    /**
     * List the authenticated user's saved jobs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $savedJobs = SavedJob::query()
            ->where('user_id', $user->id)
            ->whereHas('job', fn ($j) => $j->where('status', 'open')->whereHas('employer', fn ($e) => $e->where('status', 'active')))
            ->with(['job.category', 'job.skills', 'job.employer'])
            ->orderByDesc('saved_jobs.created_at')
            ->paginate(15);

        return $this->sendResponse(
            SavedJobResource::collection($savedJobs),
            'Saved jobs retrieved successfully.',
            200,
            $this->paginationMeta($savedJobs)
        );
    }

    /**
     * Save a job for the authenticated user.
     */
    public function save(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        // Ensure the job is open (publicly visible) and employer is active.
        if ($job->status !== 'open' || ! $job->employer || $job->employer->status !== 'active') {
            return $this->sendError('Job not found.', [], 404);
        }

        // Check for duplicate save.
        $exists = SavedJob::where('user_id', $user->id)
            ->where('job_id', $job->id)
            ->exists();

        if ($exists) {
            return $this->sendError('Job is already saved.', [], 422);
        }

        $saved = SavedJob::create([
            'user_id' => $user->id,
            'job_id' => $job->id,
        ]);

        $saved->load(['job.category', 'job.skills', 'job.employer']);

        return $this->sendResponse(
            new SavedJobResource($saved),
            'Job saved successfully.',
            201
        );
    }

    /**
     * Check whether a job is saved by the authenticated user.
     */
    public function saved(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        if ($job->status !== 'open' || ! $job->employer || $job->employer->status !== 'active') {
            return $this->sendResponse(
                ['saved' => false],
                'Job is not saved.'
            );
        }

        $isSaved = SavedJob::where('user_id', $user->id)
            ->where('job_id', $job->id)
            ->exists();

        return $this->sendResponse(
            ['saved' => $isSaved],
            $isSaved ? 'Job is saved.' : 'Job is not saved.'
        );
    }

    /**
     * Remove a saved job for the authenticated user.
     */
    public function destroy(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        $deleted = SavedJob::where('user_id', $user->id)
            ->where('job_id', $job->id)
            ->delete();

        if (!$deleted) {
            return $this->sendError('This job is not in your saved list.', [], 404);
        }

        return $this->sendResponse(null, 'Job removed from saved list.');
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
