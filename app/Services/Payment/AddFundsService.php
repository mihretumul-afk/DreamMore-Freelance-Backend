<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookLog;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AddFundsService — handles wallet deposit (Add Funds) flow.
 *
 * Flow:
 * 1. User initiates deposit → validate → create pending Payment → call provider
 * 2. Provider confirms (sync in sandbox / webhook in production) → credit wallet
 * 3. Idempotency: provider_reference prevents double credit
 *
 * IMPORTANT: In sandbox mode, payment is confirmed synchronously.
 * In production, only webhook/provider verification credits the wallet.
 */
class AddFundsService
{
    private PaymentProviderInterface $provider;

    public function __construct(PaymentProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Minimum deposit amount (ETB).
     */
    public const MIN_AMOUNT = 10;

    /**
     * Maximum deposit amount (ETB).
     */
    public const MAX_AMOUNT = 500000;

    /**
     * Initiate a wallet deposit (Add Funds).
     *
     * 1. Validate user role (employer or freelancer)
     * 2. Validate amount
     * 3. Validate payment method belongs to user
     * 4. Create a pending payment record (TYPE_WALLET_DEPOSIT)
     * 5. Charge via payment provider
     * 6. If provider confirms synchronously (sandbox), credit wallet immediately
     * 7. If provider requires webhook, leave pending for webhook to confirm
     *
     * @return array{payment: Payment, wallet: Wallet, previous_balance: float}
     */
    public function initiate(int $userId, float $amount, int $paymentMethodId): array
    {
        // ── Validate user ──────────────────────────────────────────────
        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException('User not found.');
        }

        if (!in_array($user->role, ['employer', 'freelancer'])) {
            throw new \RuntimeException('Only employers and freelancers can add funds.');
        }

        // ── Validate amount ────────────────────────────────────────────
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new \RuntimeException(
                "Amount must be between " . number_format(self::MIN_AMOUNT) . " and " . number_format(self::MAX_AMOUNT) . " ETB."
            );
        }

        // ── Validate payment method ────────────────────────────────────
        $paymentMethod = $user->paymentMethods()->where('id', $paymentMethodId)->first();
        if (!$paymentMethod) {
            throw new \RuntimeException('Payment method not found or does not belong to you.');
        }

        // ── Get or create wallet ───────────────────────────────────────
        $wallet = Wallet::forUser($userId);
        $previousBalance = (float) $wallet->available_balance;

        // ── Create pending payment ─────────────────────────────────────
        $reference = Payment::generateReference();

        $payment = DB::transaction(function () use ($userId, $amount, $paymentMethodId, $reference, $paymentMethod) {
            return Payment::create([
                'reference'          => $reference,
                'payer_id'           => $userId,
                'payment_method_id'  => $paymentMethodId,
                'type'               => Payment::TYPE_WALLET_DEPOSIT,
                'amount'             => $amount,
                'platform_fee'       => 0,
                'processing_fee'     => 0,
                'fee'                => 0,
                'net_amount'         => $amount,
                'currency'           => 'ETB',
                'status'             => Payment::STATUS_PENDING,
                'description'        => 'Wallet deposit via ' . $paymentMethod->display_label,
                'provider'           => $this->provider->getName(),
            ]);
        });

        // ── Determine return URL based on user role ───────────────────
        $user = User::find($userId);
        $returnUrlBase = config('payment.chapa.return_url', url('/wallet'));
        if ($user && $user->role === 'freelancer') {
            $returnUrlBase = 'http://localhost:5173/freelancer/withdrawals';
        } elseif ($user && $user->role === 'employer') {
            $returnUrlBase = 'http://localhost:5173/employer/finance';
        }

        // ── Charge via provider ────────────────────────────────────────
        $result = $this->provider->charge($amount, 'ETB', $reference, [
            'user_id'          => $userId,
            'payment_id'       => $payment->id,
            'payment_method_id' => $paymentMethodId,
            'type'             => 'wallet_deposit',
            'return_url'       => $returnUrlBase,
        ]);

