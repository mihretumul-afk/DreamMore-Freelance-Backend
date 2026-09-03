<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * WithdrawalService — manages freelancer withdrawal flow.
 */
class WithdrawalService
{
    private PaymentProviderInterface $provider;

    public function __construct(PaymentProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Calculate withdrawal fee.
     */
    public static function calculateFee(float $amount): float
    {
        $feeRate = (float) config('payment.withdrawal_fee_rate', 0.015);
        return round($amount * $feeRate, 2);
    }

    /**
     * Request a withdrawal.
     * 1. Validate balance
     * 2. Reserve funds from available balance
     * 3. Create withdrawal record
     * 4. Process via provider
     * 5. Update status
     */
    public function request(int $userId, float $amount, int $paymentMethodId): Withdrawal
    {
        return DB::transaction(function () use ($userId, $amount, $paymentMethodId) {
            $wallet = Wallet::where('user_id', $userId)->first();
            if (!$wallet) {
                throw new \RuntimeException('No wallet found.');
            }

            if ($amount <= 0) {
                throw new \RuntimeException('Withdrawal amount must be greater than zero.');
            }

            $minWithdrawal = (float) config('payment.min_withdrawal', 100);
            if ($amount < $minWithdrawal) {
                throw new \RuntimeException(
                    "Minimum withdrawal amount is " . number_format($minWithdrawal) . " ETB."
                );
            }

            if ((float) $wallet->available_balance < $amount) {
                throw new \RuntimeException('Insufficient available balance.');
            }

            $fee = self::calculateFee($amount);
            $netAmount = round($amount - $fee, 2);
            $reference = 'WTH-' . strtoupper(uniqid());

            // Reserve funds
            $wallet->reserveForWithdrawal($amount);

            // Create withdrawal
            $withdrawal = Withdrawal::create([
                'reference'         => $reference,
                'user_id'           => $userId,
                'payment_method_id' => $paymentMethodId,
                'amount'            => $amount,
                'fee'               => $fee,
                'net_amount'        => $netAmount,
                'currency'          => 'ETB',
                'status'            => Withdrawal::STATUS_REQUESTED,
            ]);

            // Auto-process the withdrawal immediately (no admin approval needed)
            $this->process($withdrawal);

            // Only send "requested" notification if process() didn't already fail it
            $withdrawal->refresh();
            if ($withdrawal->status !== Withdrawal::STATUS_FAILED) {
                // Audit
                AuditService::withdrawalRequested($withdrawal->id, $userId, [
                    'reference'  => $reference,
                    'amount'     => $amount,
                    'fee'        => $fee,
                    'net_amount' => $netAmount,
                    'currency'   => 'ETB',
                ]);

                // Notify user
                NotificationService::withdrawalRequested(
                    $userId,
                    $reference,
                    $amount,
                    $fee,
                    $netAmount
                );

                // Broadcast finance update
                \App\Events\FinanceUpdated::dispatch('withdrawal_requested', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $reference,
                    'amount'        => $amount,
                    'fee'           => $fee,
                    'net_amount'    => $netAmount,
                    'user_id'       => $userId,
                ], $userId);
            }

            return $withdrawal;
        });
    }

