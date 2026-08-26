<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\MilestoneRequest;
use App\Http\Requests\Api\V1\MilestoneRevisionRequest;
use App\Http\Requests\Api\V1\MilestoneSubmissionRequest;
use App\Http\Resources\Api\V1\MilestoneResource;
use App\Http\Resources\Api\V1\MilestoneSubmissionResource;
use App\Models\Contract;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\MilestoneSubmission;
use App\Models\MilestoneSubmissionFile;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MilestoneController extends BaseApiController
{
    /**
     * Milestone statuses the employer may still edit.
     */
    private const EDITABLE_STATUSES = ['pending', 'in_progress', 'revision_requested'];

    /**
     * Milestone statuses a freelancer may submit from.
     */
    private const SUBMITTABLE_STATUSES = ['pending', 'in_progress', 'revision_requested'];

    /**
     * Milestone statuses that block funding or release.
     */
    private const PAYMENT_BLOCKED_STATUSES = ['disputed', 'paid'];

    /**
     * List milestones for a contract the user belongs to.
     */
    public function index(Request $request, Contract $contract): JsonResponse
    {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestones = $contract->milestones()
            ->with(['submissions.files', 'submissions.submitter', 'submissions.reviewer'])
            ->orderBy('created_at')
            ->get();

        return $this->sendResponse(
            MilestoneResource::collection($milestones),
            'Milestones retrieved successfully.'
        );
    }

    /**
     * Employer creates a milestone on an active contract.
     */
    public function store(MilestoneRequest $request, Contract $contract): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be created on active contracts.', [], 422);
        }

        if ($contract->status === 'disputed') {
            return $this->sendError('Milestones cannot be created on disputed contracts.', [], 422);
        }

        $milestone = DB::transaction(function () use ($contract, $request) {
            return $contract->milestones()->create($request->validated());
        });

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone created successfully.',
            201
        );
    }

    public function show(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        $milestone->load(['submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone retrieved successfully.'
        );
    }

    /**
     * Employer updates a pending/in-progress/revision_requested milestone on an active contract.
     */
    public function update(MilestoneRequest $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be updated on active contracts.', [], 422);
        }

        if (!in_array($milestone->status, self::EDITABLE_STATUSES, true)) {
            return $this->sendError(
                "Only pending, in-progress, or revision requested milestones can be updated. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($milestone, $request) {
            $milestone->update($request->validated());
        });

        $milestone->load(['submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone updated successfully.'
        );
    }

    /**
     * Freelancer submits a deliverable for milestone review (supports description, files, links).
     */
    public function submit(MilestoneSubmissionRequest $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $guard = $this->requireFreelancer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be submitted on active contracts.', [], 422);
        }

        if (!in_array($milestone->status, self::SUBMITTABLE_STATUSES, true)) {
            return $this->sendError(
                "Milestone cannot be submitted from '{$milestone->status}' status.",
                [],
                422
            );
        }

        $user = $request->user();
        $validated = $request->validated();

        $submission = DB::transaction(function () use ($contract, $milestone, $user, $validated, $request) {
            // Create the deliverable submission record
            $submission = MilestoneSubmission::create([
                'milestone_id' => $milestone->id,
                'submitted_by' => $user->id,
                'description' => $validated['description'] ?? null,
                'links' => $validated['links'] ?? [],
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            // Handle file uploads safely
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
                    $fileSize = $file->getSize();
                    $extension = $file->getClientOriginalExtension();
                    $storedName = Str::random(40) . ($extension ? ".{$extension}" : '');
                    $storedPath = $file->storeAs("deliverables/{$contract->id}/{$milestone->id}", $storedName);

                    MilestoneSubmissionFile::create([
                        'submission_id' => $submission->id,
                        'uploader_id' => $user->id,
                        'original_filename' => $originalName,
                        'stored_path' => $storedPath,
                        'mime_type' => $mimeType,
                        'file_size' => $fileSize,
                    ]);
                }
            }

            // Update milestone state
            $milestone->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'approved_at' => null,
            ]);

            return $submission;
        });

        // Notify the employer
        NotificationService::milestoneSubmitted(
            $contract->employer_id,
            $milestone->title,
            $contract->title,
            $contract->id
        );

        $milestone->load(['submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Work submitted successfully.'
        );
    }

    /**
     * Employer approves a submitted milestone deliverable.
     * If all contract milestones are approved, auto-completes the contract and job.
     */
    public function approve(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be approved on active contracts.', [], 422);
        }

        if ($milestone->status !== 'submitted') {
            return $this->sendError(
                "Only submitted milestones can be approved. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        $user = $request->user();

        DB::transaction(function () use ($milestone, $contract, $user) {
            // Update latest submission to approved
            $latestSubmission = $milestone->submissions()->where('status', 'submitted')->latest()->first();
            if ($latestSubmission) {
                $latestSubmission->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'reviewed_by' => $user->id,
                ]);
            }

            // Update milestone
            $milestone->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            // Check if all milestones in contract are approved
            $allApproved = $contract->milestones()->whereNotIn('status', ['approved', 'paid'])->doesntExist();

            if ($allApproved) {
                // Auto-complete contract
                $contract->update([
                    'status' => 'completed',
                    'end_date' => now(),
                ]);

                // Update job
                if ($contract->job) {
                    $contract->job->update(['status' => 'completed']);
                }

                // Update stats
                $freelancerProfile = FreelancerProfile::where('user_id', $contract->freelancer_id)->first();
                if ($freelancerProfile) {
                    $freelancerProfile->increment('completed_jobs_count');
                    $freelancerProfile->increment('total_earnings', (float) $contract->total_amount);
                }

                $employerProfile = EmployerProfile::where('user_id', $contract->employer_id)->first();
                if ($employerProfile) {
                    $employerProfile->increment('total_spent', (float) $contract->total_amount);
                }

                // Notify both parties of completion
                NotificationService::contractCompleted($contract->freelancer_id, $contract->title, 'freelancer');
                NotificationService::contractCompleted($contract->employer_id, $contract->title, 'employer');
            }
        });

        // Notify the freelancer about milestone approval
        NotificationService::milestoneApproved(
            $contract->freelancer_id,
            $milestone->title,
            $contract->title,
            $contract->id
        );

        $milestone->load(['submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone approved successfully.'
        );
    }

    /**
     * Employer sends a submitted milestone back for revision with feedback instructions.
     */
    public function revision(MilestoneRevisionRequest $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be revised on active contracts.', [], 422);
        }

        if ($milestone->status !== 'submitted') {
            return $this->sendError(
                "Only submitted milestones can be sent for revision. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        $user = $request->user();
        $revisionNote = $request->input('revision_note');

        DB::transaction(function () use ($milestone, $user, $revisionNote) {
            // Update latest submission
            $latestSubmission = $milestone->submissions()->where('status', 'submitted')->latest()->first();
            if ($latestSubmission) {
                $latestSubmission->update([
                    'status' => 'revision_requested',
                    'revision_note' => $revisionNote,
                    'reviewed_at' => now(),
                    'reviewed_by' => $user->id,
                ]);
            }

            $milestone->update([
                'status' => 'revision_requested',
                'submitted_at' => null,
            ]);
        });

        // Notify the freelancer about revision request
        NotificationService::milestoneRevision(
            $contract->freelancer_id,
            $milestone->title,
            $contract->title,
            $contract->id,
            $revisionNote
        );

        $milestone->load(['submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Revision requested successfully.'
        );
    }

    /**
     * Get all deliverable submissions (history) for a milestone.
     */
    public function submissions(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        $submissions = $milestone->submissions()
            ->with(['submitter', 'reviewer', 'files'])
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse(
            MilestoneSubmissionResource::collection($submissions),
            'Milestone submissions retrieved successfully.'
        );
    }

    /**
     * Securely download a submission deliverable file.
     */
    public function downloadFile(
        Request $request,
        Contract $contract,
        Milestone $milestone,
        MilestoneSubmission $submission,
        MilestoneSubmissionFile $file
    ): StreamedResponse|JsonResponse {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($submission->milestone_id !== $milestone->id) {
            return $this->sendError('Submission not found for this milestone.', [], 404);
        }

        if ($file->submission_id !== $submission->id) {
            return $this->sendError('File not found for this submission.', [], 404);
        }

        if (!Storage::exists($file->stored_path)) {
            return $this->sendError('The requested file is no longer available.', [], 404);
        }

        return Storage::download($file->stored_path, $file->original_filename);
    }

    /**
     * Employer deletes a milestone that has not been started yet.
     */
    public function destroy(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($contract->status !== 'active') {
            return $this->sendError('Milestones can only be deleted on active contracts.', [], 422);
        }

        if ($milestone->status !== 'pending') {
            return $this->sendError(
                "Only pending milestones can be deleted. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($milestone) {
            $milestone->delete();
        });

        return $this->sendResponse(null, 'Milestone deleted successfully.');
    }

    /**
     * Only employers and admins may create/update/approve/revise/delete milestones.
     */
    private function requireEmployer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can manage milestones.');
        }

        return null;
    }

    /**
     * Only freelancers and admins may submit milestones.
     */
    private function requireFreelancer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can submit work for milestones.');
        }

        return null;
    }

    /**
     * Load a contract the authenticated user belongs to (owner or admin).
     */
    private function loadOwnedContract(Request $request, Contract $contract): Contract|JsonResponse
    {
        $user = $request->user();

        $isOwner = $contract->employer_id === $user->id || $contract->freelancer_id === $user->id;

        if (!$isOwner && $user->role !== 'admin') {
            return $this->sendError('You do not have access to this contract.', [], 403);
        }

        return $contract;
    }

    /**
     * Ensure the URL milestone actually belongs to the URL contract.
     * Returns 404 when it does not, to avoid leaking existence.
     */
    private function findScopedMilestone(Contract $contract, Milestone $milestone): Milestone|JsonResponse
    {
        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        return $milestone;
    }
}
