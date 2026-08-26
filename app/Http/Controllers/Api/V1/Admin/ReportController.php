<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Contract;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Payment;
use App\Models\Report;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Report::with('reporter');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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

    public function show(Report $report): JsonResponse
    {
        $report->load('reporter');

        $target = null;
        if ($report->target_type === 'contract') {
            $target = Contract::with(['employer', 'freelancer', 'job', 'milestones'])->find($report->target_id);
        }

        $data         = $report->toArray();
        $data['target'] = $target;

        return $this->sendResponse($data, 'Report retrieved successfully.');
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be resolved.', [], 422);
        }

        $request->validate([
            'resolution'      => 'nullable|string|max:2000',
            'contract_action' => 'nullable|in:active,completed,cancelled',
            'payment_action'  => 'nullable|in:release,refund,hold',
        ]);

        $contractAction = $request->input('contract_action', 'active');
        $paymentAction  = $request->input('payment_action', 'hold');
        $actor          = $request->user();

        DB::transaction(function () use ($report, $request, $contractAction, $paymentAction, $actor) {
            $report->update([
                'status'      => 'resolved',
                'resolution'  => $request->input('resolution'),
                'resolved_at' => now(),
            ]);

            if ($report->target_type === 'contract') {
                $contract = Contract::find($report->target_id);
                if ($contract) {
                    $contract->update([
                        'status'   => $contractAction,
                        'end_date' => in_array($contractAction, ['completed', 'cancelled'], true)
                            ? now()
                            : $contract->end_date,
                    ]);

                    if ($contractAction === 'completed') {
                        if ($contract->job) {
                            $contract->job->update(['status' => 'completed']);
                        }
                        $freelancerProfile = FreelancerProfile::where('user_id', $contract->freelancer_id)->first();
                        if ($freelancerProfile) {
                            $freelancerProfile->increment('completed_jobs_count');
                            $freelancerProfile->increment('total_earnings', (float) $contract->total_amount);
                        }
                        $employerProfile = EmployerProfile::where('user_id', $contract->employer_id)->first();
                        if ($employerProfile) {
                            $employerProfile->increment('total_spent', (float) $contract->total_amount);
                        }
                    }

                    // Handle payment decisions for disputed milestones
                    if ($paymentAction !== 'hold') {
                        $disputedMilestones = $contract->milestones()
                            ->where('status', 'disputed')
                            ->whereNotNull('escrow_funded_at')
                            ->whereNull('paid_at')
                            ->get();

                        foreach ($disputedMilestones as $milestone) {
                            if ($paymentAction === 'release') {
                                // Release payment to freelancer
                                try {
                                    PaymentService::releaseMilestonePayment(
                                        $contract,
                                        $milestone,
                                        $actor->id
                                    );
                                } catch (\RuntimeException $e) {
                                    // Log but don't fail the whole resolution
                                    AuditService::log(
                                        \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                                        \App\Models\AuditLog::MODULE_PAYMENTS,
                                        'Milestone', $milestone->id,
                                        ['error' => $e->getMessage()],
                                        $actor->id,
                                        "Failed to release payment for milestone \"{$milestone->title}\": {$e->getMessage()}"
                                    );
                                }
                            } elseif ($paymentAction === 'refund') {
                                // Refund the employer
                                $payment = Payment::where('milestone_id', $milestone->id)
                                    ->where('type', Payment::TYPE_ESCROW_FUNDED)
                                    ->where('status', Payment::STATUS_DISPUTED)
                                    ->first();

                                if ($payment) {
                                    try {
                                        PaymentService::approveRefund(
                                            $payment,
                                            $actor->id,
                                            null,
                                            'Dispute resolution: refund to employer'
                                        );
                                    } catch (\RuntimeException $e) {
                                        AuditService::log(
                                            \App\Models\AuditLog::ACTION_PAYMENT_REFUNDED,
                                            \App\Models\AuditLog::MODULE_PAYMENTS,
                                            'Payment', $payment->id,
                                            ['error' => $e->getMessage()],
                                            $actor->id,
                                            "Failed to refund payment {$payment->reference}: {$e->getMessage()}"
                                        );
                                    }
                                }
                            }
                        }
                    }

                    NotificationService::disputeResolved(
                        $contract->freelancer_id,
                        $contract->title,
                        $contract->id,
                        'freelancer',
                        $contractAction
                    );
                    NotificationService::disputeResolved(
                        $contract->employer_id,
                        $contract->title,
                        $contract->id,
                        'employer',
                        $contractAction
                    );
                }
            }

            // Audit log the dispute resolution.
            AuditService::disputeResolved($report->id, $actor->id, [
                'reason'          => $report->reason,
                'target_type'     => $report->target_type,
                'target_id'       => $report->target_id,
                'resolution'      => $request->input('resolution'),
                'contract_action' => $contractAction,
                'payment_action'  => $paymentAction,
            ]);
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report resolved successfully.');
    }

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

            if ($report->target_type === 'contract') {
                $contract = Contract::find($report->target_id);
                if ($contract && $contract->status === 'disputed') {
                    $contract->update(['status' => 'active']);

                    NotificationService::disputeResolved(
                        $contract->freelancer_id,
                        $contract->title,
                        $contract->id,
                        'freelancer',
                        'active'
                    );
                    NotificationService::disputeResolved(
                        $contract->employer_id,
                        $contract->title,
                        $contract->id,
                        'employer',
                        'active'
                    );
                }
            }

            // Audit log the dismissal.
            AuditService::disputeDismissed($report->id, $actor->id, [
                'reason'      => $report->reason,
                'target_type' => $report->target_type,
                'target_id'   => $report->target_id,
            ]);
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report dismissed successfully.');
    }

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
