<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\Providers\ChapaProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ReconcilePendingWithdrawals — safety-net command that catches withdrawals
 * stuck in 'processing' status where the Chapa transfer confirmation
 * was never received (webhook missed, network issue, etc.).
 *
 * Runs every 5 minutes via scheduler.
 * Idempotent: safe to run repeatedly.
 */
class ReconcilePendingWithdrawals extends Command
{
    protected $signature = 'payments:reconcile-withdrawals
                            {--minutes=10 : Only check withdrawals older than this many minutes}
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Reconcile stuck processing withdrawals by querying Chapa Transfer API';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);

        // Find withdrawals stuck in 'processing' older than the cutoff
        $stuckWithdrawals = Withdrawal::where('status', Withdrawal::STATUS_PROCESSING)
            ->where('created_at', '<=', $cutoff)
            ->whereNotNull('reference')
            ->get();

        if ($stuckWithdrawals->isEmpty()) {
            $this->info('No stuck processing withdrawals found. All clear.');
            return Command::SUCCESS;
        }

        $this->info("Found {$stuckWithdrawals->count()} stuck processing withdrawal(s) to reconcile.");

        $confirmed = 0;
        $failed = 0;
        $stillProcessing = 0;
        $errors = 0;

        $provider = new ChapaProvider();

        foreach ($stuckWithdrawals as $withdrawal) {
            $this->line("  Checking Withdrawal #{$withdrawal->id} (ref: {$withdrawal->reference}, amount: {$withdrawal->amount} ETB)...");

            if ($dryRun) {
                $this->line("    [DRY RUN] Would verify with Chapa API");
                continue;
            }

            try {
                $verifyResult = $provider->verifyTransfer($withdrawal->reference);
                $status = $verifyResult['status'];

                if ($status === 'completed') {
                    // Transfer confirmed by Chapa — mark withdrawal as completed
                    $wallet = Wallet::where('user_id', $withdrawal->user_id)->first();

                    $withdrawal->update([
                        'status'             => Withdrawal::STATUS_COMPLETED,
                        'provider_reference' => $withdrawal->reference,
                        'completed_at'       => now(),
                    ]);

                    // Complete withdrawal in wallet (funds already reserved)
                    if ($wallet) {
                        $wallet->completeWithdrawal((float) $withdrawal->amount);
                    }

                    // Ledger entry
                    $this->recordLedgerEntry($withdrawal->user_id, $withdrawal->amount, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL, "Withdrawal {$withdrawal->reference}");
                    if ($withdrawal->fee > 0) {
                        $this->recordLedgerEntry($withdrawal->user_id, $withdrawal->fee, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL_FEE, "Withdrawal fee for {$withdrawal->reference}");
                    }

                    $confirmed++;
                    $this->info("    ✅ CONFIRMED — withdrawal completed, {$withdrawal->amount} ETB paid out");

                } elseif ($status === 'failed') {
                    // Transfer failed — restore wallet balance
                    $wallet = Wallet::where('user_id', $withdrawal->user_id)->first();

                    $withdrawal->update([
                        'status'         => Withdrawal::STATUS_FAILED,
                        'failure_reason' => $verifyResult['error'] ?? 'Transfer failed at provider',
                    ]);

                    // Restore funds to wallet
                    if ($wallet) {
                        $wallet->releaseReservation((float) $withdrawal->amount, 'Withdrawal failed: ' . $withdrawal->reference);
                    }

                    // Ledger entry for the reversal
                    $this->recordLedgerEntry($withdrawal->user_id, $withdrawal->amount, Transaction::DIR_CREDIT, Transaction::TYPE_REFUND, "Withdrawal failed - funds restored: {$withdrawal->reference}");

                    $failed++;
                    $this->warn("    ❌ FAILED — funds restored to wallet");

                } else {
                    $stillProcessing++;
                    $this->line("    ⏳ Still processing at provider — will retry next run");
                }

                Log::info('[Reconcile Withdrawals] Processed stuck withdrawal', [
                    'withdrawal_id'    => $withdrawal->id,
                    'reference'        => $withdrawal->reference,
                    'amount'           => $withdrawal->amount,
                    'provider_status'  => $status,
                ]);
            } catch (\Exception $e) {
                $errors++;
                $this->error("    ⚠️  Error verifying: {$e->getMessage()}");
                Log::error('[Reconcile Withdrawals] Error processing stuck withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info("Reconciliation complete: {$confirmed} confirmed, {$failed} failed, {$stillProcessing} still processing, {$errors} errors.");

        return Command::SUCCESS;
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
