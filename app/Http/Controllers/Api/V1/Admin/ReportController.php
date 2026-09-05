<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Contract;
use App\Models\Milestone;
use App\Models\Report;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseApiController
{
    /**
     * List all reports (disputes).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Report::with('reporter');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->input('target_type'));
        }

        $reports = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            $reports,
            'Reports retrieved successfully.',
            200,
            [
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
                'per_page'     => $reports->perPage(),
                'total'        => $reports->total(),
            ]
        );
    }

    /**
     * Show a single report with its related milestone & contract.
    /**
     * Parse raw admin_notes string into a structured array of note objects.
     */
    public static function parseNotes(?string $rawNotes): array
    {
        if (empty($rawNotes)) {
            return [];
        }

        $decoded = json_decode($rawNotes, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Legacy string format fallback
        return [
            [
                'id'             => '1',
                'sender_id'       => 0,
                'sender_name'     => 'Admin',
                'sender_role'     => 'admin',
                'recipient_type' => 'both',
                'note'           => $rawNotes,
                'created_at'     => now()->toIso8601String(),
            ]
        ];
    }

    /**
     * Show a single report with its related milestone & contract.
     */
    public function show(Report $report): JsonResponse
    {
        $report->load('reporter');

        $data = $report->toArray();
        $data['notes_list'] = self::parseNotes($report->admin_notes);

        // Attach the related milestone and contract if this is a milestone dispute
        if ($report->target_type === 'milestone') {
            $milestone = Milestone::with(['contract.employer', 'contract.freelancer', 'submissions'])->find($report->target_id);
            if ($milestone) {
                $data['milestone'] = $milestone->toArray();
                $data['contract']  = $milestone->contract ? $milestone->contract->toArray() : null;
            }
        }

        return $this->sendResponse($data, 'Report retrieved successfully.');
    }

    /**
     * Admin adds a note/comment to the dispute for communication.
     */
    public function addNote(Request $request, Report $report): JsonResponse
    {
        $request->validate([
            'note'           => 'required|string|max:5000',
            'recipient_type' => 'nullable|string|in:freelancer,employer,both',
        ]);

        $actor         = $request->user();
        $noteText      = $request->input('note');
        $recipientType = $request->input('recipient_type', 'both');

        $notesList = self::parseNotes($report->admin_notes);
        $notesList[] = [
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'sender_id'       => $actor->id,
            'sender_name'     => $actor->name,
            'sender_role'     => 'admin',
            'recipient_type' => $recipientType,
            'note'           => $noteText,
            'created_at'     => now()->toIso8601String(),
        ];

        $report->update(['admin_notes' => json_encode($notesList)]);

        // Find associated contract parties
        $contract = null;
        if ($report->target_type === 'milestone') {
            $milestone = Milestone::with('contract')->find($report->target_id);
            $contract  = $milestone?->contract;
        } elseif ($report->target_type === 'contract') {
            $contract  = Contract::find($report->target_id);
        }

        $employerId   = $contract?->employer_id ?? ($report->reporter_id);
        $freelancerId = $contract?->freelancer_id;

        // Send targeted notifications
        if (($recipientType === 'employer' || $recipientType === 'both') && $employerId) {
            NotificationService::disputeUpdate($employerId, $report->reason, $report->id, $noteText, 'employer');
        }

        if (($recipientType === 'freelancer' || $recipientType === 'both') && $freelancerId) {
            NotificationService::disputeUpdate($freelancerId, $report->reason, $report->id, $noteText, 'freelancer');
        }

        $responseData = $report->fresh()->load('reporter')->toArray();
        $responseData['notes_list'] = $notesList;

        return $this->sendResponse($responseData, 'Note added successfully.');
    }

    /**
     * Resolve a dispute: admin decides to release funds to freelancer or refund employer.
     */
    public function resolve(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be resolved.', [], 422);
        }

        $request->validate([
            'resolution'       => 'nullable|string|max:2000',
            'resolution_type'  => 'required|in:release_to_freelancer,refund_to_employer',
        ]);

        $actor          = $request->user();
        $resolutionType = $request->input('resolution_type');
        $resolutionNote = $request->input('resolution');

        DB::transaction(function () use ($report, $actor, $resolutionType, $resolutionNote) {
            // Update the report
            $report->update([
                'status'           => 'resolved',
                'resolution'       => $resolutionNote,
                'resolution_type'  => $resolutionType,
                'resolved_at'      => now(),
            ]);

            $paymentService = app(PaymentService::class);

            // Handle milestone disputes
            if ($report->target_type === 'milestone') {
                $milestone = Milestone::with('contract')->find($report->target_id);

                if ($milestone && $milestone->contract) {
                    $contract = $milestone->contract;
                    $amount   = (float) $milestone->amount;

                    $report->update(['resolution_amount' => $amount]);

                    // Unset contract disputed status if no other active disputes
                    $remainingDisputes = $contract->milestones()
                        ->where('status', Milestone::STATUS_DISPUTED)
                        ->where('id', '!=', $milestone->id)
                        ->count();

                    if ($remainingDisputes === 0) {
                        $contract->update(['status' => Contract::STATUS_ACTIVE]);
                    }

                    if ($resolutionType === 'release_to_freelancer') {
                        // Admin rules in favor of freelancer — approve & release funds
                        $milestone->update([
                            'status'      => Milestone::STATUS_APPROVED,
                            'approved_at' => now(),
                        ]);

                        try {
                            $paymentService->releaseMilestone($milestone, $actor->id);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to release disputed milestone funds', [
                                'milestone_id' => $milestone->id,
                                'error'        => $e->getMessage(),
                            ]);
                        }

                        NotificationService::disputeResolved($contract->freelancer_id, $contract->title, $contract->id, 'freelancer', 'released');
                        NotificationService::disputeResolved($contract->employer_id, $contract->title, $contract->id, 'employer', 'released');

                    } else {
                        // Admin rules in favor of employer — refund funds to employer
                        try {
                            $paymentService->refundMilestone($milestone, $actor->id, $resolutionNote ?? 'Dispute resolved in favor of employer');
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to refund disputed milestone funds', [
                                'milestone_id' => $milestone->id,
                                'error'        => $e->getMessage(),
                            ]);
                        }

                        NotificationService::disputeResolved($contract->employer_id, $contract->title, $contract->id, 'employer', 'refunded');
                        NotificationService::disputeResolved($contract->freelancer_id, $contract->title, $contract->id, 'freelancer', 'refunded');
                    }
                }
            } elseif ($report->target_type === 'contract') {
                $contract = Contract::with('milestones')->find($report->target_id);
                if ($contract) {
                    $contract->update(['status' => Contract::STATUS_ACTIVE]);

                    $targetMilestones = $contract->milestones()
                        ->whereIn('status', [Milestone::STATUS_DISPUTED, Milestone::STATUS_SUBMITTED, Milestone::STATUS_FUNDED, Milestone::STATUS_IN_PROGRESS])
                        ->get();

                    foreach ($targetMilestones as $milestone) {
                        if ($resolutionType === 'release_to_freelancer') {
                            $milestone->update(['status' => Milestone::STATUS_APPROVED, 'approved_at' => now()]);
                            try {
                                $paymentService->releaseMilestone($milestone, $actor->id);
                            } catch (\Exception $e) {}
                        } else {
                            try {
                                $paymentService->refundMilestone($milestone, $actor->id, $resolutionNote ?? 'Contract dispute resolved in favor of employer');
                            } catch (\Exception $e) {}
                        }
                    }

                    NotificationService::disputeResolved($contract->freelancer_id, $contract->title, $contract->id, 'freelancer', $resolutionType === 'release_to_freelancer' ? 'released' : 'refunded');
                    NotificationService::disputeResolved($contract->employer_id, $contract->title, $contract->id, 'employer', $resolutionType === 'release_to_freelancer' ? 'released' : 'refunded');
                }
            }

            // Audit log
            AuditService::disputeResolved($report->id, $actor->id, [
                'reason'          => $report->reason,
                'target_type'     => $report->target_type,
                'target_id'       => $report->target_id,
                'resolution'      => $resolutionNote,
                'resolution_type' => $resolutionType,
            ]);
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Dispute resolved successfully.');
    }

    /**
     * Dismiss a report.
     */
    public function dismiss(Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be dismissed.', [], 422);
        }

        $actor = request()->user();

        DB::transaction(function () use ($report, $actor) {
            $report->update([
                'status'      => 'dismissed',
                'resolved_at' => now(),
            ]);

            AuditService::disputeDismissed($report->id, $actor->id, [
                'reason'      => $report->reason,
                'target_type' => $report->target_type,
                'target_id'   => $report->target_id,
            ]);
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report dismissed successfully.');
    }

    /**
     * Delete a report.
     */
    public function destroy(Report $report): JsonResponse
    {
        $actor = request()->user();

        AuditService::disputeDeleted($report->id, $actor->id, [
            'reason'      => $report->reason,
            'target_type' => $report->target_type,
            'target_id'   => $report->target_id,
            'old_status'  => $report->status,
        ]);

        $report->delete();

        return $this->sendResponse(null, 'Report deleted successfully.');
    }
}
