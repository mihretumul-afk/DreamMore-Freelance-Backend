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
        // Read contract/milestone fee percentage configured by admin in settings (default: 8%)
        $feeSetting = \App\Models\AdminSetting::getValue(
            'platform_fee_percent',
            \App\Models\PlatformSetting::get('platform_fee_percent', '8'),
            'string'
        );
        $percent = is_numeric($feeSetting) ? (float) $feeSetting : 8.0;
        $platformFeeRate = max(0, min(0.50, $percent / 100.0));

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
        $shouldBroadcast = false;
        $freelancerId = null;

        $payment = DB::transaction(function () use ($milestone, $employerId, $paymentMethodId, &$shouldBroadcast, &$freelancerId) {
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

            // Create payment record — fees are NOT calculated at funding time.
            // The full amount is held in escrow. Fees are calculated only when
            // funds are released to the freelancer upon approval.
            $payment = Payment::create([
                'reference'          => $reference,
                'payer_id'           => $employerId,
                'milestone_id'       => $milestone->id,
                'payment_method_id'  => $paymentMethodId,
                'type'               => Payment::TYPE_ESCROW_FUNDED,
                'amount'             => $amount,
                'platform_fee'       => 0,
                'processing_fee'     => 0,
                'net_amount'         => $amount,
                'currency'           => 'ETB',
                'status'             => Payment::STATUS_PENDING,
            ]);

            // Try wallet first — if employer has sufficient balance, use it directly
            $wallet = Wallet::where('user_id', $employerId)->first();
            $usedWallet = false;

            if ($wallet && $wallet->available_balance >= $amount) {
                // Deduct from wallet available balance → hold in escrow
                $wallet->decrement('available_balance', $amount);
                $usedWallet = true;

                Log::info('[Payment] Milestone funded from wallet', [
                    'reference'  => $reference,
                    'employer_id' => $employerId,
                    'amount'     => $amount,
                    'new_balance' => $wallet->fresh()->available_balance,
                ]);
            }

            // If wallet didn't have enough, charge via external provider (Chapa checkout)
            if (!$usedWallet) {
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
            }

            // Mark payment as completed
            $payment->update([
                'status'              => Payment::STATUS_COMPLETED,
                'provider_reference'  => $usedWallet ? 'WALLET-' . $reference : ($result['provider_reference'] ?? null),
                'provider_response'   => $usedWallet ? ['source' => 'wallet'] : ($result['provider_response'] ?? null),
                'processed_at'        => now(),
            ]);

            // Update milestone status
            $milestone->update(['status' => Milestone::STATUS_FUNDED, 'funded_at' => now()]);

            // Record ledger transaction — full amount held in escrow, no fees yet
            $this->recordLedgerTransaction($employerId, $amount, Transaction::DIR_DEBIT, Transaction::TYPE_FUNDS_HELD, $payment->id, "Funds held in escrow for milestone \"{$milestone->title}\"");

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

            // Email employer: escrow payment confirmed (additive, email-only)
            NotificationService::employerEscrowFunded(
                $employerId,
                $payment->reference,
                $milestone->title,
                $contract->title,
                $contract->id,
                $amount
            );

            // Mark that we should broadcast after commit
            $shouldBroadcast = true;
            $freelancerId = $contract->freelancer_id;

            return $payment;
        });

        // Broadcast finance update AFTER transaction commits so the dashboard
        // sees the committed data when it re-fetches.
        if ($shouldBroadcast) {
            \App\Events\FinanceUpdated::dispatch('milestone_funded', [
                'payment_id'      => $payment->id,
                'milestone_id'    => $milestone->id,
                'milestone_title' => $milestone->title,
                'amount'          => $milestone->amount,
                'platform_fee'    => 0,
                'employer_id'     => $employerId,
                'freelancer_id'   => $freelancerId,
            ], $employerId);
        }

        return $payment;
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
        $result = DB::transaction(function () use ($milestone, $actorId) {
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
            $grossAmount = (float) $payment->amount;

            // Calculate platform fee at release time (not at funding time)
            $fees = self::calculateFees($grossAmount);
            $platformFee = $fees['platform_fee'];
            $processingFee = $fees['processing_fee'];
            $releaseAmount = round($grossAmount - $platformFee, 2); // Freelancer gets gross minus platform fee

            // Credit freelancer wallet with net amount
            $wallet = Wallet::forUser($freelancerId);
            $wallet->creditAvailable($releaseAmount);

            // Update the original escrow payment with actual fees (for record-keeping)
            $payment->update([
                'platform_fee'   => $platformFee,
                'processing_fee' => $processingFee,
                'net_amount'     => $releaseAmount,
            ]);

            // Create release payment record
            $releasePayment = Payment::create([
                'reference'          => Payment::generateReference(),
                'payer_id'           => $payment->payer_id,
                'payee_id'           => $freelancerId,
                'milestone_id'       => $milestone->id,
                'type'               => Payment::TYPE_MILESTONE_RELEASED,
                'amount'             => $releaseAmount,
                'platform_fee'       => $platformFee,
                'processing_fee'     => 0,
                'net_amount'         => $releaseAmount,
                'currency'           => 'ETB',
                'status'             => Payment::STATUS_COMPLETED,
                'processed_at'       => now(),
            ]);

            // Record ledger transaction — freelancer receives net amount
            $this->recordLedgerTransaction($freelancerId, $releaseAmount, Transaction::DIR_CREDIT, Transaction::TYPE_FUNDS_RELEASED, $releasePayment->id, "Funds released for milestone \"{$milestone->title}\"");

            // Record platform fee deduction for freelancer visibility
            if ($platformFee > 0) {
                $this->recordLedgerTransaction($freelancerId, $platformFee, Transaction::DIR_DEBIT, Transaction::TYPE_PLATFORM_FEE, $releasePayment->id, "Platform fee deducted for milestone \"{$milestone->title}\"");
            }

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

            // Notify freelancer (in-app + queued email with fee breakdown)
            NotificationService::milestonePaid(
                $freelancerId,
                $milestone->title,
                $contract->id,
                $releaseAmount,
                $platformFee,
                $releasePayment->reference,
                $contract->title,
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

            return [
                'release_payment' => $releasePayment,
                'employer_id'     => $payment->payer_id,
                'freelancer_id'   => $freelancerId,
                'amount'          => $releaseAmount,
            ];
        });

        // Broadcast finance update AFTER transaction commits so the dashboard
        // sees the committed data when it re-fetches.
        \App\Events\FinanceUpdated::dispatch('milestone_released', [
            'payment_id'      => $result['release_payment']->id,
            'milestone_id'    => $milestone->id,
            'milestone_title' => $milestone->title,
            'amount'          => $result['amount'],
            'employer_id'     => $result['employer_id'],
            'freelancer_id'   => $result['freelancer_id'],
        ], $result['employer_id']);

        return $result['release_payment'];
    }

    /**
     * Process a refund.
     *
     * MONEY-INTEGRITY CONTRACT (all-or-nothing):
     *  - The refund payment row, wallet credit, original-payment refund flag
     *    and ledger entry are written inside ONE database transaction with
     *    the wallet row locked, so the wallet balance and the ledger can
     *    never disagree.
     *  - Refunds are WALLET refunds: money returns to the payer's Dream
     *    More wallet, fully within our control. The external provider
     *    (Chapa) is never trusted to move money for a refund; its API
     *    support for refunds is not guaranteed and previously returned
     *    success=false while the caller still announced success.
     *  - Duplicate protection: the original payment row is locked, its
     *    refund flag is checked, AND any prior completed refund payment
     *    (metadata.original_payment_id) blocks a second refund.
     *  - Throws \RuntimeException on any failure; the DB transaction rolls
     *    back, leaving NO refund row, NO wallet credit and NO notification.
     *    Callers must only announce success when this method returns.
     *
     * @throws \RuntimeException
     */
    public function processRefund(Payment $originalPayment, int $actorId, string $reason = ''): Payment
    {
        $refund = DB::transaction(function () use ($originalPayment, $actorId, $reason) {
            // Lock the original payment row so two concurrent refund
            // requests cannot both pass the duplicate checks below.
            $payment = Payment::whereKey($originalPayment->id)->lockForUpdate()->first();

            if (!$payment || $payment->status !== Payment::STATUS_COMPLETED) {
                throw new \RuntimeException('Only completed payments can be refunded.');
            }

            if ($payment->isRefunded()) {
                throw new \RuntimeException('This payment has already been refunded.');
            }

            // Duplicate protection that survives status drift: if ANY prior
            // completed refund payment already exists for this payment, refuse.
            $existingRefund = Payment::where('type', Payment::TYPE_REFUND)
                ->where('status', Payment::STATUS_COMPLETED)
                ->where('metadata->original_payment_id', $payment->id)
                ->exists();

            if ($existingRefund) {
                throw new \RuntimeException('This payment has already been refunded.');
            }

            $refundAmount = (float) $payment->amount;

            // Credit the payer's wallet with row locking BEFORE writing the
            // refund row, so the balance and the refund row commit together.
            // Auto-create the wallet if the payer has none (e.g. legacy payment
            // made before wallets existed) — a refund must never be lost
            // because the wallet row is missing.
            Wallet::forUser($payment->payer_id);
            $wallet = Wallet::where('user_id', $payment->payer_id)->lockForUpdate()->first();

            if (!$wallet) {
                throw new \RuntimeException('Payer wallet could not be created.');
            }

            $balanceBefore = (float) $wallet->available_balance;
            $wallet->increment('available_balance', $refundAmount);
            $wallet->refresh();
            $balanceAfter = (float) $wallet->available_balance;

            // Create the refund payment record
            $refund = Payment::create([
                'reference'          => Payment::generateReference(),
                'payer_id'           => $payment->payer_id,
                'payee_id'           => $payment->payer_id,
                'milestone_id'       => $payment->milestone_id,
                'type'               => Payment::TYPE_REFUND,
                'amount'             => $refundAmount,
                'platform_fee'       => 0,
                'processing_fee'     => 0,
                'net_amount'         => $refundAmount,
                'currency'           => $payment->currency,
                'status'             => Payment::STATUS_COMPLETED,
                'provider_reference' => 'WALLET-REFUND-'.$payment->reference,
                'metadata'           => json_encode([
                    'refund_method'       => 'wallet',
                    'original_payment_id' => $payment->id,
                ]),
                'refunded_at'        => now(),
            ]);

            // Mark original payment refunded
            $payment->update([
                'status'        => Payment::STATUS_REFUNDED,
                'refund_status' => Payment::REFUND_COMPLETED,
                'refunded_at'   => now(),
            ]);

            // Immutable ledger entry with real before/after balances
            $this->recordLedgerTransaction(
                $payment->payer_id,
                $refundAmount,
                Transaction::DIR_CREDIT,
                Transaction::TYPE_REFUND,
                $refund->id,
                "Refund for payment {$payment->reference}".($reason !== '' ? " \u{2014} {$reason}" : ''),
                $balanceBefore,
                $balanceAfter
            );

            return $refund;
        });

        // ── Post-commit: audit + notification + broadcast ────────────────
        // Only reached when the DB transaction COMMITTED successfully.
        AuditService::paymentRefunded($originalPayment->id, $actorId, [
            'reference'        => $originalPayment->reference,
            'refund_reference' => $refund->reference,
            'refund_amount'    => (float) $refund->amount,
            'currency'         => $originalPayment->currency,
            'reason'           => $reason,
            'refund_method'    => 'wallet',
        ]);

        NotificationService::paymentRefunded(
            $originalPayment->payer_id,
            $originalPayment->reference,
            (float) $refund->amount,
            $originalPayment->currency
        );

        \App\Events\FinanceUpdated::dispatch('refund_completed', [
            'payment_id'   => $refund->id,
            'milestone_id' => $originalPayment->milestone_id,
            'amount'       => (float) $refund->amount,
            'employer_id'  => $originalPayment->payer_id,
        ], $actorId);

        return $refund;
    }

    /**
     * Refund a funded milestone back to the employer.
     *
     * Throws when no completed escrow payment exists — a "refund" without
     * funds to return is a FAILURE, never a silent milestone cancellation.
     * On any failure the milestone keeps its current status (e.g. disputed).
     *
     * @throws \RuntimeException
     */
    public function refundMilestone(Milestone $milestone, ?int $actorId, string $reason = ''): Payment
    {
        return DB::transaction(function () use ($milestone, $actorId, $reason) {
            $payment = Payment::where('milestone_id', $milestone->id)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->where('status', Payment::STATUS_COMPLETED)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new \RuntimeException(
                    "Cannot refund milestone \"{$milestone->title}\": no completed escrow payment exists for it."
                );
            }

            $refund = $this->processRefund($payment, $actorId ?? 0, $reason);

            // Only reached on successful refund — cancel the milestone.
            $milestone->update(['status' => Milestone::STATUS_CANCELLED]);

            return $refund;
        });
    }

    /**
     * Charge an employer for featuring a job listing.
     *
     * 1. Check feature flag is enabled
     * 2. Check wallet has sufficient balance
     * 3. Deduct from wallet with row locking
     * 4. Create FeaturedJob record
     * 5. Record transaction ledger entry
     */
    public function chargeForFeaturedJob(int $jobId, int $employerId): \App\Models\FeaturedJob
    {
        // Check feature flag
        $enabled = \App\Models\AdminSetting::getValue('featured_jobs_enabled', 'false', 'boolean');
        if (!$enabled) {
            throw new \RuntimeException('This feature is not currently available.');
        }

        // Get pricing settings
        $price = (float) \App\Models\AdminSetting::getValue('featured_job_price', '150', 'string');
        $durationDays = (int) \App\Models\AdminSetting::getValue('featured_job_duration_days', '7', 'integer');

        if ($price <= 0) {
            throw new \RuntimeException('Featured job pricing is not configured.');
        }

        $featuredJob = DB::transaction(function () use ($jobId, $employerId, $price, $durationDays) {
            // Ensure wallet exists (auto-create with 0 balance if needed)
            Wallet::forUser($employerId);

            // Lock wallet row to prevent race conditions
            $wallet = Wallet::where('user_id', $employerId)->lockForUpdate()->first();

            if ((float) $wallet->available_balance < $price) {
                throw new \RuntimeException(
                    "Insufficient balance. Required: {$price} ETB, Available: {$wallet->available_balance} ETB."
                );
            }

            // Deduct from wallet
            $wallet->decrement('available_balance', $price);

            // Create FeaturedJob record
            $featuredJob = \App\Models\FeaturedJob::create([
                'job_id'        => $jobId,
                'employer_id'   => $employerId,
                'amount_paid'   => $price,
                'duration_days' => $durationDays,
                'starts_at'     => now(),
                'expires_at'    => now()->addDays($durationDays),
                'status'        => \App\Models\FeaturedJob::STATUS_ACTIVE,
            ]);

            // Record transaction — debit from employer
            $this->recordLedgerTransaction(
                $employerId,
                $price,
                Transaction::DIR_DEBIT,
                Transaction::TYPE_FEATURED_JOB_FEE,
                null,
                "Featured job listing fee for job #{$jobId} ({$durationDays} days)"
            );

            Log::info('[Featured Job] Employer charged', [
                'job_id'      => $jobId,
                'employer_id' => $employerId,
                'amount'      => $price,
                'duration'    => $durationDays,
            ]);

            return $featuredJob;
        });

        // Broadcast AFTER the transaction commits so the admin finance dashboard
        // (single platform balance card) refreshes when featured revenue is received.
        \App\Events\FinanceUpdated::dispatch('featured_job_charged', [
            'featured_job_id' => $featuredJob->id,
            'job_id'          => $jobId,
            'amount'          => (float) $featuredJob->amount_paid,
            'user_id'         => $employerId,
        ], $employerId);

        return $featuredJob;
    }

    /**
     * Charge a freelancer for featuring their profile.
     *
     * 1. Check feature flag is enabled
     * 2. Check wallet has sufficient balance
     * 3. Deduct from wallet with row locking
     * 4. Create FeaturedProfile record
     * 5. Record transaction ledger entry
     */
    public function chargeForFeaturedProfile(int $userId): \App\Models\FeaturedProfile
    {
        // Check feature flag
        $enabled = \App\Models\AdminSetting::getValue('featured_profiles_enabled', 'false', 'boolean');
        if (!$enabled) {
            throw new \RuntimeException('This feature is not currently available.');
        }

        // Get pricing settings
        $price = (float) \App\Models\AdminSetting::getValue('featured_profile_price', '100', 'string');
        $durationDays = (int) \App\Models\AdminSetting::getValue('featured_profile_duration_days', '7', 'integer');

        if ($price <= 0) {
            throw new \RuntimeException('Featured profile pricing is not configured.');
        }

        $featuredProfile = DB::transaction(function () use ($userId, $price, $durationDays) {
            // Ensure wallet exists (auto-create with 0 balance if needed)
            Wallet::forUser($userId);

            // Lock wallet row to prevent race conditions
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();

            if ((float) $wallet->available_balance < $price) {
                throw new \RuntimeException(
                    "Insufficient balance. Required: {$price} ETB, Available: {$wallet->available_balance} ETB."
                );
            }

            // Deduct from wallet
            $wallet->decrement('available_balance', $price);

            // Create FeaturedProfile record
            $featuredProfile = \App\Models\FeaturedProfile::create([
                'user_id'       => $userId,
                'amount_paid'   => $price,
                'duration_days' => $durationDays,
                'starts_at'     => now(),
                'expires_at'    => now()->addDays($durationDays),
                'status'        => \App\Models\FeaturedProfile::STATUS_ACTIVE,
            ]);

            // Record transaction — debit from freelancer
            $this->recordLedgerTransaction(
                $userId,
                $price,
                Transaction::DIR_DEBIT,
                Transaction::TYPE_FEATURED_PROFILE_FEE,
                null,
                "Featured profile listing fee ({$durationDays} days)"
            );

            Log::info('[Featured Profile] Freelancer charged', [
                'user_id' => $userId,
                'amount'  => $price,
                'duration' => $durationDays,
            ]);

            return $featuredProfile;
        });

        // Broadcast AFTER the transaction commits so the admin finance dashboard
        // (single platform balance card) refreshes when featured revenue is received.
        \App\Events\FinanceUpdated::dispatch('featured_profile_charged', [
            'featured_profile_id' => $featuredProfile->id,
            'user_id'             => $userId,
            'amount'              => (float) $featuredProfile->amount_paid,
        ], $userId);

        return $featuredProfile;
    }

    /**
     * Record a ledger transaction.
     *
     * $balanceBefore/$balanceAfter are optional: when provided (by
     * balance-mutating flows like refunds) they record the REAL wallet
     * balances observed inside the same locked transaction. When omitted,
     * the current balance is used for both (legacy behavior for flows that
     * write the ledger before mutating the wallet).
     */
    private function recordLedgerTransaction(
        int $userId,
        float $amount,
        string $direction,
        string $type,
        ?int $paymentId = null,
        string $description = '',
        ?float $balanceBefore = null,
        ?float $balanceAfter = null
    ): void {
        $wallet = Wallet::forUser($userId);
        $currentBalance = (float) ($wallet->available_balance ?? 0.00);

        Transaction::create([
            'reference'      => Transaction::generateReference(),
            'user_id'        => $userId,
            'payment_id'     => $paymentId,
            'wallet_id'      => $wallet->id,
            'direction'      => $direction,
            'type'           => $type,
            'amount'         => $amount,
            'balance_before' => $balanceBefore ?? $currentBalance,
            'balance_after'  => $balanceAfter ?? $currentBalance,
            'currency'       => 'ETB',
            'status'         => Transaction::STATUS_COMPLETED,
            'description'    => $description,
        ]);
    }
}
