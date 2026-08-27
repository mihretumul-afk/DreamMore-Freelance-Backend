<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Services\Payment\ChapaPaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WithdrawalService — handles freelancer withdrawal requests.
 *
 * Withdrawals are NOT automatic. Each request creates a pending record
 * that must be processed by a Finance Admin or through provider callbacks.
 */
class WithdrawalService
{
    /**
     * Request a withdrawal from the freelancer's available balance.
     */
    public static function requestWithdrawal(
        int    $userId,
        float  $amount,
        ?int   $paymentMethodId = null,
    ): Withdrawal {
        // Validate amount
        $minWithdrawal = config('payment.min_withdrawal', 100);
        if ($amount < $minWithdrawal) {
            throw new \RuntimeException("Minimum withdrawal amount is ETB {$minWithdrawal}.");
        }

        // Check available balance
        $balanceSummary = PaymentService::getBalanceSummary($userId);
        $available = $balanceSummary['available_earnings'] ?? 0;

        if ($amount > $available) {
            throw new \RuntimeException(
                "Insufficient balance. Available: ETB " . number_format($available, 2)
                . ". You requested: ETB " . number_format($amount, 2) . "."
            );
        }

        // Prevent duplicate withdrawal requests
        $pendingExists = Withdrawal::where('user_id', $userId)
            ->whereIn('status', [Withdrawal::STATUS_REQUESTED, Withdrawal::STATUS_PROCESSING])
            ->exists();

        if ($pendingExists) {
            throw new \RuntimeException('You already have a pending withdrawal request. Please wait for it to be processed.');
        }

        // Validate payment method
        $paymentMethod = null;
        if ($paymentMethodId) {
            $paymentMethod = PaymentMethod::where('id', $paymentMethodId)
                ->where('user_id', $userId)
                ->first();

            if (!$paymentMethod) {
                throw new \RuntimeException('Payment method not found.');
            }
        } else {
            $paymentMethod = PaymentMethod::where('user_id', $userId)
                ->where('is_default', true)
                ->first();
        }

        // Calculate fee
        $withdrawalFeeRate = config('payment.withdrawal_fee_rate', 0.01);
        $fee     = round($amount * $withdrawalFeeRate, 2);
        $net     = $amount - $fee;

        $withdrawal = DB::transaction(function () use ($userId, $amount, $fee, $net, $paymentMethod) {
            // CRITICAL: Re-check for pending withdrawals inside transaction with lock
            $pendingExists = Withdrawal::where('user_id', $userId)
                ->whereIn('status', [Withdrawal::STATUS_REQUESTED, Withdrawal::STATUS_PROCESSING])
                ->lockForUpdate()
                ->exists();

            if ($pendingExists) {
                throw new \RuntimeException('You already have a pending withdrawal request. Please wait for it to be processed.');
            }

            $w = Withdrawal::create([
                'user_id'           => $userId,
                'payment_method_id' => $paymentMethod?->id,
                'amount'            => $amount,
                'fee'               => $fee,
                'net_amount'        => $net,
                'currency'          => 'ETB',
                'status'            => Withdrawal::STATUS_REQUESTED,
                'provider'          => config('payment.provider', 'manual'),
            ]);

            // Create a debit transaction to reserve the amount
            Transaction::create([
                'payment_id'  => null,
                'user_id'     => $userId,
                'direction'   => Transaction::DIRECTION_DEBIT,
                'type'        => 'withdrawal',
                'amount'      => $amount,
                'fee'         => $fee,
                'currency'    => 'ETB',
                'status'      => Payment::STATUS_PENDING,
                'description' => "Withdrawal request: {$w->reference}",
            ]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $w->id,
                ['reference' => $w->reference, 'amount' => $amount, 'fee' => $fee],
                $userId,
                "User #{$userId} requested withdrawal of ETB {$amount} ({$w->reference})"
            );

            return $w;
        });

        // Send notification (outside transaction to avoid rolling back on email failure)
        $withdrawal->refresh();
        NotificationService::withdrawalRequested(
            $userId,
            $withdrawal->reference,
            $amount,
            $fee,
            $net,
            $paymentMethod?->getDisplayLabel()
        );

        // Notify finance admins about the withdrawal request
        NotificationService::notifyAdmins(
            'withdrawal_requested',
            'Withdrawal Requested',
            "User #{$userId} has requested a withdrawal of ETB " . number_format($amount, 2) . " (Reference: {$withdrawal->reference}).",
            '/admin/withdrawals'
        );

        return $withdrawal->fresh();
    }

