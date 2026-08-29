<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\PaymentService;
use App\Services\Payment\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends BaseApiController
{
    /**
     * List user's withdrawals.
     */
    public function index(Request $request): JsonResponse
    {
        $withdrawals = $request->user()
            ->withdrawals()
            ->orderByDesc('created_at')
            ->paginate(15);

        return $this->sendResponse(
            $withdrawals->items(),
            'Withdrawals retrieved.',
            200,
            [
                'current_page' => $withdrawals->currentPage(),
                'last_page'    => $withdrawals->lastPage(),
                'total'        => $withdrawals->total(),
            ]
        );
    }

    /**
     * Request a withdrawal.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'            => 'required|numeric|min:1|max:999999.99',
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        $paymentMethod = $request->user()->paymentMethods()->find($validated['payment_method_id']);
        if (!$paymentMethod) {
            return $this->sendForbidden('This payment method does not belong to you.');
        }

        try {
            $service = app(WithdrawalService::class);
            $withdrawal = $service->request(
                $request->user()->id,
                $validated['amount'],
                $validated['payment_method_id']
            );

            $fee = (float) $withdrawal->fee;
            $netAmount = (float) $withdrawal->net_amount;

            return $this->sendResponse([
                'id'         => $withdrawal->id,
                'reference'  => $withdrawal->reference,
                'amount'     => (float) $withdrawal->amount,
                'fee'        => $fee,
                'net_amount' => $netAmount,
                'status'     => $withdrawal->status,
            ], 'Withdrawal request submitted successfully.', 201);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Cancel a pending withdrawal.
     */
    public function cancel(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->user_id !== $request->user()->id) {
            return $this->sendForbidden('You do not have access to this withdrawal.');
        }

        try {
            $service = app(WithdrawalService::class);
            $service->cancel($withdrawal, $request->user()->id);

            return $this->sendResponse(null, 'Withdrawal cancelled successfully.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Get user's wallet/earnings summary.
     */
    public function earnings(Request $request): JsonResponse
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['currency' => 'ETB']
        );

        $fee = WithdrawalService::calculateFee((float) $wallet->available_balance);

        // Calculate totals from transactions
        $totalWithdrawn = \App\Models\Transaction::where('user_id', $request->user()->id)
            ->where('type', \App\Models\Transaction::TYPE_WITHDRAWAL)
            ->where('direction', \App\Models\Transaction::DIR_DEBIT)
            ->sum('amount');

        $totalEarnings = \App\Models\Payment::where('payee_id', $request->user()->id)
            ->where('type', \App\Models\Payment::TYPE_MILESTONE_RELEASED)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->sum('amount');

        return $this->sendResponse([
            'available_balance' => (float) $wallet->available_balance,
            'pending_balance'   => (float) $wallet->pending_balance,
            'total_earned'      => (float) $totalEarnings,
            'total_withdrawn'   => (float) $totalWithdrawn,
            'currency'          => $wallet->currency,
            'estimated_fee'     => $fee,
        ], 'Earnings retrieved.');
    }
}
