<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ContractResource;
use App\Models\Contract;
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
        'active' => ['paused', 'completed', 'cancelled'],
        'paused' => ['active', 'cancelled'],
    ];

    private const ACTION_MESSAGES = [
        'paused' => 'Contract paused successfully.',
        'active' => 'Contract resumed successfully.',
        'cancelled' => 'Contract cancelled successfully.',
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
        });

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
     * Only employers and admins may manage a contract.
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
