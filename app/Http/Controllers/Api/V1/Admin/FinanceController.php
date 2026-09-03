<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\FeaturedJob;
use App\Models\FeaturedProfile;
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

        // ── Featured Listing Revenue ──────────────────────────────────
        // Featured job fees (completed transactions only)
        $featuredJobRevenue = Transaction::where('type', Transaction::TYPE_FEATURED_JOB_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount');

        $monthlyFeaturedJobRevenue = Transaction::where('type', Transaction::TYPE_FEATURED_JOB_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $activeFeaturedJobs = FeaturedJob::where('status', FeaturedJob::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->count();

        // Featured profile fees (completed transactions only)
        $featuredProfileRevenue = Transaction::where('type', Transaction::TYPE_FEATURED_PROFILE_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount');

        $monthlyFeaturedProfileRevenue = Transaction::where('type', Transaction::TYPE_FEATURED_PROFILE_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $activeFeaturedProfiles = FeaturedProfile::where('status', FeaturedProfile::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->count();

        $totalFeaturedRevenue = (float) $featuredJobRevenue + (float) $featuredProfileRevenue;
        $monthlyFeaturedRevenue = (float) $monthlyFeaturedJobRevenue + (float) $monthlyFeaturedProfileRevenue;

        return $this->sendResponse([
            'payment_volume' => [
                'total'   => (float) $totalPaymentVolume,
                'monthly' => (float) $monthlyPaymentVolume,
            ],
            'platform_revenue' => [
                'total'   => (float) $totalPlatformRevenue,
                'monthly' => (float) $monthlyPlatformRevenue,
            ],
            'featured_revenue' => [
                'total'          => $totalFeaturedRevenue,
                'monthly'        => $monthlyFeaturedRevenue,
                'jobs_total'     => (float) $featuredJobRevenue,
                'jobs_monthly'   => (float) $monthlyFeaturedJobRevenue,
                'jobs_active'    => $activeFeaturedJobs,
                'profiles_total' => (float) $featuredProfileRevenue,
                'profiles_monthly' => (float) $monthlyFeaturedProfileRevenue,
                'profiles_active'  => $activeFeaturedProfiles,
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

    /**
     * Withdraw platform revenue (admin).
     *
     * Transfers all available platform revenue (collected from milestone fees
     * and featured listing fees) to the admin's designated bank account.
     *
     * POST /api/v1/admin/finance/withdraw-revenue
     */
    public function withdrawPlatformRevenue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'          => 'required|numeric|min:1',
            'account_name'    => 'required|string|max:255',
            'account_number'  => 'required|string|max:255',
            'bank_name'       => 'required|string|max:255',
            'bank_code'       => 'nullable|string|max:50',
            'description'     => 'nullable|string|max:500',
        ]);

        $amount = (float) $validated['amount'];

        // Calculate available platform revenue
        $totalPlatformRevenue = $this->calculatePlatformRevenue();
        $totalWithdrawn = $this->calculatePlatformWithdrawals();
        $availableRevenue = $totalPlatformRevenue - $totalWithdrawn;

        if ($amount > $availableRevenue) {
            return $this->sendError(
                "Insufficient platform revenue. Available: " . number_format($availableRevenue, 2) . " ETB.",
                [], 422
            );
        }

        try {
            $withdrawal = DB::transaction(function () use ($validated, $amount, $request) {
                // Create platform withdrawal record
                $withdrawal = \App\Models\PlatformWithdrawal::create([
                    'reference'       => 'PLAT-WTH-' . strtoupper(uniqid()),
                    'amount'          => $amount,
                    'currency'        => 'ETB',
                    'status'          => 'completed',
                    'account_name'    => $validated['account_name'],
                    'account_number'  => $validated['account_number'],
                    'bank_name'       => $validated['bank_name'],
                    'bank_code'       => $validated['bank_code'] ?? null,
                    'description'     => $validated['description'] ?? 'Platform revenue withdrawal',
                    'processed_by'    => $request->user()->id,
                    'completed_at'    => now(),
                ]);

                // Record audit log
                \App\Services\AuditService::platformWithdrawal($withdrawal->id, $request->user()->id, [
                    'reference'     => $withdrawal->reference,
                    'amount'        => $amount,
                    'currency'      => 'ETB',
                    'account_name'  => $validated['account_name'],
                    'bank_name'     => $validated['bank_name'],
                ]);

                // Broadcast finance update
                \App\Events\FinanceUpdated::dispatch('platform_withdrawal', [
                    'withdrawal_id'  => $withdrawal->id,
                    'reference'      => $withdrawal->reference,
                    'amount'         => $amount,
                    'account_name'   => $validated['account_name'],
                    'bank_name'      => $validated['bank_name'],
                ], $request->user()->id);

                return $withdrawal;
            });

            return $this->sendResponse([
                'withdrawal' => [
                    'id'           => $withdrawal->id,
                    'reference'    => $withdrawal->reference,
                    'amount'       => (float) $withdrawal->amount,
                    'status'       => $withdrawal->status,
                    'account_name' => $withdrawal->account_name,
                    'bank_name'    => $withdrawal->bank_name,
                    'completed_at' => $withdrawal->completed_at?->toIso8601String(),
                ],
                'available_revenue' => $availableRevenue - $amount,
            ], 'Platform revenue withdrawn successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to process withdrawal: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Add funds to platform wallet (admin).
     *
     * Manually credits the platform wallet with funds (e.g., for operational
     * expenses, promotional credits, or external deposits).
     *
     * POST /api/v1/admin/finance/add-funds
     */
    public function addPlatformFunds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'       => 'required|numeric|min:1|max:10000000',
            'description'  => 'required|string|max:500',
            'source'       => 'nullable|string|max:255',
        ]);

        $amount = (float) $validated['amount'];

        try {
            $deposit = DB::transaction(function () use ($validated, $amount, $request) {
                // Use the current admin user's wallet
                $adminId = $request->user()->id;
                $wallet = \App\Models\Wallet::forUser($adminId);

                // Credit the wallet
                $wallet->creditAvailable($amount);

                // Create deposit record
                $deposit = \App\Models\PlatformDeposit::create([
                    'reference'    => 'PLAT-DEP-' . strtoupper(uniqid()),
                    'amount'       => $amount,
                    'currency'     => 'ETB',
                    'status'       => 'completed',
                    'description'  => $validated['description'],
                    'source'       => $validated['source'] ?? 'Manual admin deposit',
                    'deposited_by' => $adminId,
                    'completed_at' => now(),
                ]);

                // Record ledger transaction
                \App\Models\Transaction::create([
                    'reference'      => \App\Models\Transaction::generateReference(),
                    'user_id'        => $adminId,
                    'wallet_id'      => $wallet->id,
                    'direction'      => \App\Models\Transaction::DIR_CREDIT,
                    'type'           => 'platform_deposit',
                    'amount'         => $amount,
                    'balance_before' => (float) $wallet->available_balance - $amount,
                    'balance_after'  => (float) $wallet->available_balance,
                    'currency'       => 'ETB',
                    'status'         => \App\Models\Transaction::STATUS_COMPLETED,
                    'description'    => $validated['description'],
                ]);

                // Record audit log
                \App\Services\AuditService::platformDeposit($deposit->id, $request->user()->id, [
                    'reference'   => $deposit->reference,
                    'amount'      => $amount,
                    'currency'    => 'ETB',
                    'description' => $validated['description'],
                ]);

                // Broadcast finance update
                \App\Events\FinanceUpdated::dispatch('platform_deposit', [
                    'deposit_id'   => $deposit->id,
                    'reference'    => $deposit->reference,
                    'amount'       => $amount,
                    'description'  => $validated['description'],
                ], $request->user()->id);

                return [
                    'deposit' => $deposit,
                    'wallet'  => $wallet->fresh(),
                ];
            });

            return $this->sendResponse([
                'deposit' => [
                    'id'          => $deposit['deposit']->id,
                    'reference'   => $deposit['deposit']->reference,
                    'amount'      => (float) $deposit['deposit']->amount,
                    'status'      => $deposit['deposit']->status,
                    'description' => $deposit['deposit']->description,
                    'completed_at' => $deposit['deposit']->completed_at?->toIso8601String(),
                ],
                'wallet' => [
                    'available_balance' => (float) $deposit['wallet']->available_balance,
                    'pending_balance'   => (float) $deposit['wallet']->pending_balance,
                ],
            ], 'Funds added to platform successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Failed to add funds: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Get platform revenue summary for withdrawal.
     *
     * GET /api/v1/admin/finance/platform-revenue
     */
    public function getPlatformRevenue(Request $request): JsonResponse
    {
        $totalPlatformRevenue = $this->calculatePlatformRevenue();
        $totalWithdrawn = $this->calculatePlatformWithdrawals();
        $availableRevenue = $totalPlatformRevenue - $totalWithdrawn;

        // Get recent withdrawals
        $recentWithdrawals = \App\Models\PlatformWithdrawal::orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($w) => [
                'id'           => $w->id,
                'reference'    => $w->reference,
                'amount'       => (float) $w->amount,
                'status'       => $w->status,
                'account_name' => $w->account_name,
                'bank_name'    => $w->bank_name,
                'completed_at' => $w->completed_at?->toIso8601String(),
            ]);

        // Get recent deposits
        $recentDeposits = \App\Models\PlatformDeposit::orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($d) => [
                'id'          => $d->id,
                'reference'   => $d->reference,
                'amount'      => (float) $d->amount,
                'status'      => $d->status,
                'description' => $d->description,
                'completed_at' => $d->completed_at?->toIso8601String(),
            ]);

        return $this->sendResponse([
            'total_revenue'      => $totalPlatformRevenue,
            'total_withdrawn'    => $totalWithdrawn,
            'available_revenue'  => max(0, $availableRevenue),
            'recent_withdrawals' => $recentWithdrawals,
            'recent_deposits'    => $recentDeposits,
        ], 'Platform revenue summary retrieved.');
    }

    /**
     * Calculate total platform revenue from milestone fees.
     */
    private function calculatePlatformRevenue(): float
    {
        // Milestone fees
        $milestoneFees = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->sum('platform_fee');

        // Featured listing fees
        $featuredFees = (float) Transaction::where('type', Transaction::TYPE_FEATURED_JOB_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount')
            + (float) Transaction::where('type', Transaction::TYPE_FEATURED_PROFILE_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount');

        return $milestoneFees + $featuredFees;
    }

    /**
     * Calculate total platform withdrawals.
     */
    private function calculatePlatformWithdrawals(): float
    {
        return (float) \App\Models\PlatformWithdrawal::where('status', 'completed')
            ->sum('amount');
    }
}