    /**
     * Process a withdrawal (called automatically after request, or by queue/cron).
     */
    public function process(Withdrawal $withdrawal): void
    {
        DB::transaction(function () use ($withdrawal) {
            if ($withdrawal->status !== Withdrawal::STATUS_REQUESTED) {
                throw new \RuntimeException("Withdrawal cannot be processed. Status: '{$withdrawal->status}'.");
            }

            $withdrawal->update(['status' => Withdrawal::STATUS_PROCESSING]);

            $wallet = Wallet::where('user_id', $withdrawal->user_id)->first();
            $paymentMethod = $withdrawal->paymentMethod;

            // Build recipient details from PaymentMethod
            $recipient = [
                'user_id'           => $withdrawal->user_id,
                'payment_method_id' => $withdrawal->payment_method_id,
                'type'              => $paymentMethod?->type,
                'provider'          => $paymentMethod?->provider,
            ];

            // Extract actual payout destination from PaymentMethod
            if ($paymentMethod) {
                $recipient['account_name']   = $paymentMethod->account_name ?? '';
                $recipient['account_number'] = $paymentMethod->account_number_encrypted ?? '';
                $recipient['bank_code']      = $paymentMethod->bank_code ?? null;
                $recipient['bank_name']      = $paymentMethod->bank_name ?? '';
            }

            // Validate recipient details before calling provider
            if (empty($recipient['account_number']) || empty($recipient['bank_code'])) {
                $withdrawal->update([
                    'status'         => Withdrawal::STATUS_FAILED,
                    'failure_reason' => 'Payout details incomplete. Please update your payment method with your full account number and bank.',
                ]);

                // Restore funds
                $wallet->releaseReservation((float) $withdrawal->amount, 'Payout details incomplete: ' . $withdrawal->reference);

                throw new \RuntimeException('Your payout details are incomplete. Please update your payment method with your full account number and bank before withdrawing.');
            }

            // Call provider
            $result = $this->provider->payout(
                (float) $withdrawal->net_amount,
                $withdrawal->currency,
                $withdrawal->reference,
                $recipient
            );

            if ($result['success']) {
                $withdrawal->update([
                    'status'              => Withdrawal::STATUS_COMPLETED,
                    'provider_reference'  => $result['provider_reference'],
                    'completed_at'        => now(),
                ]);

                // Complete withdrawal in wallet
                if ($wallet) {
                    $wallet->completeWithdrawal((float) $withdrawal->amount);
                }

                // Ledger entry
                $this->recordLedgerEntry($withdrawal->user_id, $withdrawal->amount, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL, "Withdrawal {$withdrawal->reference}");
                if ($withdrawal->fee > 0) {
                    $this->recordLedgerEntry($withdrawal->user_id, $withdrawal->fee, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL_FEE, "Withdrawal fee for {$withdrawal->reference}");
                }

                // Audit
                AuditService::withdrawalCompleted($withdrawal->id, $withdrawal->user_id, [
                    'reference'  => $withdrawal->reference,
                    'net_amount' => $withdrawal->net_amount,
                    'currency'   => $withdrawal->currency,
                ]);

                // Notify
                NotificationService::withdrawalCompleted(
                    $withdrawal->user_id,
                    $withdrawal->reference,
                    $withdrawal->amount,
                    $withdrawal->fee,
                    $withdrawal->net_amount
                );

                // Broadcast finance update
                \App\Events\FinanceUpdated::dispatch('withdrawal_completed', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                    'amount'        => $withdrawal->amount,
                    'fee'           => $withdrawal->fee,
                    'net_amount'    => $withdrawal->net_amount,
                    'user_id'       => $withdrawal->user_id,
                ], $withdrawal->user_id);
            } else {
                $withdrawal->update([
                    'status'          => Withdrawal::STATUS_FAILED,
                    'failure_reason'  => $result['error'] ?? 'Withdrawal failed',
                    'provider_response' => $result['provider_response'] ?? null,
                ]);

                // Return funds to available balance
                if ($wallet) {
                    $wallet->releaseReservation((float) $withdrawal->amount, "Withdrawal failed: {$withdrawal->reference}");
                }

                // Audit
                AuditService::withdrawalFailed($withdrawal->id, $withdrawal->user_id, [
                    'reference' => $withdrawal->reference,
                    'reason'    => $result['error'] ?? 'Unknown error',
                ]);

                // Notify
                NotificationService::withdrawalFailed(
                    $withdrawal->user_id,
                    $withdrawal->reference,
                    $withdrawal->amount,
                    $result['error'] ?? 'Withdrawal failed'
                );
            }
        });
    }

    /**
     * Cancel a pending withdrawal.
     */
    public function cancel(Withdrawal $withdrawal, int $userId): void
    {
        DB::transaction(function () use ($withdrawal, $userId) {
            if ($withdrawal->status !== Withdrawal::STATUS_REQUESTED) {
                throw new \RuntimeException('Only requested withdrawals can be cancelled.');
            }

            $withdrawal->update(['status' => Withdrawal::STATUS_CANCELLED]);

            // Return funds
            $wallet = Wallet::where('user_id', $userId)->first();
            if ($wallet) {
                $wallet->releaseReservation((float) $withdrawal->amount, "Withdrawal cancelled: {$withdrawal->reference}");
            }

            AuditService::withdrawalFailed($withdrawal->id, $userId, [
                'reference' => $withdrawal->reference,
                'reason'    => 'Cancelled by user',
            ]);
        });
    }

    private function recordLedgerEntry(int $userId, float $amount, string $direction, string $type, string $description): void
    {
        $wallet = Wallet::forUser($userId);
        $currentBalance = (float) ($wallet->available_balance ?? 0.00);

        Transaction::create([
            'reference'      => Transaction::generateReference(),
            'user_id'        => $userId,
            'wallet_id'      => $wallet->id,
            'direction'      => $direction,
            'type'           => $type,
            'amount'         => $amount,
            'balance_before' => $currentBalance,
            'balance_after'  => $currentBalance,
            'fee'            => 0,
            'currency'       => 'ETB',
            'status'         => 'completed',
            'description'    => $description,
        ]);
    }
}
