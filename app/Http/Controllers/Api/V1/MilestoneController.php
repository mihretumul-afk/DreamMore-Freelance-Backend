<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\MilestoneRequest;
use App\Http\Requests\Api\V1\MilestoneSubmissionRequest;
use App\Http\Resources\Api\V1\MilestoneResource;
use App\Models\Contract;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilestoneController extends BaseApiController
{
    /**
     * Milestone statuses the employer may still edit.
     */
    private const EDITABLE_STATUSES = ['pending', 'in_progress'];

    /**
     * Milestone statuses a freelancer may submit from.
     */
    private const SUBMITTABLE_STATUSES = ['pending', 'in_progress'];

    /**
     * List milestones for a contract the user belongs to.
     */
    public function index(Request $request, Contract $contract): JsonResponse
    {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $milestones = $contract->milestones()->orderBy('created_at')->get();

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

        return $this->sendResponse(
            new MilestoneResource($milestone),
            'Milestone retrieved successfully.'
        );
    }

    /**
     * Employer updates a pending/in-progress milestone on an active contract.
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
                "Only pending or in-progress milestones can be updated. Current status: '{$milestone->status}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($milestone, $request) {
            $milestone->update($request->validated());
        });

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone updated successfully.'
        );
    }

    /**
     * Freelancer submits a pending/in-progress milestone for review.
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

        DB::transaction(function () use ($milestone) {
            $milestone->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'approved_at' => null,
            ]);
        });

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone submitted for review.'
        );
    }

    /**
     * Employer approves a submitted milestone.
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

        DB::transaction(function () use ($milestone) {
            $milestone->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);
        });

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Milestone approved successfully.'
        );
    }

    /**
     * Employer sends a submitted milestone back for revision.
     */
    public function revision(MilestoneSubmissionRequest $request, Contract $contract, Milestone $milestone): JsonResponse
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

        DB::transaction(function () use ($milestone) {
            $milestone->update([
                'status' => 'in_progress',
                'submitted_at' => null,
            ]);
        });

        return $this->sendResponse(
            new MilestoneResource($milestone->fresh()),
            'Revision requested. Milestone returned to in-progress.'
        );
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
            return $this->sendForbidden('Only freelancers can submit milestones.');
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
