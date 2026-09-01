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
        // Use platform_fee (set at release time by our workflow). Fall back to fee column
        // for legacy seed data where only the fee column was populated.
        $totalPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->sum('platform_fee');

        // If no platform_fee records, fall back to legacy fee column
        if ($totalPlatformRevenue == 0) {
            $totalPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('fee', '>', 0)
                ->sum('fee');
        }

        $monthlyPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('platform_fee');

        if ($monthlyPlatformRevenue == 0) {
            $monthlyPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('fee', '>', 0)
                ->where('processed_at', '>=', $startOfMonth)
                ->sum('fee');
        }

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

        // Monthly revenue breakdown (last 6 months)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = $now->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $now->copy()->subMonths($i)->endOfMonth();

            $monthPaymentVolume = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->whereBetween('processed_at', [$monthStart, $monthEnd])
                ->sum('amount');

            $monthPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('platform_fee', '>', 0)
                ->whereBetween('processed_at', [$monthStart, $monthEnd])
                ->sum('platform_fee');

            if ($monthPlatformRevenue == 0) {
                $monthPlatformRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
                    ->where('type', Payment::TYPE_ESCROW_FUNDED)
                    ->where('fee', '>', 0)
                    ->whereBetween('processed_at', [$monthStart, $monthEnd])
                    ->sum('fee');
            }

            $monthReleased = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_MILESTONE_RELEASED)
                ->whereBetween('processed_at', [$monthStart, $monthEnd])
                ->sum('amount');

            $monthlyRevenue[] = [
                'month'             => $monthStart->format('M'),
                'full_month'        => $monthStart->format('F Y'),
                'payment_volume'    => (float) $monthPaymentVolume,
                'platform_revenue'  => (float) $monthPlatformRevenue,
                'released_funds'    => (float) $monthReleased,
            ];
        }

        return $this->sendResponse([
            'payment_volume' => [
                'total'   => (float) $totalPaymentVolume,
                'monthly' => (float) $monthlyPaymentVolume,
            ],
            'platform_revenue' => [
                'total'   => (float) $totalPlatformRevenue,
                'monthly' => (float) $monthlyPlatformRevenue,
            ],
            'monthly_breakdown' => $monthlyRevenue,
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
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $to = $request->input('date_to') . ' 23:59:59';
            $query->where('created_at', '<=', $to);
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
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $to = $request->input('date_to') . ' 23:59:59';
            $query->where('created_at', '<=', $to);
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