        if (!$result['success']) {
            $payment->update([
                'status'         => Payment::STATUS_FAILED,
                'failure_reason' => $result['error'] ?? 'Payment failed',
            ]);

            throw new \RuntimeException($result['error'] ?? 'Payment failed. Please try again.');
        }

        // Store checkout_url if provided (Chapa redirect flow)
        $checkoutUrl = $result['checkout_url'] ?? null;

        if ($checkoutUrl) {
            // Redirect-based provider (Chapa): payment confirmed later via webhook.
            // Store the checkout URL and provider reference in the payment record.
            $payment->update([
                'provider_reference' => $result['provider_reference'] ?? null,
                'metadata' => array_merge(
                    $payment->metadata ?? [],
                    ['checkout_url' => $checkoutUrl]
                ),
            ]);

            Log::info('[Add Funds] Deposit initiated, awaiting provider confirmation', [
                'user_id'     => $userId,
                'reference'   => $reference,
                'amount'      => $amount,
                'checkout_url' => $checkoutUrl,
            ]);
        } else {
            // Sandbox / synchronous provider: confirm immediately.
            $this->confirmDeposit($payment, $result['provider_reference'], $result['provider_response'] ?? null);
            $wallet->refresh();

            Log::info('[Add Funds] Deposit initiated and confirmed', [
                'user_id'     => $userId,
                'reference'   => $reference,
                'amount'      => $amount,
                'new_balance' => (float) $wallet->available_balance,
            ]);
        }

