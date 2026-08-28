<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Contract;
use App\Models\Dispute;
use App\Models\Milestone;
use App\Services\NotificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisputeController extends BaseApiController
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * User raises a dispute on a contract / milestone. Freezes the milestone.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id' => 'required|exists:contracts,id',
            'milestone_id' => 'nullable|exists:milestones,id',
            'reason' => 'required|string|min:5|max:2000',
        ]);

        $user = $request->user();
        $contract = Contract::findOrFail($validated['contract_id']);

        if ($contract->employer_id !== $user->id && $contract->freelancer_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have permission to dispute this contract.');
        }

        $milestone = null;
        if (!empty($validated['milestone_id'])) {
            $milestone = Milestone::where('contract_id', $contract->id)
                ->where('id', $validated['milestone_id'])
                ->firstOrFail();
        }

        $dispute = DB::transaction(function () use ($contract, $milestone, $user, $validated) {
            if ($milestone) {
                $milestone->update(['status' => 'disputed']);
            }
            $contract->update(['status' => 'disputed']);

            return Dispute::create([
                'contract_id' => $contract->id,
                'milestone_id' => $milestone?->id,
                'raised_by' => $user->id,
                'reason' => $validated['reason'],
                'status' => 'open',
            ]);
        });

        // Notify admins
        NotificationService::notifyAdmins(
            'admin_dispute_created',
            'New Escrow Dispute Raised',
            "Dispute raised by {$user->name} on contract '{$contract->title}'.",
            '/admin/reports'
        );

        return $this->sendResponse($dispute->load(['contract', 'milestone', 'raisedBy']), 'Dispute raised successfully. Milestone frozen pending admin resolution.', 201);
    }

    /**
     * Admin lists all disputes.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->sendForbidden('Only administrators can view all disputes.');
        }

        $disputes = Dispute::with(['contract', 'milestone', 'raisedBy', 'resolvedBy'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->sendResponse($disputes->items(), 'Disputes retrieved successfully.', 200, [
            'current_page' => $disputes->currentPage(),
            'last_page' => $disputes->lastPage(),
            'per_page' => $disputes->perPage(),
            'total' => $disputes->total(),
        ]);
    }

    /**
     * Admin resolves a dispute (release to freelancer, or refund to employer).
     */
    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return $this->sendForbidden('Only administrators can resolve disputes.');
        }

        $validated = $request->validate([
            'resolution' => 'required|string|in:resolved_release,resolved_refund',
            'resolution_note' => 'required|string|min:5|max:2000',
        ]);

        $admin = $request->user();
        $milestone = $dispute->milestone;

        try {
            DB::transaction(function () use ($dispute, $milestone, $admin, $validated) {
                if ($validated['resolution'] === 'resolved_release') {
                    if ($milestone) {
                        // Force milestone to submitted so releaseMilestone can process it
                        $milestone->status = 'submitted';
                        $milestone->save();

                        $this->walletService->releaseMilestone($milestone, $admin);
                    }
                    $dispute->status = 'resolved_release';
                } else {
                    if ($milestone) {
                        $this->walletService->refundMilestone($milestone);
                    }
                    $dispute->status = 'resolved_refund';
                }

                $dispute->resolved_by = $admin->id;
                $dispute->resolution_note = $validated['resolution_note'];
                $dispute->resolved_at = now();
                $dispute->save();

                // Unfreeze contract status if no other active disputes
                $otherOpenDisputes = Dispute::where('contract_id', $dispute->contract_id)
                    ->where('id', '!=', $dispute->id)
                    ->where('status', 'open')
                    ->exists();

                if (!$otherOpenDisputes) {
                    $dispute->contract->update(['status' => 'active']);
                }
            });

            return $this->sendResponse($dispute->fresh(['contract', 'milestone', 'resolvedBy']), 'Dispute resolved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to resolve dispute: ' . $e->getMessage(), [], 422);
        }
    }
}
