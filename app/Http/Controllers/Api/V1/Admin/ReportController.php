<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Contract;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Report;
use App\Services\NotificationService;
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
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
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

        $data = $report->toArray();
        $data['target'] = $target;

        return $this->sendResponse($data, 'Report retrieved successfully.');
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be resolved.', [], 422);
        }

        $request->validate([
            'resolution' => 'nullable|string|max:2000',
            'contract_action' => 'nullable|in:active,completed,cancelled',
        ]);

        $contractAction = $request->input('contract_action', 'active');

        DB::transaction(function () use ($report, $request, $contractAction) {
            $report->update([
                'status' => 'resolved',
                'resolution' => $request->input('resolution'),
                'resolved_at' => now(),
            ]);

            if ($report->target_type === 'contract') {
                $contract = Contract::find($report->target_id);
                if ($contract) {
                    $contract->update([
                        'status' => $contractAction,
                        'end_date' => in_array($contractAction, ['completed', 'cancelled'], true) ? now() : $contract->end_date,
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

                    // Notify both participants
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
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report resolved successfully.');
    }

    public function dismiss(Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be dismissed.', [], 422);
        }

        DB::transaction(function () use ($report) {
            $report->update([
                'status' => 'dismissed',
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
        });

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report dismissed successfully.');
    }

    public function destroy(Report $report): JsonResponse
    {
        $report->delete();

        return $this->sendResponse(null, 'Report deleted successfully.');
    }
}
