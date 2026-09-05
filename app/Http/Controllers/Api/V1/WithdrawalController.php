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
     * Remove a withdrawal entry from the user's history.
     *
     * Only settled (completed/failed/cancelled/rejected) withdrawals can be
     * deleted. Active ones are protected because funds are still reserved.
     */
    public function destroy(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && $withdrawal->user_id !== $user->id) {
            return $this->sendForbidden('You do not have access to this withdrawal.');
        }

        if (!$withdrawal->isDeletable()) {
            return $this->sendError(
                'Only completed, failed, cancelled or rejected withdrawals can be deleted.',
                [],
                422
            );
        }

        $withdrawal->delete();

        return $this->sendResponse(null, 'Withdrawal history entry deleted.');
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

        // Calculate total withdrawn from withdrawals table (includes requested, processing, and completed)
        $totalWithdrawn = \App\Models\Withdrawal::where('user_id', $request->user()->id)
            ->whereIn('status', ['requested', 'processing', 'completed'])
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

    /**
     * Get employer's finance summary.
     */
    public function employerFinance(Request $request): JsonResponse
    {
        $user = $request->user();

        // Total spent on milestone funding (payments made by employer)
        $totalSpent = \App\Models\Payment::where('payer_id', $user->id)
            ->where('type', \App\Models\Payment::TYPE_ESCROW_FUNDED)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->sum('amount');

        // Total platform fees paid
        $totalPlatformFees = \App\Models\Payment::where('payer_id', $user->id)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->sum('platform_fee');

        // Total processing fees paid
        $totalProcessingFees = \App\Models\Payment::where('payer_id', $user->id)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->sum('processing_fee');

        // Pending payments (funded but not yet released)
        $pendingPayments = \App\Models\Payment::where('payer_id', $user->id)
            ->where('type', \App\Models\Payment::TYPE_ESCROW_FUNDED)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->whereHas('milestone', function ($q) {
                $q->whereIn('status', [
                    \App\Models\Milestone::STATUS_FUNDED,
                    \App\Models\Milestone::STATUS_IN_PROGRESS,
                    \App\Models\Milestone::STATUS_SUBMITTED,
                    \App\Models\Milestone::STATUS_IN_REVIEW,
                    \App\Models\Milestone::STATUS_REVISION,
                    \App\Models\Milestone::STATUS_APPROVED,
                    \App\Models\Milestone::STATUS_DISPUTED,
                ]);
            })
            ->sum('amount');

        // Monthly spending (this month only)
        $monthlySpent = \App\Models\Payment::where('payer_id', $user->id)
            ->where('type', \App\Models\Payment::TYPE_ESCROW_FUNDED)
            ->where('status', \App\Models\Payment::STATUS_COMPLETED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        // Budget remaining (monthly limit minus spent this month)
        $budget = \App\Models\EmployerBudget::where('user_id', $user->id)->first();
        $monthlyLimit = $budget ? (float) $budget->monthly_limit : 0;
        $budgetRemaining = $monthlyLimit > 0 ? max(0, $monthlyLimit - (float) $monthlySpent) : 0;

        // Get wallet balance (actual available funds)
        $wallet = \App\Models\Wallet::where('user_id', $user->id)->first();
        $walletBalance = $wallet ? (float) $wallet->available_balance : 0;

        return $this->sendResponse([
            'available_balance' => $walletBalance,
            'account_balance'   => $budgetRemaining,
            'monthly_limit'     => $monthlyLimit,
            'monthly_spent'     => (float) $monthlySpent,
            'pending_payments'  => (float) $pendingPayments,
            'total_spent'       => (float) $totalSpent,
            'total_fees'        => (float) ($totalPlatformFees + $totalProcessingFees),
            'currency'          => 'ETB',
        ], 'Employer finance retrieved.');
    }
}
