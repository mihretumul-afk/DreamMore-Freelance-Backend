<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends BaseApiController
{
    /**
     * Admin finance dashboard — aggregate financial statistics.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();

        // Payment volume
        $totalPaymentVolume = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->sum('amount');

        $monthlyPaymentVolume = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('amount');

        // Platform revenue (fees collected)
        $totalPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->sum('fee');

        $monthlyPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('fee');

        // Held funds (funded escrow remaining after release or refund)
        $releasedSum = (float) DB::table('payments')
            ->where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->sum('amount');

        $refundedSum = (float) DB::table('payments')
            ->where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_REFUND)
            ->sum('amount');

        $heldFunds = (float) DB::table('payments')
            ->where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->sum('net_amount') - $releasedSum - $refundedSum;

        // Released funds
        $releasedFunds = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->sum('amount');

        // Refunds
        $totalRefunds = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_REFUND)
            ->sum('amount');

        // Withdrawals
        $pendingWithdrawals = Withdrawal::where('status', Withdrawal::STATUS_REQUESTED)
            ->count();

        $completedWithdrawals = Withdrawal::where('status', Withdrawal::STATUS_COMPLETED)
            ->sum('amount');

        $failedPayments = Payment::where('status', Payment::STATUS_FAILED)
            ->count();

        // Disputed funds (escrow amount for currently disputed milestones)
        $disputedFunds = (float) DB::table('payments')
            ->join('milestones', 'payments.milestone_id', '=', 'milestones.id')
            ->where('payments.status', Payment::STATUS_COMPLETED)
            ->where('payments.type', Payment::TYPE_ESCROW_FUNDED)
            ->where('milestones.status', 'disputed')
            ->sum('payments.amount');

        // Wallets
        $totalAvailableBalance = Wallet::sum('available_balance');
        $totalPendingBalance = Wallet::sum('pending_balance');

        return $this->sendResponse([
            'payment_volume' => [
                'total'   => (float) $totalPaymentVolume,
                'monthly' => (float) $monthlyPaymentVolume,
            ],
            'platform_revenue' => [
                'total'   => (float) $totalPlatformRevenue,
                'monthly' => (float) $monthlyPlatformRevenue,
            ],
            'held_funds'       => (float) max(0, $heldFunds),
            'released_funds'   => (float) $releasedFunds,
            'total_refunds'    => (float) $totalRefunds,
            'withdrawals' => [
                'pending'   => $pendingWithdrawals,
                'completed' => (float) $completedWithdrawals,
            ],
            'failed_payments'  => $failedPayments,
            'disputed_funds'   => (float) $disputedFunds,
            'wallets' => [
                'total_available'    => (float) $totalAvailableBalance,
                'total_pending'      => (float) $totalPendingBalance,
            ],
        ], 'Finance dashboard data retrieved.');
    }

    /**
     * List all payments (admin view).
     */
    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with(['payer', 'payee', 'milestone']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $payments = $query->orderByDesc('created_at')->paginate(20);

        return $this->sendResponse(
            $payments->items(),
            'Payments retrieved.',
            200,
            [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'total'        => $payments->total(),
            ]
        );
    }

    /**
     * List all withdrawals (admin view).
     */
    public function withdrawals(Request $request): JsonResponse
    {
        $query = Withdrawal::with(['user', 'paymentMethod']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $withdrawals = $query->orderByDesc('created_at')->paginate(20);

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
     * Approve a pending withdrawal (admin).
     */
    public function approveWithdrawal(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== Withdrawal::STATUS_REQUESTED) {
            return $this->sendError('Only pending withdrawals can be approved.', [], 422);
        }

        try {
            $service = app(\App\Services\Payment\WithdrawalService::class);
            $service->process($withdrawal);

            return $this->sendResponse(null, 'Withdrawal approved and processing.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }
}