    /**
     * Process a withdrawal (called by admin or provider callback).
     */
    public static function processWithdrawal(
        Withdrawal $withdrawal,
        int        $adminId,
        ?string    $note = null,
    ): Withdrawal {
        if ($withdrawal->status !== Withdrawal::STATUS_REQUESTED) {
            throw new \RuntimeException('This withdrawal has already been processed.');
        }

        $provider = PaymentService::getProvider();

        return DB::transaction(function () use ($withdrawal, $adminId, $note, $provider) {
            $withdrawal->update([
                'status'       => Withdrawal::STATUS_PROCESSING,
                'processed_by' => $adminId,
                'processed_at' => now(),
                'admin_notes'  => $note,
            ]);

            // In manual mode, immediately complete
            if ($provider->getSlug() === 'manual') {
                return self::completeWithdrawal($withdrawal, $adminId, 'Manual processing by admin');
            }

            // For real providers, mark as processing and wait for webhook/callback
            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $withdrawal->id,
                ['reference' => $withdrawal->reference, 'action' => 'processing'],
                $adminId,
                "Admin #{$adminId} initiated withdrawal processing for {$withdrawal->reference}"
            );

            return $withdrawal->fresh();
        });
    }

    /**
     * Mark a withdrawal as completed.
     */
    public static function completeWithdrawal(
        Withdrawal $withdrawal,
        int        $adminId,
        ?string    $note = null,
    ): Withdrawal {
        $result = DB::transaction(function () use ($withdrawal, $adminId, $note) {
            $withdrawal->update([
                'status'       => Withdrawal::STATUS_COMPLETED,
                'completed_at' => now(),
                'admin_notes'  => $note,
            ]);

            // Update the corresponding debit transaction to completed
            Transaction::where('user_id', $withdrawal->user_id)
                ->where('type', 'withdrawal')
                ->where('status', Payment::STATUS_PENDING)
                ->where('description', 'like', "%{$withdrawal->reference}%")
                ->update(['status' => Payment::STATUS_COMPLETED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $withdrawal->id,
                ['reference' => $withdrawal->reference, 'action' => 'completed', 'amount' => $withdrawal->net_amount],
                $adminId,
                "Withdrawal {$withdrawal->reference} completed — ETB {$withdrawal->net_amount} disbursed"
            );

            return $withdrawal->fresh();
        });

        // Notify the freelancer
        NotificationService::withdrawalCompleted(
            $result->user_id,
            $result->reference,
            $result->amount,
            $result->fee,
            $result->net_amount,
            $result->paymentMethod?->getDisplayLabel()
        );

        return $result;
    }

    /**
     * Mark a withdrawal as failed.
     */
    public static function failWithdrawal(
        Withdrawal $withdrawal,
        string     $reason,
        int        $adminId,
    ): Withdrawal {
        return DB::transaction(function () use ($withdrawal, $reason, $adminId) {
            $withdrawal->update([
                'status'         => Withdrawal::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            // Reverse the debit transaction
            Transaction::where('user_id', $withdrawal->user_id)
                ->where('type', 'withdrawal')
                ->where('status', Payment::STATUS_PENDING)
                ->where('description', 'like', "%{$withdrawal->reference}%")
                ->update(['status' => Payment::STATUS_FAILED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $withdrawal->id,
                ['reference' => $withdrawal->reference, 'action' => 'failed', 'reason' => $reason],
                $adminId,
                "Withdrawal {$withdrawal->reference} failed: {$reason}"
            );

            return $withdrawal->fresh();
        });
    }

    /**
     * Reject a withdrawal (by admin/finance).
     */
    public static function rejectWithdrawal(
        Withdrawal $withdrawal,
        string     $reason,
        int        $adminId,
    ): Withdrawal {
        if (!in_array($withdrawal->status, [Withdrawal::STATUS_REQUESTED, Withdrawal::STATUS_PROCESSING], true)) {
            throw new \RuntimeException('This withdrawal cannot be rejected in its current state.');
        }

        return DB::transaction(function () use ($withdrawal, $reason, $adminId) {
            $withdrawal->update([
                'status'           => Withdrawal::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'rejected_at'      => now(),
                'processed_by'     => $adminId,
                'admin_notes'      => "Rejected by admin: {$reason}",
            ]);

            // Reverse the debit transaction
            Transaction::where('user_id', $withdrawal->user_id)
                ->where('type', 'withdrawal')
                ->where('status', Payment::STATUS_PENDING)
                ->where('description', 'like', "%{$withdrawal->reference}%")
                ->update(['status' => Payment::STATUS_CANCELLED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $withdrawal->id,
                ['reference' => $withdrawal->reference, 'action' => 'rejected', 'reason' => $reason],
                $adminId,
                "Admin #{$adminId} rejected withdrawal {$withdrawal->reference}: {$reason}"
            );

            return $withdrawal->fresh();
        });
    }

    /**
     * Cancel a withdrawal (by the freelancer before processing).
     */
    public static function cancelWithdrawal(Withdrawal $withdrawal, int $userId): Withdrawal {
        if ($withdrawal->user_id !== $userId) {
            throw new \RuntimeException('You can only cancel your own withdrawals.');
        }

        if (!in_array($withdrawal->status, [Withdrawal::STATUS_REQUESTED], true)) {
            throw new \RuntimeException('Only pending withdrawals can be cancelled.');
        }

        return DB::transaction(function () use ($withdrawal, $userId) {
            $withdrawal->update([
                'status' => Withdrawal::STATUS_CANCELLED,
            ]);

            // Reverse the debit transaction
            Transaction::where('user_id', $userId)
                ->where('type', 'withdrawal')
                ->where('status', Payment::STATUS_PENDING)
                ->where('description', 'like', "%{$withdrawal->reference}%")
                ->update(['status' => Payment::STATUS_CANCELLED]);

            AuditService::log(
                \App\Models\AuditLog::ACTION_PAYMENT_PROCESSED,
                \App\Models\AuditLog::MODULE_PAYMENTS,
                'Withdrawal', $withdrawal->id,
                ['reference' => $withdrawal->reference, 'action' => 'cancelled'],
                $userId,
                "User #{$userId} cancelled withdrawal {$withdrawal->reference}"
            );

            return $withdrawal->fresh();
        });
    }
}
