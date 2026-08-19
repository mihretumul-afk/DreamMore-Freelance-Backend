<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return $this->sendResponse($report, 'Report retrieved successfully.');
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be resolved.', [], 422);
        }

        $request->validate([
            'resolution' => 'nullable|string|max:2000',
        ]);

        $report->update([
            'status' => 'resolved',
            'resolution' => $request->input('resolution'),
            'resolved_at' => now(),
        ]);

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report resolved successfully.');
    }

    public function dismiss(Report $report): JsonResponse
    {
        if ($report->status !== 'pending') {
            return $this->sendError('Only pending reports can be dismissed.', [], 422);
        }

        $report->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
        ]);

        return $this->sendResponse($report->fresh()->load('reporter'), 'Report dismissed successfully.');
    }

    public function destroy(Report $report): JsonResponse
    {
        $report->delete();

        return $this->sendResponse(null, 'Report deleted successfully.');
    }
}
