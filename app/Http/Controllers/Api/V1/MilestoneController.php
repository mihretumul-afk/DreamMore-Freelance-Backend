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
use App\Models\MilestoneAttachment;
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
    private const EDITABLE_STATUSES = [
        Milestone::STATUS_AWAITING_FUNDING,
        Milestone::STATUS_IN_PROGRESS,
        Milestone::STATUS_REVISION_REQUESTED,
    ];

    /**
     * Milestone statuses a freelancer may submit from.
     * NOTE: awaiting_funding and funded are NOT here — freelancer must start work first.
     */
    private const SUBMITTABLE_STATUSES = [
        Milestone::STATUS_IN_PROGRESS,
        Milestone::STATUS_REVISION_REQUESTED,
    ];

    /**
     * Milestone statuses that block funding or release.
     */
    private const PAYMENT_BLOCKED_STATUSES = [
        Milestone::STATUS_DISPUTED,
        Milestone::STATUS_PAID,
        Milestone::STATUS_RELEASED,
    ];

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
            ->with(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer'])
            ->orderBy('created_at')
            ->get();

        return $this->sendResponse(
            MilestoneResource::collection($milestones),
            'Milestones retrieved successfully.'
        );
    }

    /**
     * Employer creates a milestone on an active contract.
     * Status defaults to 'awaiting_funding'.
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

        $validated = $request->validated();
        $attachments = $request->file('attachments');

        $milestone = DB::transaction(function () use ($contract, $validated, $attachments, $request) {
            $milestone = $contract->milestones()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'],
                'due_date' => $validated['due_date'] ?? null,
                'status' => Milestone::STATUS_AWAITING_FUNDING,
            ]);

            // Handle file attachments
            if ($attachments && count($attachments) > 0) {
                $this->storeAttachments($milestone, $attachments, $request->user()->id);
            }

            // Notify freelancer about new milestone
            NotificationService::milestoneCreated(
                $contract->freelancer_id,
                $milestone->title,
                $milestone->amount,
                $contract->title,
                $contract->id
            );

            return $milestone;
        });

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

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

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone retrieved successfully.'
        );
    }

    /**
     * Employer updates a milestone on an active contract.
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
                "This milestone cannot be edited. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        $validated = $request->validated();
        $attachments = $request->file('attachments');

        DB::transaction(function () use ($milestone, $validated, $attachments, $request) {
            $milestone->update([
                'title' => $validated['title'] ?? $milestone->title,
                'description' => $validated['description'] ?? $milestone->description,
                'amount' => $validated['amount'] ?? $milestone->amount,
                'due_date' => $validated['due_date'] ?? $milestone->due_date,
            ]);

            // Handle new file attachments
            if ($attachments && count($attachments) > 0) {
                $this->storeAttachments($milestone, $attachments, $request->user()->id);
            }
        });

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone updated successfully.'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // FREELANCER: START WORK (funded → in_progress)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Freelancer starts working on a funded milestone.
     * Transitions: funded → in_progress
     * Freelancer MUST NOT start work before the employer funds the milestone.
     */
    public function startWork(Request $request, Contract $contract, Milestone $milestone): JsonResponse
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
            return $this->sendError('Milestones can only be started on active contracts.', [], 422);
        }

        // CRITICAL: Only allow starting funded milestones
        if (!$milestone->isEscrowFunded() || $milestone->status !== Milestone::STATUS_FUNDED) {
            return $this->sendError(
                'This milestone has not been funded yet. You cannot start work until the employer has successfully funded the milestone.',
                [],
                422
            );
        }

        DB::transaction(function () use ($milestone) {
            $milestone->update([
                'status' => Milestone::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        });

        // Notify the employer that work has started
        $contract = $milestone->contract;
        NotificationService::milestoneStarted(
            $contract->employer_id,
            $milestone->title,
            $contract->title,
            $contract->id
        );

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Work started successfully. Good luck!'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // FREELANCER: SUBMIT WORK (in_progress → submitted)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Freelancer submits a deliverable for milestone review.
     * Only allowed from in_progress or revision_requested status.
     * CRITICAL: Freelancer CANNOT submit before starting work (which requires funding).
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
                "Milestone cannot be submitted from '{$milestone->status}' status. You must wait for the milestone to be funded and started before submitting work.",
                [],
                422
            );
        }

        // Extra safety: ensure milestone is actually funded
        if (!$milestone->isEscrowFunded()) {
            return $this->sendError(
                'This milestone has not been funded. Work cannot be submitted for unfunded milestones.',
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
                'status' => Milestone::STATUS_SUBMITTED,
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

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Work submitted successfully.'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // EMPLOYER: APPROVE MILESTONE (submitted → approved)
    // ════════════════════════════════════════════════════════════════════

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

        if ($milestone->status !== Milestone::STATUS_SUBMITTED) {
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

            // Update milestone status to approved (ready for release)
            $milestone->update([
                'status' => Milestone::STATUS_APPROVED,
                'approved_at' => now(),
            ]);

            // Check if all milestones in contract are approved/released/paid
            $allApproved = $contract->milestones()
                ->whereNotIn('status', [
                    Milestone::STATUS_APPROVED,
                    Milestone::STATUS_RELEASED,
                    Milestone::STATUS_PAID,
                ])
                ->doesntExist();

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

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone approved successfully.'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // EMPLOYER: REQUEST REVISION (submitted → revision_requested → in_progress)
    // ════════════════════════════════════════════════════════════════════

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

        if ($milestone->status !== Milestone::STATUS_SUBMITTED) {
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
                'status' => Milestone::STATUS_REVISION_REQUESTED,
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

        $milestone->load(['attachments', 'submissions.files', 'submissions.submitter', 'submissions.reviewer']);

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

    // ════════════════════════════════════════════════════════════════════
    // MILESTONE ATTACHMENT ENDPOINTS (employer-created attachments)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Download a milestone attachment.
     */
    public function downloadAttachment(
        Request $request,
        Contract $contract,
        Milestone $milestone,
        MilestoneAttachment $attachment
    ): StreamedResponse|JsonResponse {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($attachment->milestone_id !== $milestone->id) {
            return $this->sendError('Attachment not found for this milestone.', [], 404);
        }

        if (!Storage::exists($attachment->stored_path)) {
            return $this->sendError('The requested file is no longer available.', [], 404);
        }

        return Storage::download($attachment->stored_path, $attachment->original_filename);
    }

    /**
     * Preview a milestone attachment inline (for images, video, audio, PDF).
     */
    public function previewAttachment(
        Request $request,
        Contract $contract,
        Milestone $milestone,
        MilestoneAttachment $attachment
    ): StreamedResponse|JsonResponse {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestone = $this->findScopedMilestone($contract, $milestone);
        if ($milestone instanceof JsonResponse) {
            return $milestone;
        }

        if ($attachment->milestone_id !== $milestone->id) {
            return $this->sendError('Attachment not found for this milestone.', [], 404);
        }

        if (!Storage::exists($attachment->stored_path)) {
            return $this->sendError('The requested file is no longer available.', [], 404);
        }

        $mimeType = $attachment->mime_type ?? 'application/octet-stream';

        return Storage::response($attachment->stored_path, $attachment->original_filename, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $attachment->original_filename . '"',
        ]);
    }

    /**
     * Delete a milestone attachment (only before funding).
     */
    public function deleteAttachment(
        Request $request,
        Contract $contract,
        Milestone $milestone,
        MilestoneAttachment $attachment
    ): JsonResponse {
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

        if ($attachment->milestone_id !== $milestone->id) {
            return $this->sendError('Attachment not found for this milestone.', [], 404);
        }

        // Only allow deletion before funding
        if ($milestone->isEscrowFunded()) {
            return $this->sendError('Cannot delete attachments from a funded milestone.', [], 422);
        }

        DB::transaction(function () use ($attachment) {
            if (Storage::exists($attachment->stored_path)) {
                Storage::delete($attachment->stored_path);
            }
            $attachment->delete();
        });

        return $this->sendResponse(null, 'Attachment deleted successfully.');
    }

    /**
     * Preview a submission file inline (for images, video, audio, PDF).
     */
    public function previewFile(
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

        $mimeType = $file->mime_type ?? 'application/octet-stream';

        return Storage::response($file->stored_path, $file->original_filename, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $file->original_filename . '"',
        ]);
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

        // Only allow deleting milestones that haven't been funded yet
        if ($milestone->status !== Milestone::STATUS_AWAITING_FUNDING) {
            return $this->sendError(
                'Only unfunded milestones can be deleted. Current status: \'' . $milestone->status . '\'.',
                [],
                422
            );
        }

        DB::transaction(function () use ($milestone) {
            // Delete associated attachment files
            foreach ($milestone->attachments as $attachment) {
                if (Storage::exists($attachment->stored_path)) {
                    Storage::delete($attachment->stored_path);
                }
            }
            $milestone->delete();
        });

        return $this->sendResponse(null, 'Milestone deleted successfully.');
    }

    // ════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Store milestone attachments from uploaded files.
     */
    private function storeAttachments(Milestone $milestone, array $files, int $uploaderId): void
    {
        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
            $fileSize = $file->getSize();
            $extension = $file->getClientOriginalExtension();
            $storedName = Str::random(40) . ($extension ? ".{$extension}" : '');
            $storedPath = $file->storeAs("milestone-attachments/{$milestone->contract_id}/{$milestone->id}", $storedName);

            MilestoneAttachment::create([
                'milestone_id' => $milestone->id,
                'uploader_id' => $uploaderId,
                'original_filename' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
            ]);
        }
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
