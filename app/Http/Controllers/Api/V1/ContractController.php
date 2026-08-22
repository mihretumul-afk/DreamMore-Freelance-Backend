<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ContractResource;
use App\Models\Contract;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Report;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractController extends BaseApiController
{
    /**
     * Allowed contract status transitions.
     * Terminal states (completed/cancelled) are not present as sources.
     */
    private const TRANSITIONS = [
        'active' => ['paused', 'completed', 'cancelled', 'disputed'],
        'paused' => ['active', 'cancelled', 'disputed'],
        'disputed' => ['active', 'completed', 'cancelled'],
    ];

    private const ACTION_MESSAGES = [
        'paused' => 'Contract paused successfully.',
        'active' => 'Contract resumed successfully.',
        'cancelled' => 'Contract cancelled successfully.',
        'disputed' => 'Contract placed in dispute.',
    ];

    /**
     * List contracts belonging to the authenticated user (or all for admins).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $contracts = Contract::with(['job', 'employer', 'freelancer', 'milestones'])
            ->when($user->role !== 'admin', function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('employer_id', $user->id)
                        ->orWhere('freelancer_id', $user->id);
                });
            })
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse(
            ContractResource::collection($contracts),
            'Contracts retrieved successfully.'
        );
    }

    /**
     * Show a single contract (owner or admin only).
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        return $this->sendResponse(
            new ContractResource($contract),
            'Contract retrieved successfully.'
        );
    }

    public function pause(Request $request, Contract $contract): JsonResponse
    {
        return $this->changeStatus($request, $contract, 'paused');
    }

    public function resume(Request $request, Contract $contract): JsonResponse
    {
        return $this->changeStatus($request, $contract, 'active');
    }

    /**
     * Complete a contract. All milestones must be approved/paid first.
     */
    public function complete(Request $request, Contract $contract): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        if (!in_array('completed', self::TRANSITIONS[$contract->status] ?? [], true)) {
            return $this->sendError(
                "Contract cannot be completed from '{$contract->status}' status.",
                [],
                422
            );
        }

        $unfinished = $contract->milestones->reject(
            fn ($milestone) => in_array($milestone->status, ['approved', 'paid'], true)
        );

        if ($unfinished->isNotEmpty()) {
            return $this->sendError(
                'All milestones must be approved before the contract can be completed. '
                . $unfinished->count() . ' milestone(s) remain unfinished.',
                [],
                422
            );
        }

        DB::transaction(function () use ($contract) {
            $contract->update([
                'status' => 'completed',
                'end_date' => now(),
            ]);

            // Update associated job status
            if ($contract->job) {
                $contract->job->update(['status' => 'completed']);
            }

            // Update freelancer profile stats
            $freelancerProfile = FreelancerProfile::where('user_id', $contract->freelancer_id)->first();
            if ($freelancerProfile) {
                $freelancerProfile->increment('completed_jobs_count');
                $freelancerProfile->increment('total_earnings', (float) $contract->total_amount);
            }

            // Update employer profile stats
            $employerProfile = EmployerProfile::where('user_id', $contract->employer_id)->first();
            if ($employerProfile) {
                $employerProfile->increment('total_spent', (float) $contract->total_amount);
            }
        });

        // Notify both parties
        NotificationService::contractCompleted($contract->freelancer_id, $contract->title, 'freelancer');
        NotificationService::contractCompleted($contract->employer_id, $contract->title, 'employer');

        $contract->refresh()->load(['job', 'employer', 'freelancer', 'milestones']);

        return $this->sendResponse(
            new ContractResource($contract),
            'Contract completed successfully.'
        );
    }

    public function cancel(Request $request, Contract $contract): JsonResponse
    {
        return $this->changeStatus($request, $contract, 'cancelled');
    }

    /**
     * Raise a dispute on a contract. Available to either contract participant.
     */
    public function dispute(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        if (!in_array($contract->status, ['active', 'paused'], true)) {
            return $this->sendError("Only active or paused contracts can be disputed. Current status: '{$contract->status}'.", [], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($contract, $user, $validated) {
            $contract->update(['status' => 'disputed']);

            Report::create([
                'reporter_id' => $user->id,
                'target_type' => 'contract',
                'target_id' => $contract->id,
                'reason' => $validated['reason'],
                'description' => $validated['description'] ?? null,
                'status' => 'pending',
            ]);
        });

        $isEmployer = $contract->employer_id === $user->id;
        $otherUserId = $isEmployer ? $contract->freelancer_id : $contract->employer_id;
        $otherUserRole = $isEmployer ? 'freelancer' : 'employer';

        NotificationService::disputeRaised($otherUserId, $contract->title, $contract->id, $otherUserRole);

        NotificationService::notifyAdmins(
            'admin_contract_disputed',
            'Contract Dispute Raised',
            "A dispute was raised on contract '{$contract->title}' by {$user->name}.",
            '/admin/reports'
        );

        $contract->refresh()->load(['job', 'employer', 'freelancer', 'milestones']);

        return $this->sendResponse(
            new ContractResource($contract),
            'Dispute raised successfully. An administrator has been notified to review the contract.'
        );
    }

    /**
     * Apply a guarded status transition to a contract.
     */
    private function changeStatus(Request $request, Contract $contract, string $newStatus): JsonResponse
    {
        $guard = $this->requireEmployer($request);
        if ($guard) {
            return $guard;
        }

        $contract = $this->loadOwnedContract($request, $contract);
        if ($contract instanceof JsonResponse) {
            return $contract;
        }

        $allowed = self::TRANSITIONS[$contract->status] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            return $this->sendError(
                "Contract cannot transition from '{$contract->status}' to '{$newStatus}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($contract, $newStatus) {
            $contract->update([
                'status' => $newStatus,
                'end_date' => in_array($newStatus, ['completed', 'cancelled'], true)
                    ? now()
                    : $contract->end_date,
            ]);
        });

        $contract->refresh()->load(['job', 'employer', 'freelancer', 'milestones']);

        return $this->sendResponse(
            new ContractResource($contract),
            self::ACTION_MESSAGES[$newStatus]
        );
    }

    /**
     * Only employers and admins may manage a contract status transitions.
     */
    private function requireEmployer(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'employer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only employers can manage contracts.');
        }

        return null;
    }

    /**
     * Load a contract the authenticated user owns (or an admin may view).
     * Prevents IDOR: ownership is checked through the relationship, not the URL id.
     */
    private function loadOwnedContract(Request $request, Contract $contract): Contract|JsonResponse
    {
        $user = $request->user();

        $isOwner = $contract->employer_id === $user->id || $contract->freelancer_id === $user->id;

        if (!$isOwner && $user->role !== 'admin') {
            return $this->sendError('You do not have access to this contract.', [], 403);
        }

        return $contract->load(['job', 'employer', 'freelancer', 'milestones']);
    }
}
