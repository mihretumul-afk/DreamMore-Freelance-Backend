<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin WithdrawalController — finance admin management of withdrawal requests.
 */
class WithdrawalController extends BaseApiController
{
    /**
     * GET /admin/withdrawals
     * List all withdrawal requests with filters.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.view')) {
            return $this->sendForbidden('You do not have permission to view withdrawals.');
        }

        $query = Withdrawal::with([
            'user:id,name,email',
            'paymentMethod:id,type,display_label',
            'processor:id,name,email',
        ])->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('reference', 'like', "%{$s}%")
                  ->orWhereHas('user', fn ($q2) => $q2->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
            });
        }

        $withdrawals = $query->paginate(20);

        return $this->sendResponse(
            $withdrawals->items(),
            'Withdrawals retrieved.',
            200,
            [
                'current_page' => $withdrawals->currentPage(),
                'last_page'    => $withdrawals->lastPage(),
                'per_page'     => $withdrawals->perPage(),
                'total'        => $withdrawals->total(),
            ]
        );
    }

    /**
     * GET /admin/withdrawals/{withdrawal}
     * Show a single withdrawal detail.
     */
    public function show(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.view')) {
            return $this->sendForbidden('You do not have permission to view withdrawals.');
        }

        $withdrawal->load([
            'user:id,name,email,role',
            'paymentMethod:id,type,display_label',
            'processor:id,name,email',
        ]);

        return $this->sendResponse($withdrawal, 'Withdrawal retrieved.');
    }

    /**
     * PUT /admin/withdrawals/{withdrawal}/process
     * Process a pending withdrawal.
     */
    public function process(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.manage')) {
            return $this->sendForbidden('You do not have permission to process withdrawals.');
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $processed = WithdrawalService::processWithdrawal(
                $withdrawal,
                $request->user()->id,
                $validated['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($processed, 'Withdrawal processing initiated.');
    }

    /**
     * PUT /admin/withdrawals/{withdrawal}/complete
     * Mark a withdrawal as completed.
     */
    public function complete(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.manage')) {
            return $this->sendForbidden('You do not have permission to complete withdrawals.');
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $completed = WithdrawalService::completeWithdrawal(
                $withdrawal,
                $request->user()->id,
                $validated['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($completed, 'Withdrawal completed.');
    }

    /**
     * PUT /admin/withdrawals/{withdrawal}/reject
     * Reject a withdrawal request.
     */
    public function reject(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.manage')) {
            return $this->sendForbidden('You do not have permission to reject withdrawals.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $rejected = WithdrawalService::rejectWithdrawal(
                $withdrawal,
                $validated['reason'],
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($rejected, 'Withdrawal rejected.');
    }

    /**
     * PUT /admin/withdrawals/{withdrawal}/fail
     * Mark a withdrawal as failed.
     */
    public function fail(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if (!$request->user()->hasPermission('withdrawals.manage')) {
            return $this->sendForbidden('You do not have permission to manage withdrawals.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $failed = WithdrawalService::failWithdrawal(
                $withdrawal,
                $validated['reason'],
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($failed, 'Withdrawal marked as failed.');
    }
}
