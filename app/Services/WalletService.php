<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\EscrowTransaction;
use App\Models\Milestone;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Wallet;
use Exception;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Get or create a wallet for a user.
     */
    public function walletFor(User $user, string $currency = 'ETB'): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'available_balance' => 0.00,
                'pending_balance' => 0.00,
                'currency' => $currency,
            ]
        );
    }

    /**
     * Fund a milestone from employer's available balance to pending balance (escrow).
     */
    public function fundMilestone(Milestone $milestone, User $employer): EscrowTransaction
    {
        return DB::transaction(function () use ($milestone, $employer) {
            if (!in_array($milestone->status, ['pending', 'awaiting_funding'], true)) {
                throw new Exception("Milestone cannot be funded from current status '{$milestone->status}'.");
            }

            $contract = $milestone->contract;
            if ($contract->employer_id !== $employer->id) {
                throw new Exception("Unauthorized: Only the contract employer can fund this milestone.");
            }

            $wallet = Wallet::where('user_id', $employer->id)->lockForUpdate()->first();
            if (!$wallet) {
                $wallet = $this->walletFor($employer);
                $wallet = Wallet::where('user_id', $employer->id)->lockForUpdate()->first();
            }

            if ((float) $wallet->available_balance < (float) $milestone->amount) {
                throw new Exception("Insufficient available balance ({$wallet->available_balance} ETB) to fund milestone amount ({$milestone->amount} ETB).");
            }

            // Deduct available balance and add to pending balance
            $wallet->available_balance = (float) $wallet->available_balance - (float) $milestone->amount;
            $wallet->pending_balance = (float) $wallet->pending_balance + (float) $milestone->amount;
            $wallet->save();

            // Update milestone
            $milestone->status = 'funded';
            $milestone->funded_at = now();
            $milestone->save();

            // Update contract status if active
            if ($contract->status === 'pending') {
                $contract->status = 'active';
                $contract->start_date = $contract->start_date ?? now();
                $contract->save();
            }

            // Record transaction
            return EscrowTransaction::create([
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'type' => 'escrow_hold',
                'amount' => $milestone->amount,
                'currency' => $contract->currency ?? 'ETB',
                'from_user_id' => $employer->id,
                'to_user_id' => null,
                'status' => 'completed',
                'payment_method' => 'test_mode',
            ]);
        });
    }

    /**
     * Release milestone escrow to freelancer, deducting platform fee.
     */
    public function releaseMilestone(Milestone $milestone, ?User $approvedBy = null): array
    {
        return DB::transaction(function () use ($milestone, $approvedBy) {
            if ($milestone->status !== 'submitted') {
                throw new Exception("Milestone must be in 'submitted' status to be released. Current: '{$milestone->status}'.");
            }

            $contract = $milestone->contract;

            // Lock employer wallet
            $employerWallet = Wallet::where('user_id', $contract->employer_id)->lockForUpdate()->first();
            if (!$employerWallet || (float) $employerWallet->pending_balance < (float) $milestone->amount) {
                // If pending balance is somehow lower, fallback to available balance or create wallet
                if (!$employerWallet) {
                    $employerWallet = $this->walletFor($contract->employer);
                    $employerWallet = Wallet::where('user_id', $contract->employer_id)->lockForUpdate()->first();
                }
            }

            // Lock or create freelancer wallet
            $freelancerWallet = Wallet::where('user_id', $contract->freelancer_id)->lockForUpdate()->first();
            if (!$freelancerWallet) {
                $freelancerWallet = Wallet::create([
                    'user_id' => $contract->freelancer_id,
                    'available_balance' => 0.00,
                    'pending_balance' => 0.00,
                    'currency' => 'ETB',
                ]);
                $freelancerWallet = Wallet::where('user_id', $contract->freelancer_id)->lockForUpdate()->first();
            }

            // Read platform fee percentage
            $feePercentSetting = PlatformSetting::get('platform_fee_percent', '8');
            $feePercent = max(0, min(50, (float) $feePercentSetting));

            $feeAmount = round(((float) $milestone->amount * $feePercent) / 100, 2);
            $netAmount = round((float) $milestone->amount - $feeAmount, 2);

            // Deduct employer pending balance
            $employerWallet->pending_balance = max(0.00, (float) $employerWallet->pending_balance - (float) $milestone->amount);
            $employerWallet->save();

            // Credit freelancer available balance
            $freelancerWallet->available_balance = (float) $freelancerWallet->available_balance + $netAmount;
            $freelancerWallet->save();

            // Update milestone
            $milestone->status = 'released';
            $milestone->approved_at = $milestone->approved_at ?? now();
            $milestone->released_at = now();
            $milestone->save();

            // Log release transaction to freelancer
            $releaseTxn = EscrowTransaction::create([
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'type' => 'release',
                'amount' => $netAmount,
                'currency' => $contract->currency ?? 'ETB',
                'from_user_id' => $contract->employer_id,
                'to_user_id' => $contract->freelancer_id,
                'status' => 'completed',
                'payment_method' => 'test_mode',
            ]);

            // Log platform fee transaction (retained by system)
            $feeTxn = EscrowTransaction::create([
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'type' => 'platform_fee',
                'amount' => $feeAmount,
                'currency' => $contract->currency ?? 'ETB',
                'from_user_id' => $contract->employer_id,
                'to_user_id' => null,
                'status' => 'completed',
                'payment_method' => 'test_mode',
            ]);

            // If all contract milestones are released/completed, complete contract
            $remainingCount = Milestone::where('contract_id', $contract->id)
                ->whereNotIn('status', ['released', 'approved'])
                ->count();

            if ($remainingCount === 0) {
                $contract->status = 'completed';
                $contract->end_date = now();
                $contract->save();
            }

            return [
                'milestone' => $milestone,
                'net_amount' => $netAmount,
                'fee_amount' => $feeAmount,
                'release_transaction' => $releaseTxn,
                'fee_transaction' => $feeTxn,
            ];
        });
    }

    /**
     * Test mode withdrawal from available balance.
     */
    public function withdraw(User $freelancer, float $amount, string $currency = 'ETB'): EscrowTransaction
    {
        return DB::transaction(function () use ($freelancer, $amount, $currency) {
            if ($amount <= 0) {
                throw new Exception("Withdrawal amount must be greater than 0.");
            }

            $wallet = Wallet::where('user_id', $freelancer->id)->lockForUpdate()->first();
            if (!$wallet || (float) $wallet->available_balance < $amount) {
                $available = $wallet ? $wallet->available_balance : 0.00;
                throw new Exception("Insufficient available balance ({$available} {$currency}) for withdrawal of {$amount} {$currency}.");
            }

            $wallet->available_balance = (float) $wallet->available_balance - $amount;
            $wallet->save();

            return EscrowTransaction::create([
                'contract_id' => null,
                'milestone_id' => null,
                'type' => 'withdrawal',
                'amount' => $amount,
                'currency' => $currency,
                'from_user_id' => $freelancer->id,
                'to_user_id' => null,
                'status' => 'completed',
                'payment_method' => 'test_mode',
            ]);
        });
    }

    /**
     * Refund milestone escrow back to employer available balance.
     */
    public function refundMilestone(Milestone $milestone): EscrowTransaction
    {
        return DB::transaction(function () use ($milestone) {
            $contract = $milestone->contract;
            $employerWallet = Wallet::where('user_id', $contract->employer_id)->lockForUpdate()->first();

            if (!$employerWallet) {
                $employerWallet = $this->walletFor($contract->employer);
                $employerWallet = Wallet::where('user_id', $contract->employer_id)->lockForUpdate()->first();
            }

            // Move pending escrow back to available balance
            $employerWallet->pending_balance = max(0.00, (float) $employerWallet->pending_balance - (float) $milestone->amount);
            $employerWallet->available_balance = (float) $employerWallet->available_balance + (float) $milestone->amount;
            $employerWallet->save();

            $milestone->status = 'rejected';
            $milestone->save();

            return EscrowTransaction::create([
                'contract_id' => $contract->id,
                'milestone_id' => $milestone->id,
                'type' => 'refund',
                'amount' => $milestone->amount,
                'currency' => $contract->currency ?? 'ETB',
                'from_user_id' => null,
                'to_user_id' => $contract->employer_id,
                'status' => 'completed',
                'payment_method' => 'test_mode',
            ]);
        });
    }
}