        return [
            'payment'          => $payment,
            'wallet'           => $wallet,
            'previous_balance' => $previousBalance,
            'checkout_url'     => $checkoutUrl,
        ];
    }

    /**
     * Confirm a wallet deposit (called by webhook or provider verification).
     *
     * Idempotent: if already confirmed, returns early.
     * Uses row locking to prevent concurrent credit.
     *
     * @param  Payment  $payment
     * @param  string   $providerReference
     * @param  mixed    $providerResponse
     * @return bool     Whether credit was applied (true = credited, false = already credited)
     */
    public function confirmDeposit(Payment $payment, string $providerReference, mixed $providerResponse = null): bool
    {
        return DB::transaction(function () use ($payment, $providerReference, $providerResponse) {
            // ── Idempotency: already completed? ────────────────────────
            $payment->refresh();
            if ($payment->status === Payment::STATUS_COMPLETED) {
                Log::info('[Add Funds] Deposit already confirmed (idempotent skip)', [
                    'reference' => $payment->reference,
                ]);
                return false;
            }

            // ── Verify provider reference matches ──────────────────────
            if (
                $payment->provider_reference &&
                $payment->provider_reference !== $providerReference
            ) {
                Log::warning('[Add Funds] Provider reference mismatch', [
                    'expected'  => $payment->provider_reference,
                    'received'  => $providerReference,
                    'reference' => $payment->reference,
                ]);
                return false;
            }

            // ── Lock wallet row for atomic credit ──────────────────────
            $wallet = Wallet::where('user_id', $payment->payer_id)->lockForUpdate()->first();
            if (!$wallet) {
                Log::error('[Add Funds] Wallet not found for user', ['user_id' => $payment->payer_id]);
                throw new \RuntimeException('Wallet not found.');
            }

            $previousBalance = (float) $wallet->available_balance;
            $amount = (float) $payment->amount;

            // ── Credit wallet ──────────────────────────────────────────
            $wallet->increment('available_balance', $amount);
            $wallet->refresh();
            $newBalance = (float) $wallet->available_balance;

            // ── Update payment record ──────────────────────────────────
            $payment->update([
                'status'             => Payment::STATUS_COMPLETED,
                'provider_reference' => $providerReference,
                'provider_response'  => $providerResponse,
                'processed_at'       => now(),
            ]);

            // ── Record ledger transaction ──────────────────────────────
            Transaction::create([
                'reference'      => Transaction::generateReference(),
                'payment_id'     => $payment->id,
                'user_id'        => $payment->payer_id,
                'wallet_id'      => $wallet->id,
                'direction'      => Transaction::DIR_CREDIT,
                'type'           => Transaction::TYPE_WALLET_DEPOSIT,
                'amount'         => $amount,
                'balance_before' => $previousBalance,
                'balance_after'  => $newBalance,
                'currency'       => 'ETB',
                'status'         => Transaction::STATUS_COMPLETED,
                'description'    => "Wallet deposit via {$this->provider->getName()}",
            ]);

            // ── Audit log ─────────────────────────────────────────────
            AuditService::walletDepositConfirmed($payment->id, $payment->payer_id, [
                'reference'        => $payment->reference,
                'amount'           => $amount,
                'currency'         => 'ETB',
                'provider'         => $this->provider->getName(),
                'provider_ref'     => $providerReference,
                'previous_balance' => $previousBalance,
                'new_balance'      => $newBalance,
            ]);

            // ── Notify user ───────────────────────────────────────────
            NotificationService::walletDepositCompleted(
                $payment->payer_id,
                $payment->reference,
                $amount,
                $newBalance
            );

            // ── Notify Finance Admins ─────────────────────────────────
            $payerName = User::find($payment->payer_id)?->name ?? 'A user';
            NotificationService::notifyAdmins(
                ['finance.view', 'payments.view'],
                'wallet_deposit_completed',
                'Wallet Deposit Confirmed',
                "{$payerName} added ETB " . number_format($amount, 2) . " to wallet (Ref: {$payment->reference}).",
                '/admin/finance'
            );

            Log::info('[Add Funds] Deposit confirmed and wallet credited', [
                'user_id'          => $payment->payer_id,
                'reference'        => $payment->reference,
                'amount'           => $amount,
                'previous_balance' => $previousBalance,
                'new_balance'      => $newBalance,
            ]);

            return true;
        });
    }

    /**
     * Process a failed deposit (called by webhook for provider failure events).
     *
     * @param  Payment $payment
     * @param  string  $reason
     */
    public function failDeposit(Payment $payment, string $reason = ''): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $payment->refresh();
            if ($payment->status === Payment::STATUS_COMPLETED) {
                return; // Already completed, don't downgrade
            }

            $payment->update([
                'status'         => Payment::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            Log::info('[Add Funds] Deposit marked as failed', [
                'reference' => $payment->reference,
                'reason'    => $reason,
            ]);
        });
    }

    /**
     * Cancel a pending deposit (e.g., user cancels before provider completes).
     */
    public function cancelDeposit(Payment $payment, int $userId): void
    {
        if ($payment->payer_id !== $userId) {
            throw new \RuntimeException('You do not have access to this deposit.');
        }

        if ($payment->status !== Payment::STATUS_PENDING) {
            throw new \RuntimeException('Only pending deposits can be cancelled.');
        }

        $payment->update([
            'status' => Payment::STATUS_CANCELLED,
        ]);

        Log::info('[Add Funds] Deposit cancelled', [
            'reference' => $payment->reference,
            'user_id'   => $userId,
        ]);
    }

    /**
     * Handle an incoming webhook from a payment provider.
     *
     * 1. Validate webhook signature
     * 2. Log webhook (WebhookLog)
     * 3. Find payment by reference
     * 4. Confirm or fail based on event
     */
    public function handleWebhook(string $provider, string $payload, string $signature = ''): array
    {
        // ── Verify webhook signature ───────────────────────────────────
        if (!$this->provider->verifyWebhookSignature($payload, $signature)) {
            Log::warning('[Add Funds] Webhook signature verification failed', ['provider' => $provider]);
            return ['success' => false, 'error' => 'Invalid signature'];
        }

        // ── Parse payload ──────────────────────────────────────────────
        $data = json_decode($payload, true);
        if (!$data) {
            return ['success' => false, 'error' => 'Invalid payload'];
        }

        $eventType = $data['event_type'] ?? $data['type'] ?? 'unknown';
        $providerReference = $data['provider_reference'] ?? $data['reference'] ?? '';
        $paymentReference = $data['payment_reference'] ?? $data['reference'] ?? '';

        // ── Log webhook ────────────────────────────────────────────────
        $webhookLog = WebhookLog::create([
            'provider'           => $provider,
            'event_type'         => $eventType,
            'provider_reference' => $providerReference,
            'payload'            => $data,
            'status'             => 'received',
        ]);

        // ── Check for duplicate webhook ────────────────────────────────
        $existingLog = WebhookLog::where('provider', $provider)
            ->where('provider_reference', $providerReference)
            ->where('id', '!=', $webhookLog->id)
            ->where('status', 'processed')
            ->first();

        if ($existingLog) {
            $webhookLog->update(['status' => 'duplicate']);
            Log::info('[Add Funds] Duplicate webhook ignored', [
                'provider'          => $provider,
                'provider_reference' => $providerReference,
            ]);
            return ['success' => true, 'duplicate' => true];
        }

        // ── Find payment ───────────────────────────────────────────────
        $payment = Payment::where('reference', $paymentReference)
            ->where('type', Payment::TYPE_WALLET_DEPOSIT)
            ->first();

        if (!$payment) {
            $webhookLog->update(['status' => 'failed', 'error' => 'Payment not found']);
            return ['success' => false, 'error' => 'Payment not found'];
        }

        // ── Process based on event type ────────────────────────────────
        try {
            match ($eventType) {
                'payment.completed', 'charge.succeeded', 'payment.success' => $this->confirmDeposit($payment, $providerReference, $data),
                'payment.failed', 'charge.failed', 'payment.error' => $this->failDeposit($payment, $data['error'] ?? 'Payment failed'),
                'payment.cancelled' => $this->failDeposit($payment, 'Payment cancelled by provider'),
                default => Log::info('[Add Funds] Unhandled webhook event type', ['type' => $eventType]),
            };

            $webhookLog->update(['status' => 'processed']);
        } catch (\Exception $e) {
            $webhookLog->update(['status' => 'failed', 'error' => $e->getMessage()]);
            Log::error('[Add Funds] Webhook processing failed', [
                'reference' => $payment->reference,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['success' => true];
    }

    /**
     * Get deposit status for polling (frontend fallback).
     * If payment is still pending, polls the provider API to check
     * if the payment completed (e.g., webhook was missed).
     */
    public function getDepositStatus(Payment $payment, int $userId): array
    {
        if ($payment->payer_id !== $userId) {
            throw new \RuntimeException('Access denied.');
        }

        $wallet = Wallet::forUser($userId);

        // If still pending and has a provider reference, poll the provider
        if ($payment->status === Payment::STATUS_PENDING && $payment->provider_reference) {
            try {
                $verifyResult = $this->provider->verify($payment->provider_reference);

                if ($verifyResult['status'] === 'completed') {
                    // Payment confirmed by provider — credit wallet
                    $this->confirmDeposit(
                        $payment,
                        $payment->provider_reference,
                        $verifyResult['data'] ?? null
                    );
                    $wallet->refresh();
                } elseif ($verifyResult['status'] === 'failed') {
                    $this->failDeposit($payment, $verifyResult['error'] ?? 'Payment failed at provider');
                }
            } catch (\Exception $e) {
                Log::warning('[Add Funds] Poll verify failed', [
                    'reference' => $payment->reference,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Re-fetch payment in case status changed
        $payment->refresh();

        return [
            'payment' => [
                'id'                => $payment->id,
                'reference'         => $payment->reference,
                'amount'            => (float) $payment->amount,
                'status'            => $payment->status,
                'provider'          => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'failure_reason'    => $payment->failure_reason,
                'processed_at'      => $payment->processed_at?->toIso8601String(),
                'created_at'        => $payment->created_at?->toIso8601String(),
            ],
            'wallet' => [
                'available_balance' => (float) $wallet->available_balance,
                'currency'          => $wallet->currency,
            ],
        ];
    }
}
