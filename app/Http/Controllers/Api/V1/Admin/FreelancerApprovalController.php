<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreelancerApprovalController extends BaseApiController
{
    /**
     * GET /api/v1/admin/freelancers
     *
     * List all freelancers with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = FreelancerProfile::with(['user', 'skills'])
            ->whereHas('user', fn ($q) => $q->where('role', 'freelancer'));

        if ($request->filled('status')) {
            $query->where('approval_status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('headline', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $freelancers = $query->orderByDesc('created_at')->paginate(15);

        return $this->sendResponse(
            $freelancers->items(),
            'Freelancers retrieved successfully.',
            200,
            [
                'current_page' => $freelancers->currentPage(),
                'last_page' => $freelancers->lastPage(),
                'per_page' => $freelancers->perPage(),
                'total' => $freelancers->total(),
            ]
        );
    }

    /**
     * GET /api/v1/admin/freelancers/{freelancer}
     *
     * View a single freelancer's profile and approval status.
     */
    public function show(FreelancerProfile $freelancer): JsonResponse
    {
        $freelancer->load(['user', 'skills']);

        return $this->sendResponse($freelancer, 'Freelancer retrieved successfully.');
    }

    /**
     * PUT /api/v1/admin/freelancers/{freelancer}/approve
     *
     * Approve a freelancer for marketplace visibility.
     */
    public function approve(FreelancerProfile $freelancer): JsonResponse
    {
        if ($freelancer->approval_status === 'approved') {
            return $this->sendError('This freelancer is already approved.', [], 422);
        }

        $freelancer->approve();

        // Notify the freelancer
        NotificationService::freelancerApproved($freelancer->user_id);

        $freelancer->load(['user', 'skills']);

        return $this->sendResponse($freelancer, 'Freelancer approved successfully.');
    }

    /**
     * PUT /api/v1/admin/freelancers/{freelancer}/reject
     *
     * Reject a freelancer's application with an optional reason.
     */
    public function reject(Request $request, FreelancerProfile $freelancer): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($freelancer->approval_status === 'rejected') {
            return $this->sendError('This freelancer is already rejected.', [], 422);
        }

        $freelancer->reject($request->input('reason'));

        // Notify the freelancer
        NotificationService::freelancerRejected(
            $freelancer->user_id,
            $request->input('reason')
        );

        $freelancer->load(['user', 'skills']);

        return $this->sendResponse($freelancer, 'Freelancer rejected successfully.');
    }
}
