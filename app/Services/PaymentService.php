<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\Payment\ManualPaymentProvider;
use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\ProviderResult;
use Illuminate\Support\Facades\DB;

/**
 * PaymentService — the single entry point for all payment operations.
 *
 * Architecture:
 *   PaymentService
 *     └─ resolveProvider()           reads PAYMENT_PROVIDER env var
 *           └─ PaymentProviderInterface
 *                 └─ ManualPaymentProvider   (foundation)
 *                 └─ ChapaPaymentProvider    (Stage 2 — not yet built)
 *
 * All financial mutations run inside DB transactions to keep the
 * payments + transactions tables consistent.
 */
class PaymentService
{
    // ── Provider resolution ──────────────────────────────────────────────

    private static function resolveProvider(): PaymentProviderInterface
    {
        $slug = config('payment.provider', env('PAYMENT_PROVIDER', 'manual'));

        return match ($slug) {
            // Add 'chapa' => new ChapaPaymentProvider() in Stage 2
            default => new ManualPaymentProvider(),
        };
    }

    // ── Payment Method management ────────────────────────────────────────

    /**
     * Add a new payment method for a user.
     * Full card/account numbers must NEVER be passed here — only masked data.
     */
    public static function addPaymentMethod(int $userId, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($userId, $data) {
            // If this is set as default, clear previous defaults.
            if (!empty($data['is_default'])) {
                PaymentMethod::where('user_id', $userId)->update(['is_default' => false]);
            }

            $method = PaymentMethod::create(array_merge($data, ['user_id' => $userId]));

            // If this is the user's first method, auto-default it.
            if (PaymentMethod::where('user_id', $userId)->count() === 1) {
                $method->update(['is_default' => true]);
            }

            return $method;
        });
    }

    /**
     * Set a payment method as the user's default.
     */
    public static function setDefaultPaymentMethod(int $userId, int $methodId): PaymentMethod
    {
        return DB::transaction(function () use ($userId, $methodId) {
            $method = PaymentMethod::where('id', $methodId)
                ->where('user_id', $userId)
                ->firstOrFail();

            PaymentMethod::where('user_id', $userId)->update(['is_default' => false]);
            $method->update(['is_default' => true]);

            return $method->fresh();
        });
    }

    /**
     * Remove a payment method (cannot remove a method used in pending payments).
     */
    public static function removePaymentMethod(int $userId, int $methodId): void
    {
        $method = PaymentMethod::where('id', $methodId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Safety check: don't delete if there are pending payments using it.
        $hasPending = Payment::where('payment_method_id', $methodId)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->exists();

        if ($hasPending) {
            throw new \RuntimeException('Cannot remove a payment method with pending payments.');
        }

        DB::transaction(function () use ($method, $userId) {
            $wasDefault = $method->is_default;
            $method->delete();

            // Auto-assign default to the next available method.
            if ($wasDefault) {
                $next = PaymentMethod::where('user_id', $userId)->latest()->first();
                $next?->update(['is_default' => true]);
            }
        });
    }

    // ── Escrow / Milestone funding ───────────────────────────────────────

    /**
     * Employer funds a milestone into escrow.
     *
     * Creates a Payment (escrow_funded) and two Transaction rows
     * (debit for employer, credit placeholder pending release).
     *
     * @return Payment The completed escrow payment record.
     */
    public static function fundMilestoneEscrow(
        Contract      $contract,
        Milestone     $milestone,
        ?PaymentMethod $paymentMethod = null,
        int           $actorId = 0,
    ): Payment {
        if ($milestone->isEscrowFunded()) {
            throw new \RuntimeException('This milestone has already been funded.');
        }

        if ((float) $milestone->amount <= 0) {
            throw new \RuntimeException('Milestone amount must be greater than zero.');
        }

        $provider = self::resolveProvider();
        $fee      = round((float) $milestone->amount * Payment::FEE_RATE, 2);
        $net      = (float) $milestone->amount - $fee;

        return DB::transaction(function () use (
            $contract, $milestone, $paymentMethod, $fee, $net, $provider, $actorId
        ) {
            // 1. Create the payment record.
            $payment = Payment::create([
                'payer_id'          => $contract->employer_id,
                'payee_id'          => $contract->freelancer_id,
                'contract_id'       => $contract->id,
                'milestone_id'      => $milestone->id,
                'payment_method_id' => $paymentMethod?->id,
                'type'              => Payment::TYPE_ESCROW_FUNDED,
                'amount'            => $milestone->amount,
                'fee'               => $fee,
                'net_amount'        => $net,
                'currency'          => $contract->proposal?->currency ?? 'ETB',
                'status'            => Payment::STATUS_PROCESSING,
                'provider'          => $provider->getSlug(),
                'description'       => "Escrow funding for milestone: {$milestone->title}",
            ]);

            // 2. Call the gateway.
            $result = $provider->charge($payment);

            if (!$result->success) {
                $payment->update([
                    'status'         => Payment::STATUS_FAILED,
                    'failed_at'      => now(),
                    'failure_reason' => $result->message,
                    'provider_response' => $result->raw,
                ]);
                throw new \RuntimeException("Payment failed: {$result->message}");
            }

            // 3. Mark payment completed.
            $payment->update([
                'status'             => Payment::STATUS_COMPLETED,
                'provider_reference' => $result->reference,
                'provider_response'  => $result->raw,
                'processed_at'       => now(),
            ]);

            // 4. Create a debit transaction for the employer.
            Transaction::create([
                'payment_id'  => $payment->id,
                'user_id'     => $contract->employer_id,
                'direction'   => Transaction::DIRECTION_DEBIT,
                'type'        => Payment::TYPE_ESCROW_FUNDED,
                'amount'      => $milestone->amount,
                'fee'         => $fee,
                'currency'    => $payment->currency,
                'status'      => Payment::STATUS_COMPLETED,
                'description' => "Escrow funded for milestone: {$milestone->title}",
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
            ]);

            // 5. Mark the milestone as escrow-funded.
            $milestone->update([
                'escrow_funded_at' => now(),
                'payment_id'       => $payment->id,
            ]);

            // 6. Audit.
            AuditService::log(
                \App\Models\AuditLog::ACTION_MILESTONE_FUNDED,
                \App\Models\AuditLog::MODULE_MILESTONES,
                'Milestone', $milestone->id,
                ['contract_id' => $contract->id, 'amount' => $milestone->amount],
                $actorId,
                "Employer funded escrow for milestone \"{$milestone->title}\" — {$payment->currency} {$milestone->amount}"
            );

            return $payment;
        });
    }

    /**
     * Release an approved milestone's escrow to the freelancer.
     *
     * Transitions milestone status → 'paid'.
     * Creates a credit transaction for the freelancer.
     *
     * @return Payment The release payment record.
     */
    public static function releaseMilestonePayment(
        Contract  $contract,
        Milestone $milestone,
        int       $actorId = 0,
    ): Payment {
        if (!$milestone->isEscrowFunded()) {
            throw new \RuntimeException('Milestone escrow has not been funded.');
        }

        if ($milestone->status !== 'approved') {
            throw new \RuntimeException('Only approved milestones can be released.');
        }

        $provider = self::resolveProvider();
        $fee      = round((float) $milestone->amount * Payment::FEE_RATE, 2);
        $net      = (float) $milestone->amount - $fee;

        return DB::transaction(function () use (
            $contract, $milestone, $fee, $net, $provider, $actorId
        ) {
            // 1. Create the release payment.
            $payment = Payment::create([
                'payer_id'     => $contract->employer_id,
                'payee_id'     => $contract->freelancer_id,
                'contract_id'  => $contract->id,
                'milestone_id' => $milestone->id,
                'type'         => Payment::TYPE_MILESTONE_RELEASED,
                'amount'       => $milestone->amount,
                'fee'          => $fee,
                'net_amount'   => $net,
                'currency'     => $contract->proposal?->currency ?? 'ETB',
                'status'       => Payment::STATUS_PROCESSING,
                'provider'     => $provider->getSlug(),
                'description'  => "Payment release for milestone: {$milestone->title}",
            ]);

            // 2. Call the gateway.
            $result = $provider->charge($payment);

            if (!$result->success) {
                $payment->update([
                    'status'            => Payment::STATUS_FAILED,
                    'failed_at'         => now(),
                    'failure_reason'    => $result->message,
                    'provider_response' => $result->raw,
                ]);
                throw new \RuntimeException("Release failed: {$result->message}");
            }

            $payment->update([
                'status'             => Payment::STATUS_COMPLETED,
                'provider_reference' => $result->reference,
                'provider_response'  => $result->raw,
                'processed_at'       => now(),
            ]);

            // 3. Create a credit transaction for the freelancer (net after fee).
            Transaction::create([
                'payment_id'   => $payment->id,
                'user_id'      => $contract->freelancer_id,
                'direction'    => Transaction::DIRECTION_CREDIT,
                'type'         => Payment::TYPE_MILESTONE_RELEASED,
                'amount'       => $net,
                'fee'          => $fee,
                'currency'     => $payment->currency,
                'status'       => Payment::STATUS_COMPLETED,
                'description'  => "Payment received for milestone: {$milestone->title}",
                'contract_id'  => $contract->id,
                'milestone_id' => $milestone->id,
            ]);

            // 4. Mark the milestone as paid.
            $milestone->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            // 5. Update freelancer total_earnings and employer total_spent.
            \App\Models\FreelancerProfile::where('user_id', $contract->freelancer_id)
                ->increment('total_earnings', $net);
            \App\Models\EmployerProfile::where('user_id', $contract->employer_id)
                ->increment('total_spent', (float) $milestone->amount);

            // 6. Send notification to freelancer.
            \App\Services\NotificationService::milestonePaid(
                $contract->freelancer_id,
                $milestone->title,
                $contract->id,
                $net,
            );

            // 7. Audit.
            AuditService::log(
                \App\Models\AuditLog::ACTION_MILESTONE_RELEASED,
                \App\Models\AuditLog::MODULE_MILESTONES,
                'Milestone', $milestone->id,
                ['contract_id' => $contract->id, 'net_amount' => $net],
                $actorId,
                "Released payment for milestone \"{$milestone->title}\" — {$payment->currency} {$net} to freelancer"
            );

            return $payment;
        });
    }

    // ── User: request a refund on an escrow payment ──────────────────────

    /**
     * Employer requests a refund on a completed escrow payment.
     *
     * The refund is NOT immediate — it queues a refund_status=requested record
     * for admin review. This prevents self-service fund recovery after a
     * milestone has been funded.
     *
     * Only the original payer (employer) may request a refund.
     * The milestone must NOT be in 'submitted' or 'approved' status
     * (i.e. the freelancer must not have done work that was reviewed).
     */
    public static function requestRefund(
        Payment $payment,
        int     $requesterId,
        string  $reason,
    ): Payment {
        if (!$payment->isRefundable()) {
            throw new \RuntimeException(
                'This payment is not eligible for a refund request. '
                . 'It may have already been refunded or is not in a refundable state.'
            );
        }

        if ($payment->payer_id !== $requesterId) {
            throw new \RuntimeException('Only the original payer may request a refund.');
        }

        // Block refund if the milestone work is already submitted/approved/paid.
        if ($payment->milestone_id) {
            $milestone = \App\Models\Milestone::find($payment->milestone_id);
            if ($milestone && in_array($milestone->status, ['submitted', 'approved', 'paid'], true)) {
                throw new \RuntimeException(
                    "A refund cannot be requested because the milestone is already '{$milestone->status}'. "
                    . 'Contact support if you have a dispute about the work quality.'
                );
            }
        }

        DB::transaction(function () use ($payment, $requesterId, $reason) {
            $payment->update([
                'refund_status'       => Payment::REFUND_STATUS_REQUESTED,
                'refund_reason'       => $reason,
                'refund_requested_at' => now(),
                'refund_requested_by' => $requesterId,
            ]);

            // If payment is disputed, mark milestone as such.
            if ($payment->milestone_id) {
                \App\Models\Milestone::where('id', $payment->milestone_id)
                    ->whereIn('status', ['pending', 'in_progress', 'revision_requested'])
                    ->update(['status' => 'pending']); // hold in pending
            }

            // Notify admins.
            \App\Services\NotificationService::notifyAdmins(
                'refund_requested',
                'Refund Request Received',
                "A refund has been requested for payment {$payment->reference}. Reason: {$reason}",
                '/admin/payments'
            );

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_REFUNDED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                [
                    'reference' => $payment->reference,
                    'reason'    => $reason,
                    'action'    => 'refund_requested',
                ],
                $requesterId,
                "User #{$requesterId} requested refund for payment {$payment->reference}: {$reason}"
            );
        });

        return $payment->fresh();
    }

    /**
     * Admin approves a pending refund request and executes the refund.
     */
    public static function approveRefund(
        Payment $payment,
        int     $adminId,
        ?float  $amount = null,
        string  $note   = '',
    ): Payment {
        if ($payment->refund_status !== Payment::REFUND_STATUS_REQUESTED) {
            throw new \RuntimeException('This payment does not have a pending refund request.');
        }

        $refundAmount = $amount ?? (float) $payment->amount;
        $provider     = self::resolveProvider();

        return DB::transaction(function () use ($payment, $adminId, $refundAmount, $note, $provider) {
            // Mark request as approved.
            $payment->update([
                'refund_status'      => Payment::REFUND_STATUS_APPROVED,
                'refund_approved_at' => now(),
                'refund_approved_by' => $adminId,
            ]);

            $result = $provider->refund($payment, $refundAmount, $note ?: ($payment->refund_reason ?? 'Admin approved'));

            if (!$result->success) {
                $payment->update(['refund_status' => Payment::REFUND_STATUS_FAILED]);
                throw new \RuntimeException("Refund processing failed: {$result->message}");
            }

            // Create the refund payment record.
            $refund = Payment::create([
                'payer_id'           => $payment->payee_id,
                'payee_id'           => $payment->payer_id,
                'contract_id'        => $payment->contract_id,
                'milestone_id'       => $payment->milestone_id,
                'type'               => Payment::TYPE_REFUND,
                'amount'             => $refundAmount,
                'fee'                => 0,
                'net_amount'         => $refundAmount,
                'currency'           => $payment->currency,
                'status'             => Payment::STATUS_COMPLETED,
                'provider'           => $provider->getSlug(),
                'provider_reference' => $result->reference,
                'provider_response'  => $result->raw,
                'description'        => "Refund for {$payment->reference}" . ($note ? ": {$note}" : ''),
                'admin_notes'        => "Approved by admin #{$adminId}",
                'processed_at'       => now(),
                'refund_status'      => Payment::REFUND_STATUS_COMPLETED,
            ]);

            // Credit transaction for the original payer (money back to employer).
            if ($payment->payer_id) {
                Transaction::create([
                    'payment_id'   => $refund->id,
                    'user_id'      => $payment->payer_id,
                    'direction'    => Transaction::DIRECTION_CREDIT,
                    'type'         => Payment::TYPE_REFUND,
                    'amount'       => $refundAmount,
                    'fee'          => 0,
                    'currency'     => $payment->currency,
                    'status'       => Payment::STATUS_COMPLETED,
                    'description'  => "Refund received for payment {$payment->reference}",
                    'contract_id'  => $payment->contract_id,
                    'milestone_id' => $payment->milestone_id,
                ]);
            }

            // Mark original payment as refunded and request as completed.
            $payment->update([
                'status'        => Payment::STATUS_REFUNDED,
                'refund_status' => Payment::REFUND_STATUS_COMPLETED,
            ]);

            // Reset milestone to pending so it can be re-funded if needed.
            if ($payment->milestone_id) {
                \App\Models\Milestone::where('id', $payment->milestone_id)
                    ->update([
                        'escrow_funded_at' => null,
                        'payment_id'       => null,
                        'status'           => 'pending',
                    ]);
            }

            // Notify the employer.
            if ($payment->payer_id) {
                \App\Services\NotificationService::paymentRefunded(
                    $payment->payer_id,
                    $payment->reference,
                    $refundAmount,
                    $payment->currency
                );
            }

            AuditService::paymentRefunded($payment->id, $adminId, [
                'reference'     => $payment->reference,
                'refund_amount' => $refundAmount,
                'reason'        => $note ?: $payment->refund_reason,
                'currency'      => $payment->currency,
            ]);

            return $refund;
        });
    }

    /**
     * Admin rejects a pending refund request.
     */
    public static function rejectRefund(
        Payment $payment,
        int     $adminId,
        string  $reason = '',
    ): Payment {
        if ($payment->refund_status !== Payment::REFUND_STATUS_REQUESTED) {
            throw new \RuntimeException('This payment does not have a pending refund request.');
        }

        DB::transaction(function () use ($payment, $adminId, $reason) {
            $payment->update([
                'refund_status'      => Payment::REFUND_STATUS_REJECTED,
                'refund_approved_at' => now(),
                'refund_approved_by' => $adminId,
                'admin_notes'        => "Refund rejected by admin #{$adminId}" . ($reason ? ": {$reason}" : ''),
            ]);

            // Notify the employer.
            if ($payment->payer_id) {
                \App\Services\NotificationService::create(
                    $payment->payer_id,
                    'refund_rejected',
                    'Refund Request Rejected',
                    "Your refund request for payment {$payment->reference} has been rejected." . ($reason ? " Reason: {$reason}" : ''),
                    '/employer/payments'
                );
            }

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_REFUNDED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                ['reference' => $payment->reference, 'action' => 'refund_rejected', 'reason' => $reason],
                $adminId,
                "Admin #{$adminId} rejected refund request for {$payment->reference}"
            );
        });

        return $payment->fresh();
    }

    /**
     * Mark a payment as disputed (called when a contract dispute is raised
     * and there are associated funded milestones).
     */
    public static function markAsDisputed(Payment $payment, int $actorId): Payment
    {
        if (!in_array($payment->status, [Payment::STATUS_COMPLETED, Payment::STATUS_PROCESSING], true)) {
            return $payment; // Already terminal state; skip silently.
        }

        DB::transaction(function () use ($payment, $actorId) {
            $payment->update(['status' => Payment::STATUS_DISPUTED]);

            // Mirror on transactions.
            Transaction::where('payment_id', $payment->id)
                ->update(['status' => Payment::STATUS_DISPUTED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_VERIFIED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Payment', $payment->id,
                ['reference' => $payment->reference, 'action' => 'marked_disputed'],
                $actorId,
                "Payment {$payment->reference} marked as disputed"
            );
        });

        return $payment->fresh();
    }

    // ── Admin: verify a payment ──────────────────────────────────────────

    public static function adminVerify(Payment $payment, int $adminId): Payment
    {
        if ($payment->status !== Payment::STATUS_PENDING) {
            throw new \RuntimeException('Only pending payments can be verified.');
        }

        DB::transaction(function () use ($payment, $adminId) {
            $payment->update([
                'status'       => Payment::STATUS_COMPLETED,
                'processed_at' => now(),
                'admin_notes'  => "Manually verified by admin #{$adminId} at " . now()->toDateTimeString(),
            ]);

            // Mirror status on all related transactions.
            Transaction::where('payment_id', $payment->id)
                ->update(['status' => Payment::STATUS_COMPLETED]);

            AuditService::paymentVerified($payment->id, $adminId, [
                'reference' => $payment->reference,
                'amount'    => $payment->amount,
                'currency'  => $payment->currency,
            ]);
        });

        return $payment->fresh();
    }

    // ── Admin: refund a payment ──────────────────────────────────────────

    public static function adminRefund(
        Payment $payment,
        int     $adminId,
        ?float  $amount = null,
        string  $reason = '',
    ): Payment {
        if (!$payment->isRefundable()) {
            throw new \RuntimeException('This payment is not eligible for a refund.');
        }

        $refundAmount = $amount ?? (float) $payment->amount;
        $provider     = self::resolveProvider();

        return DB::transaction(function () use ($payment, $adminId, $refundAmount, $reason, $provider) {
            $result = $provider->refund($payment, $refundAmount, $reason);

            if (!$result->success) {
                throw new \RuntimeException("Refund failed: {$result->message}");
            }

            // Create a refund payment record.
            $refund = Payment::create([
                'payer_id'           => $payment->payee_id,   // money flows back
                'payee_id'           => $payment->payer_id,
                'contract_id'        => $payment->contract_id,
                'milestone_id'       => $payment->milestone_id,
                'type'               => Payment::TYPE_REFUND,
                'amount'             => $refundAmount,
                'fee'                => 0,
                'net_amount'         => $refundAmount,
                'currency'           => $payment->currency,
                'status'             => Payment::STATUS_COMPLETED,
                'provider'           => $provider->getSlug(),
                'provider_reference' => $result->reference,
                'provider_response'  => $result->raw,
                'description'        => "Refund for payment {$payment->reference}" . ($reason ? ": {$reason}" : ''),
                'admin_notes'        => "Issued by admin #{$adminId}",
                'processed_at'       => now(),
            ]);

            // Credit transaction for payer (they get their money back).
            if ($payment->payer_id) {
                Transaction::create([
                    'payment_id'   => $refund->id,
                    'user_id'      => $payment->payer_id,
                    'direction'    => Transaction::DIRECTION_CREDIT,
                    'type'         => Payment::TYPE_REFUND,
                    'amount'       => $refundAmount,
                    'fee'          => 0,
                    'currency'     => $payment->currency,
                    'status'       => Payment::STATUS_COMPLETED,
                    'description'  => "Refund received for payment {$payment->reference}",
                    'contract_id'  => $payment->contract_id,
                    'milestone_id' => $payment->milestone_id,
                ]);
            }

            // Mark original payment as refunded.
            $payment->update(['status' => Payment::STATUS_REFUNDED]);

            AuditService::paymentRefunded($payment->id, $adminId, [
                'reference'     => $payment->reference,
                'refund_amount' => $refundAmount,
                'reason'        => $reason,
                'currency'      => $payment->currency,
            ]);

            return $refund;
        });
    }

    // ── Balance summary for a user ───────────────────────────────────────

    public static function getBalanceSummary(int $userId): array
    {
        $totalEarned = Transaction::where('user_id', $userId)
            ->where('direction', Transaction::DIRECTION_CREDIT)
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereNotIn('type', [Payment::TYPE_REFUND])
            ->sum('amount');

        $totalSpent = Transaction::where('user_id', $userId)
            ->where('direction', Transaction::DIRECTION_DEBIT)
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereNotIn('type', [Payment::TYPE_REFUND])
            ->sum('amount');

        $pendingIn = Transaction::where('user_id', $userId)
            ->where('direction', Transaction::DIRECTION_CREDIT)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->sum('amount');

        // Freelancer: pending earnings = escrow funded but not yet released milestones.
        // We look at milestones where escrow_funded_at is set but paid_at is null,
        // and this user is the freelancer on the contract.
        $pendingEscrow = \App\Models\Milestone::whereNotNull('escrow_funded_at')
            ->whereNull('paid_at')
            ->whereHas('contract', fn ($q) => $q->where('freelancer_id', $userId))
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->sum('amount');

        // Net amount for pending escrow (after fee deduction).
        $pendingEscrowNet = round((float) $pendingEscrow * (1 - Payment::FEE_RATE), 2);

        // Employer: total held in escrow (funded but not released).
        $totalHeldInEscrow = Payment::where('payer_id', $userId)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereHas('milestone', fn ($q) => $q->whereNull('paid_at'))
            ->sum('amount');

        $totalRefunded = Transaction::where('user_id', $userId)
            ->where('direction', Transaction::DIRECTION_CREDIT)
            ->where('type', Payment::TYPE_REFUND)
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');

        return [
            'available_balance'   => max(0.0, (float) $totalEarned - (float) $totalSpent),
            'pending_in'          => (float) $pendingIn,
            'pending_escrow_net'  => (float) $pendingEscrowNet,  // freelancer: awaiting release
            'total_held_in_escrow'=> (float) $totalHeldInEscrow, // employer: held funds
            'total_earned'        => (float) $totalEarned,
            'total_spent'         => (float) $totalSpent,
            'total_refunded'      => (float) $totalRefunded,
            'currency'            => 'ETB',
        ];
    }
}
