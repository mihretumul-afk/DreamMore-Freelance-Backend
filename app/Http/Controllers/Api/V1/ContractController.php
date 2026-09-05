<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ContractResource;
use App\Models\Contract;
use App\Models\Milestone;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractController extends BaseApiController
{
    /**
     * List contracts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Contract::with(['job', 'employer', 'freelancer', 'milestones'])
            ->where(function ($q) use ($user) {
                $q->where('employer_id', $user->id)
                  ->orWhere('freelancer_id', $user->id);
            });

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $contracts = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            ContractResource::collection($contracts)->resolve($request),
            'Contracts retrieved successfully.',
            200,
            [
                'current_page' => $contracts->currentPage(),
                'last_page'    => $contracts->lastPage(),
                'per_page'     => $contracts->perPage(),
                'total'        => $contracts->total(),
            ]
        );
    }

    /**
     * Show a single contract.
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        $contract->load([
            'job',
            'employer',
            'freelancer',
            'milestones.creator',
            'milestones.attachments',
            'milestones.submissions' => function ($q) {
                $q->latest();
            },
            'proposal',
        ]);

        return $this->sendResponse(
            new ContractResource($contract),
            'Contract retrieved successfully.'
        );
    }

    /**
     * Employer: Accept a contract (freelancer has already accepted).
     * This is called when employer reviews contract terms.
     */
    public function employerAccept(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        if ($contract->status !== Contract::STATUS_PENDING) {
            return $this->sendError("Contract cannot be accepted. Current status: '{$contract->status}'.", [], 422);
        }

        // Contract is auto-created by proposal acceptance.
        // Employer doesn't need to "accept" - contract starts active after freelancer accepts.
        return $this->sendResponse(
            new ContractResource($contract->fresh()->load(['job', 'employer', 'freelancer', 'milestones'])),
            'Contract is ready for freelancer acceptance.'
        );
    }

    /**
     * Freelancer: Accept the contract.
     */
    public function freelancerAccept(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        if ($contract->status !== Contract::STATUS_PENDING) {
            return $this->sendError("Contract cannot be accepted. Current status: '{$contract->status}'.", [], 422);
        }

        DB::transaction(function () use ($contract, $user) {
            $contract->update([
                'status'     => Contract::STATUS_ACTIVE,
                'start_date' => now(),
            ]);

            // Notify employer
            NotificationService::contractAccepted(
                $contract->employer_id,
                $contract->title,
                'employer',
                $contract->id
            );

            // Audit log
            AuditService::record(
                $user,
                'contract.accepted',
                'Contract',
                $contract->id,
                "Freelancer accepted contract for \"{$contract->title}\"."
            );
        });

        $contract->load(['job', 'employer', 'freelancer', 'milestones']);

        return $this->sendResponse(
            new ContractResource($contract),
            'Contract accepted successfully. You can now start working on milestones.'
        );
    }

    /**
     * Freelancer: Decline the contract.
     */
    public function freelancerDecline(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        if ($contract->status !== Contract::STATUS_PENDING) {
            return $this->sendError("Contract cannot be declined. Current status: '{$contract->status}'.", [], 422);
        }

        DB::transaction(function () use ($contract, $user, $request) {
            $contract->update([
                'status'     => Contract::STATUS_CANCELLED,
                'end_date'   => now(),
            ]);

            // Also reject the proposal
            $contract->proposal()->update(['status' => 'rejected']);

            // Reopen the job
            $contract->job()->update(['status' => 'open']);

            // Notify employer
            NotificationService::contractDeclined(
                $contract->employer_id,
                $contract->title,
                'employer',
                $contract->id,
                $request->input('reason')
            );

            AuditService::record(
                $user,
                'contract.declined',
                'Contract',
                $contract->id,
                "Freelancer declined contract for \"{$contract->title}\"."
            );
        });

        return $this->sendResponse(null, 'Contract declined successfully.');
    }

    /**
     * Delete a contract from history.
     * Only completed, cancelled, disputed, or pending contracts can be deleted.
     * Active/paused contracts cannot be deleted.
     */
    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this contract.');
        }

        $deletableStatuses = [
            Contract::STATUS_PENDING,
            Contract::STATUS_ACTIVE,
            Contract::STATUS_COMPLETED,
            Contract::STATUS_CANCELLED,
            Contract::STATUS_DISPUTED,
        ];

        if (!in_array($contract->status, $deletableStatuses, true)) {
            return $this->sendError(
                "Cannot delete a contract with status '{$contract->status}'.",
                [],
                422
            );
        }

        DB::transaction(function () use ($contract) {
            $contract->milestones()->delete();
            $contract->delete();
        });

        return $this->sendResponse(null, 'Contract deleted from your history.');
    }
}
