<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PlatformDeposit;
use App\Models\PlatformWithdrawal;
use App\Models\Transaction;
use App\Models\WebhookLog;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlatformFinanceService — manages the platform's own funds.
 *
 * The platform available balance is tracked as a single derived number:
 *
 *   collected platform fees (milestone escrow + featured listings)
 *   + completed platform deposits  (money paid IN via Chapa checkout, like users' Add Funds)
 *   − completed platform withdrawals (money paid OUT via Chapa transfers, like users' withdrawals)
 *   − pending platform withdrawals   (reserved while a transfer is processing)
 *
 * Both Add Funds and Withdraw Revenue flow through the configured payment
 * provider (Chapa in production, sandbox in development) exactly like the
 * freelancer/employer wallet flows so balances only move on provider confirmation.
 */
class PlatformFinanceService
{
    private PaymentProviderInterface $provider;

    /** Maximum amount for a single platform deposit (ETB). */
    public const DEPOSIT_MAX = 10000000;

    public function __construct(PaymentProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Breakdown of collected platform fees (milestone escrow fees vs featured fees).
     */
    public static function revenueBreakdown(): array
    {
        $milestoneFees = (float) Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->sum('platform_fee');

        $featuredFees = (float) Transaction::where('type', Transaction::TYPE_FEATURED_JOB_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount')
            + (float) Transaction::where('type', Transaction::TYPE_FEATURED_PROFILE_FEE)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->sum('amount');

        return [
            'milestone_fees'  => round($milestoneFees, 2),
            'featured_revenue' => round($featuredFees, 2),
        ];
    }

    /**
     * Total platform fees ever collected (milestone escrow + featured listings).
     */
    public static function revenueFromFees(): float
    {
        $breakdown = self::revenueBreakdown();

        return $breakdown['milestone_fees'] + $breakdown['featured_revenue'];
    }

    /**
     * Current platform funds summary.
     *
     * @return array{milestone_fees: float, featured_revenue: float, total_revenue: float, total_deposits: float, total_withdrawn: float, pending_withdrawals: float, available_revenue: float}
     */
    public function summary(): array
    {
        $breakdown = self::revenueBreakdown();
        $milestoneFees = $breakdown['milestone_fees'];
        $featuredRevenue = $breakdown['featured_revenue'];
        $totalRevenue = $milestoneFees + $featuredRevenue;
        $totalDeposits = (float) PlatformDeposit::where('status', PlatformDeposit::STATUS_COMPLETED)->sum('amount');
        $totalWithdrawn = (float) PlatformWithdrawal::where('status', PlatformWithdrawal::STATUS_COMPLETED)->sum('amount');
        $pendingWithdrawals = (float) PlatformWithdrawal::where('status', PlatformWithdrawal::STATUS_PENDING)->sum('amount');

        $available = $totalRevenue + $totalDeposits - $totalWithdrawn - $pendingWithdrawals;

        return [
            'milestone_fees'      => $milestoneFees,
            'featured_revenue'    => $featuredRevenue,
            'total_revenue'       => round($totalRevenue, 2),
            'total_deposits'      => round($totalDeposits, 2),
            'total_withdrawn'     => round($totalWithdrawn, 2),
            'pending_withdrawals' => round($pendingWithdrawals, 2),
            'available_revenue'   => round(max(0, $available), 2),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // DEPOSITS (Add Funds via Chapa checkout — mirrors AddFundsService)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Initiate a platform deposit.
     *
     * 1. Validate amount
     * 2. Create a pending PlatformDeposit record
     * 3. Initialize a charge with the provider
     * 4. Redirect-based provider (Chapa): leave pending and return checkout_url
     * 5. Synchronous provider (sandbox): confirm immediately
     *
     * @param string|null $returnUrlOrigin Frontend origin (scheme://host[:port]) to
     *                                     send the user back to after checkout.
     * @return array{deposit: PlatformDeposit, checkout_url: string|null}
     */
    public function initiateDeposit(int $adminId, float $amount, string $description, ?string $source = null, ?string $returnUrlOrigin = null): array
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Deposit amount must be greater than zero.');
        }

        if ($amount > self::DEPOSIT_MAX) {
            throw new \RuntimeException('Deposit amount exceeds the maximum allowed.');
        }

        $reference = 'PLAT-DEP-' . strtoupper(uniqid());

        $deposit = DB::transaction(function () use ($adminId, $amount, $description, $source, $reference) {
            return PlatformDeposit::create([
                'reference'    => $reference,
                'amount'       => $amount,
                'currency'     => 'ETB',
                'status'       => PlatformDeposit::STATUS_PENDING,
                'description'  => $description,
                'source'       => $source ?? 'Chapa deposit',
                'provider'     => $this->provider->getName(),
                'deposited_by' => $adminId,
            ]);
        });

        // Return to the SAME origin the admin opened the page from, so the
        // checkout redirect never points at a hardcoded localhost:5173 that
        // may not match where the admin is actually running the frontend.
        $frontendOrigin = self::normalizeOrigin($returnUrlOrigin)
            ?? config('app.frontend_url', 'http://localhost:5173');
        $returnUrlBase = rtrim((string) $frontendOrigin, '/') . '/admin/finance';

        $result = $this->provider->charge($amount, 'ETB', $reference, [
            'user_id'    => $adminId,
            'deposit_id' => $deposit->id,
            'type'       => 'platform_deposit',
            'return_url' => $returnUrlBase,
        ]);

        if (!$result['success']) {
            $this->failDeposit($deposit, $result['error'] ?? 'Deposit failed');
            throw new \RuntimeException($result['error'] ?? 'Deposit failed. Please try again.');
        }

        $checkoutUrl = $result['checkout_url'] ?? null;
        $providerReference = $result['provider_reference'] ?? $reference;

        if ($checkoutUrl) {
            // Redirect-based provider (Chapa): confirmation arrives via webhook/reconcile.
            $deposit->update([
                'provider'           => $this->provider->getName(),
                'provider_reference' => $providerReference,
            ]);

            Log::info('[Platform Finance] Deposit initiated, awaiting provider confirmation', [
                'admin_id'    => $adminId,
                'reference'   => $reference,
                'amount'      => $amount,
                'checkout_url' => $checkoutUrl,
            ]);
        } else {
            // Synchronous provider (sandbox): confirm immediately.
            $this->confirmDeposit($deposit, $providerReference, $result['provider_response'] ?? null);

            Log::info('[Platform Finance] Deposit initiated and confirmed', [
                'admin_id'  => $adminId,
                'reference' => $reference,
                'amount'    => $amount,
                'available' => $this->summary()['available_revenue'],
            ]);
        }

        return [
            'deposit'      => $deposit->fresh(),
            'checkout_url' => $checkoutUrl,
        ];
    }

    /**
     * Normalize a raw origin (e.g. an Origin/Referer header or full URL) into
     * "scheme://host[:port]". Returns null when it isn't a valid http(s) origin.
     */
    private static function normalizeOrigin(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parts = parse_url(trim($raw));
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * Confirm a platform deposit (called by webhook or provider verification).
     *
     * Idempotent — only a pending deposit is credited once.
     *
     * @return bool Whether the deposit was confirmed (false = already confirmed)
     */
    public function confirmDeposit(PlatformDeposit $deposit, string $providerReference, mixed $providerResponse = null): bool
    {
        return DB::transaction(function () use ($deposit, $providerReference) {
            $deposit->refresh();

            if ($deposit->status === PlatformDeposit::STATUS_COMPLETED) {
                Log::info('[Platform Finance] Deposit already confirmed (idempotent skip)', [
                    'reference' => $deposit->reference,
                ]);
                return false;
            }

            if ($deposit->provider_reference && $deposit->provider_reference !== $providerReference) {
                Log::warning('[Platform Finance] Deposit provider reference mismatch', [
                    'expected' => $deposit->provider_reference,
                    'received' => $providerReference,
                    'reference' => $deposit->reference,
                ]);
                return false;
            }

            $deposit->update([
                'status'             => PlatformDeposit::STATUS_COMPLETED,
                'provider_reference' => $providerReference,
                'failure_reason'     => null,
                'completed_at'       => now(),
            ]);

            AuditService::platformDeposit($deposit->id, $deposit->deposited_by, [
                'reference'   => $deposit->reference,
                'amount'      => (float) $deposit->amount,
                'currency'    => $deposit->currency,
                'description' => $deposit->description,
                'provider'    => $this->provider->getName(),
                'status'      => 'completed',
            ]);

            Log::info('[Platform Finance] Deposit confirmed', [
                'reference'   => $deposit->reference,
                'amount'      => (float) $deposit->amount,
                'available'   => $this->summary()['available_revenue'],
            ]);

            return true;
        });
    }

    /**
     * Mark a platform deposit as failed.
     */
    public function failDeposit(PlatformDeposit $deposit, string $reason = ''): void
    {
        DB::transaction(function () use ($deposit, $reason) {
            $deposit->refresh();

            if ($deposit->status === PlatformDeposit::STATUS_COMPLETED) {
                return; // Never downgrade a completed deposit
            }

            $deposit->update([
                'status'         => PlatformDeposit::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            Log::info('[Platform Finance] Deposit marked as failed', [
                'reference' => $deposit->reference,
                'reason'    => $reason,
            ]);
        });
    }

    /**
     * Poll the provider for a pending deposit and confirm/fail it accordingly.
     */
    public function getDepositStatus(PlatformDeposit $deposit, int $adminId): array
    {
        if ($deposit->deposited_by !== $adminId) {
            throw new \RuntimeException('Access denied.');
        }

        if ($deposit->status === PlatformDeposit::STATUS_PENDING && $deposit->provider_reference) {
            try {
                $verifyResult = $this->provider->verify($deposit->provider_reference);

                if ($verifyResult['status'] === 'completed') {
                    $this->confirmDeposit($deposit, $deposit->provider_reference, $verifyResult['data'] ?? null);
                } elseif ($verifyResult['status'] === 'failed') {
                    $this->failDeposit($deposit, $verifyResult['error'] ?? 'Deposit failed at provider');
                }
            } catch (\Exception $e) {
                Log::warning('[Platform Finance] Deposit poll verify failed', [
                    'reference' => $deposit->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $deposit->refresh();

        return [
            'deposit' => [
                'id'                 => $deposit->id,
                'reference'          => $deposit->reference,
                'amount'             => (float) $deposit->amount,
                'status'             => $deposit->status,
                'provider'           => $deposit->provider,
                'failure_reason'     => $deposit->failure_reason,
                'completed_at'       => $deposit->completed_at?->toIso8601String(),
                'created_at'         => $deposit->created_at?->toIso8601String(),
            ],
            'available_revenue' => $this->summary()['available_revenue'],
        ];
    }

    /**
     * Reconcile ALL pending deposits created by this admin (fallback when the
     * webhook is missed, e.g. after returning from Chapa checkout).
     */
    public function reconcilePendingDeposits(int $adminId): array
    {
        $pendingDeposits = PlatformDeposit::where('deposited_by', $adminId)
            ->where('status', PlatformDeposit::STATUS_PENDING)
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', now()->subMinutes(2))
            ->limit(3)
            ->get();

        if ($pendingDeposits->isEmpty()) {
            return ['reconciled' => 0, 'pending' => 0];
        }

        $reconciled = 0;

        foreach ($pendingDeposits as $deposit) {
            try {
                // Payments older than 30 minutes were likely never completed.
                if ($deposit->created_at->diffInMinutes(now()) > 30) {
                    $this->failDeposit($deposit, 'Deposit expired — not completed within 30 minutes');
                    continue;
                }

                $this->getDepositStatus($deposit, $adminId);

                if ($deposit->fresh()->status === PlatformDeposit::STATUS_COMPLETED) {
                    $reconciled++;
                }
            } catch (\Exception $e) {
                // Continue with the next deposit
            }
        }

        return [
            'reconciled' => $reconciled,
            'pending'    => $pendingDeposits->count() - $reconciled,
        ];
    }

    /**
     * Banks supported by the provider for payouts (admin withdrawal form).
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function listBanks(): array
    {
        return $this->provider->listBanks();
    }

    // ═══════════════════════════════════════════════════════════════════
    // WITHDRAWALS (Withdraw Revenue via Chapa transfers — mirrors WithdrawalService)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Request a platform revenue withdrawal.
     *
     * 1. Validate amount against the available platform balance
     * 2. Create a pending PlatformWithdrawal record (reserves the funds)
     * 3. Process the payout via the provider (Chapa transfer API)
     * 4. Mark completed on success / failed with reason on failure
     */
    public function requestWithdrawal(
        int $adminId,
        float $amount,
        array $bank,
        ?string $description = null,
    ): PlatformWithdrawal {
        if ($amount <= 0) {
            throw new \RuntimeException('Withdrawal amount must be greater than zero.');
        }

        $summary = $this->summary();

        if ($amount > $summary['available_revenue']) {
            throw new \RuntimeException(
                'Insufficient platform revenue. Available: ' . number_format($summary['available_revenue'], 2) . ' ETB.'
            );
        }

        $reference = 'PLAT-WTH-' . strtoupper(uniqid());

        $withdrawal = DB::transaction(function () use ($adminId, $amount, $bank, $description, $reference, $summary) {
            $withdrawal = PlatformWithdrawal::create([
                'reference'       => $reference,
                'amount'          => $amount,
                'currency'        => 'ETB',
                'status'          => PlatformWithdrawal::STATUS_PENDING,
                'account_name'    => $bank['account_name'] ?? '',
                'account_number'  => $bank['account_number'] ?? '',
                'bank_name'       => $bank['bank_name'] ?? '',
                'bank_code'       => $bank['bank_code'] ?? null,
                'description'     => $description ?? 'Platform revenue withdrawal',
                'provider'        => $this->provider->getName(),
                'processed_by'    => $adminId,
            ]);

            $recipient = [
                'user_id'           => $adminId,
                'account_name'      => $withdrawal->account_name,
                'account_number'    => $withdrawal->account_number,
                'bank_code'         => $withdrawal->bank_code,
                'bank_name'         => $withdrawal->bank_name,
            ];

            if (empty($recipient['account_number'])) {
                $withdrawal->update([
                    'status'         => PlatformWithdrawal::STATUS_FAILED,
                    'failure_reason' => 'Recipient account number is required. Please check your bank details.',
                ]);

                throw new \RuntimeException('Recipient account number is required. Please check your bank details.');
            }

            $result = $this->provider->payout($amount, $withdrawal->currency, $reference, $recipient);

            if ($result['success']) {
                $withdrawal->update([
                    'status'              => PlatformWithdrawal::STATUS_COMPLETED,
                    'provider_reference'  => $result['provider_reference'],
                    'failure_reason'      => null,
                    'completed_at'        => now(),
                ]);
            } else {
                $withdrawal->update([
                    'status'         => PlatformWithdrawal::STATUS_FAILED,
                    'failure_reason' => $result['error'] ?? 'Withdrawal failed',
                ]);
            }

            // Audit trail (records the request AND final outcome in one entry)
            AuditService::platformWithdrawal($withdrawal->id, $adminId, [
                'reference'    => $withdrawal->reference,
                'amount'       => $amount,
                'currency'     => 'ETB',
                'account_name' => $withdrawal->account_name,
                'bank_name'    => $withdrawal->bank_name,
                'status'       => $withdrawal->status,
                'available'    => $summary['available_revenue'],
            ]);

            return $withdrawal;
        });

        // Broadcast AFTER the transaction commits so the admin dashboard sees fresh data.
        \App\Events\FinanceUpdated::dispatch('platform_withdrawal', [
            'withdrawal_id' => $withdrawal->id,
            'reference'     => $withdrawal->reference,
            'amount'        => (float) $withdrawal->amount,
            'status'        => $withdrawal->status,
            'account_name'  => $withdrawal->account_name,
            'bank_name'     => $withdrawal->bank_name,
        ], $adminId);

        return $withdrawal;
    }

    // ═══════════════════════════════════════════════════════════════════
    // WEBHOOK (provider → platform deposit confirmation)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Handle an incoming deposit webhook for a platform deposit.
     *
     * Mirrors AddFundsService::handleWebhook but resolves the transaction
     * against the platform_deposits table.
     */
    public function handleWebhook(string $provider, string $payload, string $signature = ''): array
    {
        if (!$this->provider->verifyWebhookSignature($payload, $signature)) {
            Log::warning('[Platform Finance] Webhook signature verification failed', ['provider' => $provider]);
            return ['success' => false, 'error' => 'Invalid signature'];
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return ['success' => false, 'error' => 'Invalid payload'];
        }

        // Chapa nests transaction data under "data" on some events.
        $inner = is_array($data['data'] ?? null) ? $data['data'] : [];
        $eventType = $data['event_type'] ?? $data['type'] ?? $data['event'] ?? 'unknown';
        $providerReference = $data['provider_reference'] ?? $inner['provider_reference'] ?? $data['tx_ref'] ?? $inner['tx_ref'] ?? '';
        $depositReference = $data['payment_reference'] ?? $data['reference'] ?? $inner['reference'] ?? $data['tx_ref'] ?? $inner['tx_ref'] ?? '';

        $webhookLog = WebhookLog::create([
            'provider'           => $provider,
            'event_type'         => $eventType,
            'provider_reference' => $providerReference,
            'payload'            => $data,
            'status'             => 'received',
        ]);

        // Duplicate webhook guard
        $existingLog = WebhookLog::where('provider', $provider)
            ->where('provider_reference', $providerReference)
            ->where('id', '!=', $webhookLog->id)
            ->where('status', 'processed')
            ->first();

        if ($existingLog) {
            $webhookLog->update(['status' => 'duplicate']);
            return ['success' => true, 'duplicate' => true];
        }

        $deposit = PlatformDeposit::where('reference', $depositReference)->first();

        if (!$deposit) {
            $webhookLog->update(['status' => 'failed', 'error' => 'Platform deposit not found']);
            return ['success' => false, 'error' => 'Platform deposit not found'];
        }

        try {
            match ($eventType) {
                'payment.completed', 'charge.succeeded', 'charge.success', 'payment.success' => $this->confirmDeposit($deposit, $providerReference, $data),
                'payment.failed', 'charge.failed', 'payment.error' => $this->failDeposit($deposit, $data['error'] ?? 'Deposit failed'),
                'payment.cancelled' => $this->failDeposit($deposit, 'Deposit cancelled by provider'),
                default => Log::info('[Platform Finance] Unhandled webhook event type', ['type' => $eventType]),
            };

            $webhookLog->update(['status' => 'processed']);
        } catch (\Exception $e) {
            $webhookLog->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('[Platform Finance] Webhook processing failed', [
                'reference' => $deposit->reference,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['success' => true];
    }
}
