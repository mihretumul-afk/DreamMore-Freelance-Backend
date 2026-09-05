<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\FeaturedJob;
use App\Models\FeaturedProfile;
use App\Models\Payment;
use App\Models\PlatformDeposit;
use App\Models\PlatformWithdrawal;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\PlatformFinanceService;
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
     * Delete a withdrawal record (admin).
     *
     * Soft-deletes the row so it disappears from the finance history. Active
     * (requested/processing) withdrawals are protected because funds are still
     * reserved — approve/complete them first.
     */
    public function deleteWithdrawal(Request $request, Withdrawal $withdrawal): JsonResponse
    {
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
     * Delete a payment record (admin).
     *
     * Soft-deletes the row so it disappears from the admin finance lists.
     * Completed escrow payments whose milestone is still in progress are
     * protected, since the release/refund flow still depends on them.
     */
    public function deletePayment(Request $request, Payment $payment): JsonResponse
    {
        // Protect active escrow payments (funds currently held for a live milestone).
        if (
            $payment->type === Payment::TYPE_ESCROW_FUNDED
            && $payment->status === Payment::STATUS_COMPLETED
            && $payment->milestone
            && in_array($payment->milestone->status, [
                \App\Models\Milestone::STATUS_FUNDED,
                \App\Models\Milestone::STATUS_IN_PROGRESS,
                \App\Models\Milestone::STATUS_SUBMITTED,
                \App\Models\Milestone::STATUS_IN_REVIEW,
                \App\Models\Milestone::STATUS_REVISION,
                \App\Models\Milestone::STATUS_APPROVED,
                \App\Models\Milestone::STATUS_DISPUTED,
            ])
        ) {
            return $this->sendError(
                'Cannot delete this payment while its milestone escrow is still active.',
                [],
                422
            );
        }

        $payment->delete();

        return $this->sendResponse(null, 'Payment history entry deleted.');
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
     * Sends money from the platform balance to the designated bank account
     * via the payment provider (Chapa transfers), exactly like freelancer
     * withdrawals. The available balance only decreases once the transfer
     * succeeds; failed transfers stay pending/failed and are not deducted.
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

        $service = app(PlatformFinanceService::class);

        try {
            $withdrawal = $service->requestWithdrawal(
                $request->user()->id,
                (float) $validated['amount'],
                [
                    'account_name'   => $validated['account_name'],
                    'account_number' => $validated['account_number'],
                    'bank_name'      => $validated['bank_name'],
                    'bank_code'      => $validated['bank_code'] ?? null,
                ],
                $validated['description'] ?? 'Platform revenue withdrawal'
            );

            if ($withdrawal->status === PlatformWithdrawal::STATUS_FAILED) {
                return $this->sendError(
                    $withdrawal->failure_reason ?: 'Withdrawal failed. Please check the bank details and try again.',
                    [],
                    422
                );
            }

            $summary = $service->summary();

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
                'available_revenue' => $summary['available_revenue'],
            ], 'Platform revenue withdrawn successfully.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (\Exception $e) {
            return $this->sendError('Failed to process withdrawal: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Add funds to the platform (admin).
     *
     * Initiates a Chapa-hosted checkout (mirroring freelancer/employer Add
     * Funds). The platform balance is credited only after the payment is
     * confirmed by the provider (webhook) or by reconciliation. In sandbox
     * mode the charge is confirmed synchronously and credited immediately.
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

        $service = app(PlatformFinanceService::class);

        // Chapa redirects the admin back after checkout — use the origin the
        // admin actually opened the page from so the return URL always works.
        $returnUrlOrigin = $request->header('Origin') ?: $request->header('Referer');

        try {
            $result = $service->initiateDeposit(
                $request->user()->id,
                (float) $validated['amount'],
                $validated['description'],
                $validated['source'] ?? null,
                $returnUrlOrigin ?: null
            );

            $deposit = $result['deposit'];
            $summary = $service->summary();

            $response = [
                'deposit' => [
                    'id'             => $deposit->id,
                    'reference'      => $deposit->reference,
                    'amount'         => (float) $deposit->amount,
                    'status'         => $deposit->status,
                    'description'    => $deposit->description,
                    'failure_reason' => $deposit->failure_reason,
                    'completed_at'   => $deposit->completed_at?->toIso8601String(),
                ],
                'available_revenue' => $summary['available_revenue'],
            ];

            if (!empty($result['checkout_url'])) {
                $response['checkout_url'] = $result['checkout_url'];
                $response['deposit']['status'] = 'pending';
            }

            $message = !empty($result['checkout_url'])
                ? 'Redirect to Chapa checkout to complete the deposit.'
                : 'Funds added to platform successfully.';

            return $this->sendResponse($response, $message, 200);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (\Exception $e) {
            return $this->sendError('Failed to add funds: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Reconcile a specific platform deposit by reference.
     *
     * POST /api/v1/admin/finance/add-funds/reconcile/{reference}
     *
     * Called by the frontend after returning from Chapa checkout.
     */
    public function reconcileDeposit(Request $request, string $reference): JsonResponse
    {
        $deposit = PlatformDeposit::where('reference', $reference)
            ->where('deposited_by', $request->user()->id)
            ->first();

        if (!$deposit) {
            return $this->sendError('Platform deposit not found.', [], 404);
        }

        try {
            $service = app(PlatformFinanceService::class);
            $status = $service->getDepositStatus($deposit, $request->user()->id);

            return $this->sendResponse($status, 'Platform deposit status retrieved.');
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Reconcile ALL pending platform deposits for the current admin.
     *
     * POST /api/v1/admin/finance/add-funds/reconcile-all
     *
     * Called by the frontend on page load to ensure any completed but
     * uncredited deposits get processed.
     */
    public function reconcileAllDeposits(Request $request): JsonResponse
    {
        try {
            $service = app(PlatformFinanceService::class);
            $result = $service->reconcilePendingDeposits($request->user()->id);

            return $this->sendResponse($result, 'Platform deposit reconciliation complete.');
        } catch (\Exception $e) {
            return $this->sendError('Reconciliation failed: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * List banks supported by the payment provider for payouts.
     *
     * Used by the admin Withdraw modal so the correct Chapa bank code is
     * sent with the transfer (Chapa rejects arbitrary bank codes).
     *
     * GET /api/v1/admin/finance/banks
     */
    public function banks(): JsonResponse
    {
        $service = app(PlatformFinanceService::class);

        return $this->sendResponse(
            ['banks' => $service->listBanks()],
            'Supported payout banks retrieved.'
        );
    }

    /**
     * Get platform finance summary for the revenue management cards.
     *
     * GET /api/v1/admin/finance/platform-revenue
     */
    public function getPlatformRevenue(Request $request): JsonResponse
    {
        $service = app(PlatformFinanceService::class);
        $summary = $service->summary();

        // Recent withdrawals (all statuses)
        $recentWithdrawals = PlatformWithdrawal::orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($w) => [
                'id'                => $w->id,
                'reference'         => $w->reference,
                'amount'            => (float) $w->amount,
                'status'            => $w->status,
                'account_name'      => $w->account_name,
                'bank_name'         => $w->bank_name,
                'failure_reason'    => $w->failure_reason,
                'completed_at'      => $w->completed_at?->toIso8601String(),
                'created_at'        => $w->created_at?->toIso8601String(),
            ]);

        // Recent deposits (all statuses)
        $recentDeposits = PlatformDeposit::orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($d) => [
                'id'             => $d->id,
                'reference'      => $d->reference,
                'amount'         => (float) $d->amount,
                'status'         => $d->status,
                'description'    => $d->description,
                'failure_reason' => $d->failure_reason,
                'completed_at'   => $d->completed_at?->toIso8601String(),
                'created_at'     => $d->created_at?->toIso8601String(),
            ]);

        return $this->sendResponse([
            'milestone_fees'      => $summary['milestone_fees'],
            'featured_revenue'    => $summary['featured_revenue'],
            'total_revenue'       => $summary['total_revenue'],
            'total_deposits'      => $summary['total_deposits'],
            'total_withdrawn'     => $summary['total_withdrawn'],
            'pending_withdrawals' => $summary['pending_withdrawals'],
            'available_revenue'   => $summary['available_revenue'],
            'recent_withdrawals'  => $recentWithdrawals,
            'recent_deposits'     => $recentDeposits,
        ], 'Platform finance summary retrieved.');
    }
}
