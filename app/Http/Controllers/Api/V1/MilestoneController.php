<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MilestoneResource;
use App\Models\Contract;
use App\Models\Milestone;
use App\Models\MilestoneAttachment;
use App\Models\MilestoneSubmission;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MilestoneController extends BaseApiController
{
    /**
     * List milestones for a contract.
     */
    public function index(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        $milestones = $contract->milestones()
            ->with(['creator', 'attachments', 'submissions' => fn ($q) => $q->latest()])
            ->orderBy('created_at')
            ->get();

        return $this->sendResponse(
            MilestoneResource::collection($milestones)->resolve($request),
            'Milestones retrieved successfully.'
        );
    }

    /**
     * Show a single milestone.
     */
    public function show(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this milestone.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        $milestone->load(['creator', 'attachments', 'submissions' => fn ($q) => $q->latest()->with('submitter')]);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone retrieved successfully.'
        );
    }

    /**
     * Employer: Create a milestone.
     */
    public function store(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can create milestones.');
        }

        if (!$contract->isActive()) {
            return $this->sendError('Milestones can only be created for active contracts.', [], 422);
        }

        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'deliverables' => 'nullable|string|max:5000',
            'amount'       => 'required|numeric|min:0|max:999999.99',
            'due_date'     => 'nullable|date|after:now',
            'files'        => 'nullable|array|max:10',
            'files.*'      => 'file|max:204800|mimes:jpg,jpeg,png,gif,webp,svg,mp4,mov,avi,mkv,webm,mp3,wav,ogg,flac,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv',
        ]);

        // Max 200MB per file for milestone attachments
        if ($request->hasFile('files')) {
            foreach ((array) $request->file('files') as $file) {
                if ($file->getSize() > 204800 * 1024) {
                    return $this->sendError('Each file must be under 200MB. "' . $file->getClientOriginalName() . '" is too large.', [], 422);
                }
            }
        }

        $milestone = DB::transaction(function () use ($validated, $contract, $user, $request) {
            $milestone = $contract->milestones()->create([
                'title'        => $validated['title'],
                'description'  => $validated['description'] ?? null,
                'deliverables' => $validated['deliverables'] ?? null,
                'amount'       => $validated['amount'],
                'due_date'     => $validated['due_date'] ?? null,
                'created_by'   => $user->id,
                'status'       => Milestone::STATUS_DRAFT,
            ]);

            // Handle file uploads — store in milestone-attachments/{milestone_id}
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('milestone-attachments/' . $milestone->id, 'public');
                        $milestone->attachments()->create([
                            'uploader_id'       => $user->id,
                            'original_filename' => $file->getClientOriginalName(),
                            'stored_path'       => $path,
                            'mime_type'         => $file->getMimeType(),
                            'file_size'         => $file->getSize(),
                        ]);
                    }
                }
            }

            // Notify freelancer
            NotificationService::milestoneCreated(
                $contract->freelancer_id,
                $milestone->title,
                $milestone->amount,
                $contract->title,
                $contract->id
            );

            AuditService::record(
                $user,
                'milestone.created',
                'Milestone',
                $milestone->id,
                "Employer created milestone \"{$milestone->title}\" in contract \"{$contract->title}\"."
            );

            return $milestone;
        });

        $milestone->load(['creator', 'attachments']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone created successfully.',
            201
        );
    }

    /**
     * Employer: Update a draft milestone.
     */
    public function update(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can update milestones.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if ($milestone->status !== Milestone::STATUS_DRAFT) {
            return $this->sendError('Only draft milestones can be updated.', [], 422);
        }

        $validated = $request->validate([
            'title'        => 'sometimes|required|string|max:255',
            'description'  => 'nullable|string|max:5000',
            'deliverables' => 'nullable|string|max:5000',
            'amount'       => 'sometimes|required|numeric|min:0|max:999999.99',
            'due_date'     => 'nullable|date|after:now',
        ]);

        $milestone->update($validated);
        $milestone->load(['creator']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone updated successfully.'
        );
    }

    /**
     * Employer: Delete a draft milestone.
     */
    public function destroy(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can delete milestones.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if ($milestone->status !== Milestone::STATUS_DRAFT) {
            return $this->sendError('Only draft milestones can be deleted.', [], 422);
        }

        $milestone->delete();

        return $this->sendResponse(null, 'Milestone deleted successfully.');
    }

    /**
     * Freelancer: Start working on a milestone.
     */
    public function startWork(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('Only the freelancer can start work on milestones.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if (!$milestone->canStartWork()) {
            return $this->sendError("Milestone cannot be started. Current status: '{$milestone->status}'.", [], 422);
        }

        DB::transaction(function () use ($milestone, $contract, $user) {
            $milestone->update([
                'status'     => Milestone::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);

            NotificationService::milestoneStarted(
                $contract->employer_id,
                $milestone->title,
                $contract->title,
                $contract->id
            );

            AuditService::record(
                $user,
                'milestone.started',
                'Milestone',
                $milestone->id,
                "Freelancer started work on milestone \"{$milestone->title}\"."
            );
        });

        $milestone->load(['creator']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Work started successfully.'
        );
    }

    /**
     * Freelancer: Submit work for a milestone.
     */
    public function submitWork(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('Only the freelancer can submit work.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if (!$milestone->canSubmitWork()) {
            return $this->sendError("Work cannot be submitted for this milestone. Current status: '{$milestone->status}'.", [], 422);
        }

        $validated = $request->validate([
            'description' => 'nullable|string|max:5000',
            'links'       => 'nullable|array|max:10',
            'links.*'     => 'url|max:2048',
            'files'       => 'nullable|array|max:10',
            'files.*'     => 'file|max:409600|mimes:jpg,jpeg,png,gif,webp,svg,mp4,mov,avi,mkv,webm,mp3,wav,ogg,flac,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z,txt,csv',
        ]);

        // Max 400MB per file for submissions
        if ($request->hasFile('files')) {
            foreach ((array) $request->file('files') as $file) {
                if ($file->getSize() > 409600 * 1024) {
                    return $this->sendError('Each file must be under 400MB. "' . $file->getClientOriginalName() . '" is too large.', [], 422);
                }
            }
        }

        // Handle file uploads
        $uploadedFiles = [];
        if ($request->hasFile('files')) {
            $files = $request->file('files');
            if (!is_array($files)) $files = [$files];
            foreach ($files as $file) {
                if ($file->isValid()) {
                    $path = $file->store('milestone-submissions/' . $milestone->id, 'public');
                    $uploadedFiles[] = [
                        'path' => $path,
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'type' => $file->getMimeType(),
                    ];
                }
            }
        }

        DB::transaction(function () use ($validated, $uploadedFiles, $milestone, $contract, $user) {
            // Create submission record
            $milestone->submissions()->create([
                'submitted_by' => $user->id,
                'description'  => $validated['description'] ?? null,
                'links'        => $validated['links'] ?? null,
                'files'        => $uploadedFiles ?: null,
                'status'       => 'submitted',
                'submitted_at' => now(),
            ]);

            $milestone->update([
                'status'       => Milestone::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);

            NotificationService::milestoneSubmitted(
                $contract->employer_id,
                $milestone->title,
                $contract->title,
                $contract->id
            );

            AuditService::record(
                $user,
                'milestone.submitted',
                'Milestone',
                $milestone->id,
                "Freelancer submitted work for milestone \"{$milestone->title}\"."
            );
        });

        $milestone->load(['creator', 'submissions']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Work submitted successfully. The employer has been notified.'
        );
    }

    /**
     * Employer: Approve a submitted milestone.
     */
    public function approve(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can approve milestones.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if (!$milestone->canReview()) {
            return $this->sendError("Milestone cannot be approved. Current status: '{$milestone->status}'.", [], 422);
        }

        DB::transaction(function () use ($milestone, $contract, $user) {
            $milestone->update([
                'status'      => Milestone::STATUS_APPROVED,
                'approved_at' => now(),
            ]);

            // Mark latest submission as approved
            $latestSubmission = $milestone->submissions()->latest()->first();
            if ($latestSubmission) {
                $latestSubmission->update([
                    'status'     => 'approved',
                    'reviewed_at' => now(),
                    'reviewed_by' => $user->id,
                ]);
            }

            NotificationService::milestoneApproved(
                $contract->freelancer_id,
                $milestone->title,
                $contract->title,
                $contract->id
            );

            AuditService::record(
                $user,
                'milestone.approved',
                'Milestone',
                $milestone->id,
                "Employer approved milestone \"{$milestone->title}\"."
            );

            // Release funds to freelancer if milestone was funded
            if ($milestone->isFunded()) {
                try {
                    $paymentService = app(PaymentService::class);
                    $paymentService->releaseMilestone($milestone, $user->id);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to release milestone funds', [
                        'milestone_id' => $milestone->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $milestone->load(['creator', 'submissions']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Work approved successfully.'
        );
    }

    /**
     * Employer: Request revision on a submitted milestone.
     */
    public function requestRevision(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('Only the employer can request revisions.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        if (!$milestone->canReview()) {
            return $this->sendError("Revision cannot be requested. Current status: '{$milestone->status}'.", [], 422);
        }

        $validated = $request->validate([
            'revision_note' => 'required|string|max:5000',
        ]);

        DB::transaction(function () use ($validated, $milestone, $contract, $user) {
            $milestone->update([
                'status' => Milestone::STATUS_REVISION,
            ]);

            // Mark latest submission as revision requested
            $latestSubmission = $milestone->submissions()->latest()->first();
            if ($latestSubmission) {
                $latestSubmission->update([
                    'status'        => 'revision_requested',
                    'revision_note' => $validated['revision_note'],
                    'reviewed_at'   => now(),
                    'reviewed_by'   => $user->id,
                ]);
            }

            NotificationService::milestoneRevision(
                $contract->freelancer_id,
                $milestone->title,
                $contract->title,
                $contract->id,
                $validated['revision_note']
            );

            AuditService::record(
                $user,
                'milestone.revision_requested',
                'Milestone',
                $milestone->id,
                "Employer requested revision for milestone \"{$milestone->title}\"."
            );
        });

        $milestone->load(['creator', 'submissions']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Revision requested successfully.'
        );
    }

    /**
     * Employer: Open a dispute on a milestone.
     */
    public function openDispute(Request $request, Contract $contract, Milestone $milestone): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this milestone.');
        }

        if ($milestone->contract_id !== $contract->id) {
            return $this->sendError('Milestone not found for this contract.', [], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:5000',
        ]);

        DB::transaction(function () use ($validated, $milestone, $contract, $user) {
            $milestone->update(['status' => Milestone::STATUS_DISPUTED]);
            $contract->update(['status' => Contract::STATUS_DISPUTED]);

            // Create a report for admin review
            \App\Models\Report::create([
                'reporter_id' => $user->id,
                'target_type' => 'milestone',
                'target_id'   => $milestone->id,
                'reason'      => $validated['reason'],
                'status'      => 'pending',
            ]);

            // Notify both parties
            $otherUserId = $user->id === $contract->employer_id
                ? $contract->freelancer_id
                : $contract->employer_id;
            $otherRole = $user->id === $contract->employer_id ? 'freelancer' : 'employer';

            NotificationService::disputeRaised(
                $otherUserId,
                $contract->title,
                $contract->id,
                $otherRole
            );

            AuditService::record(
                $user,
                'milestone.disputed',
                'Milestone',
                $milestone->id,
                "Dispute opened on milestone \"{$milestone->title}\"."
            );
        });

        $milestone->load(['creator']);

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Dispute opened successfully. An administrator will review it.'
        );
    }

    /**
     * Delete a file from a submission.
     */
    public function deleteSubmissionFile(Request $request, Contract $contract, Milestone $milestone, MilestoneSubmission $submission): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this submission.');
        }

        if ($milestone->contract_id !== $contract->id || $submission->milestone_id !== $milestone->id) {
            return $this->sendError('Submission not found.', [], 404);
        }

        $validated = $request->validate([
            'file_path' => 'required|string',
        ]);

        $files = $submission->files ?? [];
        $filePath = $validated['file_path'];
        $found = false;

        foreach ($files as $i => $file) {
            if ($file['path'] === $filePath) {
                // Delete from storage
                $fullPath = storage_path('app/public/' . $filePath);
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                unset($files[$i]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->sendError('File not found in this submission.', [], 404);
        }

        $submission->update(['files' => array_values($files)]);

        return $this->sendResponse(null, 'File deleted successfully.');
    }
}
