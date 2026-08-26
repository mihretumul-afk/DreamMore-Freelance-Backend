<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WithdrawalController — freelancer-facing withdrawal endpoints.
 */
class WithdrawalController extends BaseApiController
{
    /**
     * GET /withdrawals
     * List the authenticated freelancer's withdrawals.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Withdrawal::where('user_id', $user->id)
            ->with(['paymentMethod:id,type,display_label'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $withdrawals = $query->paginate(15);

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
     * GET /withdrawals/{withdrawal}
     * Show a single withdrawal detail.
     */
    public function show(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not have access to this withdrawal.');
        }

        $withdrawal->load(['paymentMethod:id,type,display_label']);

        return $this->sendResponse($withdrawal, 'Withdrawal retrieved.');
    }

    /**
     * POST /withdrawals
     * Request a new withdrawal.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'            => ['required', 'numeric', 'min:1'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ]);

        try {
            $withdrawal = WithdrawalService::requestWithdrawal(
                $request->user()->id,
                (float) $validated['amount'],
                $validated['payment_method_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse(
            $withdrawal->load('paymentMethod:id,type,display_label'),
            'Withdrawal request submitted.',
            201
        );
    }

    /**
     * POST /withdrawals/{withdrawal}/cancel
     * Cancel a pending withdrawal request.
     */
    public function cancel(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        try {
            $cancelled = WithdrawalService::cancelWithdrawal($withdrawal, $request->user()->id);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }

        return $this->sendResponse($cancelled, 'Withdrawal cancelled.');
    }
}
