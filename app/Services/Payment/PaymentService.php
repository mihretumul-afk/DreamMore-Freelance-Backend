<?php

namespace App\Services\Payment;

use App\Models\Contract;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PaymentService — orchestrates all payment operations.
 * Uses database transactions for atomic operations.
 * Never trusts frontend payment success — always verifies with provider.
 */
class PaymentService
{
    private PaymentProviderInterface $provider;

    public function __construct(PaymentProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Calculate platform and processing fees.
     * Returns: ['platform_fee' => float, 'processing_fee' => float, 'net_amount' => float]
     */
    public static function calculateFees(float $amount): array
    {
        $platformFeeRate = (float) config('payment.platform_fee_rate', 0.08);
        $processingFeeRate = (float) config('payment.processing_fee_rate', 0.025);

        $platformFee = round($amount * $platformFeeRate, 2);
        $processingFee = round($amount * $processingFeeRate, 2);
        $netAmount = round($amount - $platformFee - $processingFee, 2);

        return [
            'platform_fee'   => max(0, $platformFee),
            'processing_fee' => max(0, $processingFee),
            'net_amount'     => max(0, $netAmount),
        ];
    }

    /**
     * Fund a milestone (employer pays).
     * 1. Validate milestone can be funded
     * 2. Charge the employer via provider
     * 3. Create payment record
     * 4. Update milestone status to funded
     * 5. Record ledger transactions
     * 6. Notify both parties
     */
    public function fundMilestone(Milestone $milestone, int $employerId, int $paymentMethodId): Payment
    {
        return DB::transaction(function () use ($milestone, $employerId, $paymentMethodId) {
            $contract = $milestone->contract;

            // Validate access
            if (!$contract || $contract->employer_id !== $employerId) {
                throw new \RuntimeException('You do not have access to this contract.');
            }

            // If a completed escrow funding payment already exists for this milestone, return it idempotently
            $existingPayment = Payment::where('milestone_id', $milestone->id)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('status', Payment::STATUS_COMPLETED)
                ->first();

            if ($existingPayment) {
                if ($milestone->status !== Milestone::STATUS_FUNDED) {
                    $milestone->update(['status' => Milestone::STATUS_FUNDED, 'funded_at' => $milestone->funded_at ?? now()]);
                }
                return $existingPayment;
            }

            if (!in_array($milestone->status, [Milestone::STATUS_DRAFT, Milestone::STATUS_UNFUNDED, Milestone::STATUS_FUNDED])) {
                throw new \RuntimeException("Milestone cannot be funded. Current status: '{$milestone->status}'.");
            }

            $amount = (float) $milestone->amount;
            $reference = Payment::generateReference();
            $fees = self::calculateFees($amount);

            // Create payment record
            $payment = Payment::create([
                'reference'          => $reference,
                'payer_id'           => $employerId,
                'milestone_id'       => $milestone->id,
                'payment_method_id'  => $paymentMethodId,
                'type'               => Payment::TYPE_ESCROW_FUNDED,
                'amount'             => $amount,
                'platform_fee'       => $fees['platform_fee'],
                'processing_fee'     => $fees['processing_fee'],
                'net_amount'         => $fees['net_amount'],
                'currency'           => 'ETB',
                'status'             => Payment::STATUS_PENDING,
            ]);

            // Charge via provider
            $result = $this->provider->charge($amount, 'ETB', $reference, [
                'user_id'       => $employerId,
                'milestone_id'  => $milestone->id,
                'contract_id'   => $contract->id,
                'payment_id'    => $payment->id,
            ]);

            if (!$result['success']) {
                $payment->update([
                    'status'         => Payment::STATUS_FAILED,
                    'failure_reason' => $result['error'] ?? 'Payment failed',
                    'failed_at'      => now(),
                ]);
                throw new \RuntimeException($result['error'] ?? 'Payment failed. Please try again.');
            }

            // Mark payment as completed
            $payment->update([
                'status'              => Payment::STATUS_COMPLETED,
                'provider_reference'  => $result['provider_reference'],
                'provider_response'   => $result['provider_response'] ?? null,
                'processed_at'        => now(),
            ]);

            // Update milestone status
            $milestone->update(['status' => Milestone::STATUS_FUNDED, 'funded_at' => now()]);

            // Record ledger transactions
            $this->recordLedgerTransaction($employerId, $fees['platform_fee'], Transaction::DIR_DEBIT, Transaction::TYPE_PLATFORM_FEE, $payment->id, "Platform fee for milestone \"{$milestone->title}\"");
            $this->recordLedgerTransaction($employerId, $fees['processing_fee'], Transaction::DIR_DEBIT, Transaction::TYPE_PROCESSING_FEE, $payment->id, "Processing fee for milestone \"{$milestone->title}\"");

            // Audit log
            AuditService::milestoneFunded($milestone->id, $employerId, [
                'milestone_title' => $milestone->title,
                'amount'          => $amount,
                'currency'        => 'ETB',
            ]);

            // Notify freelancer
            NotificationService::milestoneFunded(
                $contract->freelancer_id,
                $milestone->title,
                $contract->title,
                $contract->id,
                $amount
            );

            return $payment;
        });
    }

    /**
     * Release funds to freelancer when employer approves milestone.
     * 1. Verify payment exists and is completed
     * 2. Verify milestone is funded/approved
     * 3. Verify no blocking dispute
     * 4. Credit freelancer wallet
     * 5. Record release transaction
     * 6. Update milestone status
     * 7. Notify freelancer
     */
    public function releaseMilestone(Milestone $milestone, ?int $actorId): Payment
    {
        return DB::transaction(function () use ($milestone, $actorId) {
            $contract = $milestone->contract;

            // Find the funding payment
            $payment = Payment::where('milestone_id', $milestone->id)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('status', Payment::STATUS_COMPLETED)
                ->first();

            if (!$payment) {
                throw new \RuntimeException('No completed payment found for this milestone.');
            }

            // Verify milestone is approved
            if ($milestone->status !== Milestone::STATUS_APPROVED) {
                throw new \RuntimeException("Milestone must be approved before releasing funds. Current status: '{$milestone->status}'.");
            }

            // Verify no active dispute
            if ($contract->status === Contract::STATUS_DISPUTED || $milestone->status === Milestone::STATUS_DISPUTED) {
                throw new \RuntimeException('Cannot release funds while a dispute is active.');
            }

            $freelancerId = $contract->freelancer_id;
            $releaseAmount = (float) $payment->net_amount; // Release net amount after fees

            // Credit freelancer wallet
            $wallet = Wallet::forUser($freelancerId);
            $wallet->creditAvailable($releaseAmount);

            // Create release payment record
            $releasePayment = Payment::create([
                'reference'          => Payment::generateReference(),
                'payer_id'           => $payment->payer_id,
                'payee_id'           => $freelancerId,
                'milestone_id'       => $milestone->id,
                'type'               => Payment::TYPE_MILESTONE_RELEASED,
                'amount'             => $releaseAmount,
                'platform_fee'       => 0,
                'processing_fee'     => 0,
                'net_amount'         => $releaseAmount,
                'currency'           => 'ETB',
                'status'             => Payment::STATUS_COMPLETED,
                'processed_at'       => now(),
            ]);

            // Record ledger transaction for release
            $this->recordLedgerTransaction($freelancerId, $releaseAmount, Transaction::DIR_CREDIT, Transaction::TYPE_FUNDS_RELEASED, $releasePayment->id, "Funds released for milestone \"{$milestone->title}\"");

            // Update milestone status
            $milestone->update([
                'status'       => Milestone::STATUS_RELEASED,
                'released_at'  => now(),
            ]);

            // Audit log
            AuditService::milestoneReleased($milestone->id, $freelancerId, [
                'milestone_title' => $milestone->title,
                'amount'          => $releaseAmount,
                'currency'        => 'ETB',
                'freelancer_id'   => $freelancerId,
            ]);

            // Notify freelancer
            NotificationService::milestonePaid(
                $freelancerId,
                $milestone->title,
                $contract->id,
                $releaseAmount
            );

            // Check if all milestones are released → contract completed
            $allReleased = $contract->milestones()
                ->where('status', '!=', Milestone::STATUS_RELEASED)
                ->count() === 0;

            if ($allReleased && $contract->milestones()->count() > 0) {
                $contract->update([
                    'status'   => Contract::STATUS_COMPLETED,
                    'end_date' => now(),
                ]);

                NotificationService::contractCompleted($freelancerId, $contract->title, 'freelancer');
                NotificationService::contractCompleted($contract->employer_id, $contract->title, 'employer');
            }

            return $releasePayment;
        });
    }

    /**
     * Process a refund.
     */
    public function processRefund(Payment $originalPayment, int $actorId, string $reason = ''): Payment
    {
        return DB::transaction(function () use ($originalPayment, $actorId, $reason) {
            if ($originalPayment->status !== Payment::STATUS_COMPLETED) {
                throw new \RuntimeException('Only completed payments can be refunded.');
            }
            if ($originalPayment->isRefunded()) {
                throw new \RuntimeException('This payment has already been refunded.');
            }

            $refundAmount = (float) $originalPayment->amount;

            // Call provider refund
            $result = $this->provider->refund(
                $originalPayment->provider_reference ?? '',
                $refundAmount,
                $reason
            );

            $refundData = [
                'reference'          => Payment::generateReference(),
                'payer_id'           => $originalPayment->payee_id ?? $originalPayment->payer_id,
                'payee_id'           => $originalPayment->payer_id,
                'milestone_id'       => $originalPayment->milestone_id,
                'type'               => Payment::TYPE_REFUND,
                'amount'             => $refundAmount,
                'platform_fee'       => 0,
                'processing_fee'     => 0,
                'net_amount'         => $refundAmount,
                'currency'           => $originalPayment->currency,
                'status'             => $result['success'] ? Payment::STATUS_COMPLETED : Payment::STATUS_FAILED,
                'provider_reference' => $result['provider_reference'] ?? null,
            ];

            if ($result['success']) {
                $refundData['refunded_at'] = now();
            }

            $refund = Payment::create($refundData);

            // Update original payment refund status
            if ($result['success']) {
                $originalPayment->update([
                    'status'      => Payment::STATUS_REFUNDED,
                    'refunded_at' => now(),
                ]);

                // Credit employer wallet balance
                $employerWallet = Wallet::forUser($originalPayment->payer_id);
                $employerWallet->creditAvailable($refundAmount);
            }

            // Ledger entry
            $this->recordLedgerTransaction($originalPayment->payer_id, $refundAmount, Transaction::DIR_CREDIT, Transaction::TYPE_REFUND, $refund->id, "Refund for payment {$originalPayment->reference}");

            // Audit
            AuditService::paymentRefunded($originalPayment->id, $actorId, [
                'reference'     => $originalPayment->reference,
                'refund_amount' => $refundAmount,
                'currency'      => $originalPayment->currency,
                'reason'        => $reason,
            ]);

            // Notify
            NotificationService::paymentRefunded(
                $originalPayment->payer_id,
                $originalPayment->reference,
                $refundAmount,
                $originalPayment->currency
            );

            return $refund;
        });
    }

    /**
     * Refund a funded milestone back to the employer.
     */
    public function refundMilestone(Milestone $milestone, ?int $actorId, string $reason = ''): Payment
    {
        return DB::transaction(function () use ($milestone, $actorId, $reason) {
            $payment = Payment::where('milestone_id', $milestone->id)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('status', Payment::STATUS_COMPLETED)
                ->first();

            if (!$payment) {
                $milestone->update(['status' => Milestone::STATUS_CANCELLED]);
                return new Payment();
            }

            $refund = $this->processRefund($payment, $actorId, $reason);

            $milestone->update(['status' => Milestone::STATUS_CANCELLED]);

            return $refund;
        });
    }

    /**
     * Record a ledger transaction.
     */
    private function recordLedgerTransaction(
        int $userId,
        float $amount,
        string $direction,
        string $type,
        ?int $paymentId = null,
        string $description = ''
    ): void {
        $wallet = Wallet::forUser($userId);

        Transaction::create([
            'reference'      => Transaction::generateReference(),
            'user_id'        => $userId,
            'payment_id'     => $paymentId,
            'wallet_id'      => $wallet->id,
            'direction'      => $direction,
            'type'           => $type,
            'amount'         => $amount,
            'balance_before' => $wallet->available_balance,
            'balance_after'  => $wallet->available_balance,
            'currency'       => 'ETB',
            'status'         => Transaction::STATUS_COMPLETED,
            'description'    => $description,
        ]);
    }
}
