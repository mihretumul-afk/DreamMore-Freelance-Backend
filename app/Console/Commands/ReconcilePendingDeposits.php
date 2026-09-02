<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payment\AddFundsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ReconcilePendingDeposits — safety-net command that catches payments
 * where both webhook AND frontend polling missed the confirmation.
 *
 * Scenario: User pays via Chapa, then closes browser immediately.
 * Webhook fails (network issue, provider delay, etc.).
 * Frontend never gets a chance to poll.
 * → This command finds those stuck payments and reconciles them.
 *
 * Runs every 5 minutes via scheduler.
 * Idempotent: safe to run repeatedly — only processes old pending payments.
 */
class ReconcilePendingDeposits extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--minutes=5 : Only check payments older than this many minutes}
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Reconcile stuck pending deposits by polling Chapa API directly';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);

        // Find wallet deposits stuck in pending status older than the cutoff
        $stuckPayments = Payment::where('type', Payment::TYPE_WALLET_DEPOSIT)
            ->where('status', Payment::STATUS_PENDING)
            ->where('provider', 'chapa')
            ->where('created_at', '<=', $cutoff)
            ->whereNotNull('provider_reference')
            ->get();

        if ($stuckPayments->isEmpty()) {
            $this->info('No stuck pending deposits found. All clear.');
            return Command::SUCCESS;
        }

        $this->info("Found {$stuckPayments->count()} stuck pending deposit(s) to reconcile.");

        $confirmed = 0;
        $failed = 0;
        $stillPending = 0;
        $errors = 0;

        $provider = app(\App\Services\Payment\PaymentProviderInterface::class);

        foreach ($stuckPayments as $payment) {
            $this->line("  Checking Payment #{$payment->id} (ref: {$payment->reference}, amount: {$payment->amount} ETB)...");

            if ($dryRun) {
                $this->line("    [DRY RUN] Would verify with Chapa API");
                continue;
            }

            try {
                $verifyResult = $provider->verify($payment->provider_reference);

                $status = $verifyResult['status'];

                if ($status === 'completed') {
                    // Payment confirmed by Chapa — credit the wallet
                    $service = app(AddFundsService::class);
                    $credited = $service->confirmDeposit(
                        $payment,
                        $payment->provider_reference,
                        $verifyResult['data'] ?? null
                    );

                    if ($credited) {
                        $confirmed++;
                        $this->info("    ✅ CONFIRMED — wallet credited with {$payment->amount} ETB");
                    } else {
                        $this->info("    ⏭️  Already confirmed (idempotent skip)");
                    }
                } elseif ($status === 'failed') {
                    $service = app(AddFundsService::class);
                    $service->failDeposit($payment, $verifyResult['error'] ?? 'Payment failed at provider');
                    $failed++;
                    $this->warn("    ❌ FAILED — marked as failed in database");
                } else {
                    $stillPending++;
                    $this->line("    ⏳ Still pending at provider — will retry next run");
                }

                Log::info('[Reconcile] Processed stuck deposit', [
                    'payment_id'  => $payment->id,
                    'reference'   => $payment->reference,
                    'amount'      => $payment->amount,
                    'provider_status' => $verifyResult['status'],
                ]);
            } catch (\Exception $e) {
                $errors++;
                $this->error("    ⚠️  Error verifying: {$e->getMessage()}");
                Log::error('[Reconcile] Error processing stuck deposit', [
                    'payment_id' => $payment->id,
                    'reference'  => $payment->reference,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info("Reconciliation complete: {$confirmed} confirmed, {$failed} failed, {$stillPending} still pending, {$errors} errors.");

        return Command::SUCCESS;
    }
}
