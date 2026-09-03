<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Api\V1\JobResource;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Job::with(['category', 'skills', 'employer']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('employer_id')) {
            $query->where('employer_id', $request->input('employer_id'));
        }

        $jobs = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            JobResource::collection($jobs)->resolve($request),
            'Jobs retrieved successfully.',
            200,
            [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ]
        );
    }

    public function moderate(Request $request, Job $job): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:open,closed,draft,cancelled,in_progress,completed',
        ]);

        $newStatus = $request->input('status');
        $oldStatus = $job->status;

        $job->update(['status' => $newStatus]);

        return $this->sendResponse(
            (new JobResource($job->fresh()->load(['category', 'skills', 'employer']))),
            "Job status changed from '{$oldStatus}' to '{$newStatus}'."
        );
    }

    public function destroy(Job $job): JsonResponse
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($job) {
            $job->delete();
        });

        return $this->sendResponse(null, 'Job deleted successfully.');
    }
}
