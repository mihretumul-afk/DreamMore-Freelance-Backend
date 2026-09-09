<?php

namespace App\Console\Commands;

use App\Models\AdminSetting;
use App\Models\Contract;
use App\Models\Milestone;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoReleaseMilestonesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milestones:auto-release {--days= : Override configured auto release days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically approve submitted milestones and release escrow payment to freelancers if unreviewed for the configured auto-release timeframe.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysOption = $this->option('days');
        $autoReleaseDays = $daysOption !== null && is_numeric($daysOption)
            ? (int) $daysOption
            : (int) AdminSetting::getValue('auto_release_days', 14, 'integer');

        if ($autoReleaseDays < 1) {
            $autoReleaseDays = 14;
        }

        $cutoffDate = now()->subDays($autoReleaseDays);

        $eligibleMilestones = Milestone::whereIn('status', [Milestone::STATUS_SUBMITTED, Milestone::STATUS_IN_REVIEW])
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', $cutoffDate)
            ->whereHas('contract', function ($query) {
                $query->where('status', '!=', Contract::STATUS_DISPUTED);
            })
            ->with(['contract', 'submissions'])
            ->get();

        if ($eligibleMilestones->isEmpty()) {
            $this->info("No submitted milestones exceed the {$autoReleaseDays}-day review threshold.");
            return Command::SUCCESS;
        }

        $releasedCount = 0;
        $paymentService = app(PaymentService::class);

        foreach ($eligibleMilestones as $milestone) {
            $contract = $milestone->contract;
            if (!$contract) {
                continue;
            }

            try {
                DB::transaction(function () use ($milestone, $contract, $paymentService, $autoReleaseDays) {
                    $milestone->update([
                        'status'      => Milestone::STATUS_APPROVED,
                        'approved_at' => now(),
                    ]);

                    // Update latest submission record
                    $latestSubmission = $milestone->submissions()->latest()->first();
                    if ($latestSubmission) {
                        $latestSubmission->update([
                            'status'      => 'approved',
                            'reviewed_at' => now(),
                        ]);
                    }

                    // Release escrow funds if funded
                    if ($milestone->isFunded()) {
                        $paymentService->releaseMilestone($milestone, null);
                    }

                    // Send Notifications
                    NotificationService::milestoneApproved(
                        $contract->freelancer_id,
                        $milestone->title,
                        $contract->title,
                        $contract->id
                    );

                    NotificationService::create(
                        $contract->employer_id,
                        'milestone_auto_released',
                        'Milestone Auto-Approved & Escrow Released',
                        "Milestone \"{$milestone->title}\" in contract \"{$contract->title}\" was automatically approved after {$autoReleaseDays} days without review.",
                        "/employer/contracts/{$contract->id}"
                    );

                    AuditService::record(
                        null,
                        'milestone.auto_released',
                        'Milestone',
                        $milestone->id,
                        "Milestone \"{$milestone->title}\" automatically approved and escrow released after {$autoReleaseDays} days of review inactivity."
                    );
                });

                $releasedCount++;
                $this->info("Auto-released milestone ID {$milestone->id} (\"{$milestone->title}\").");
            } catch (\Exception $e) {
                Log::error("Failed to auto-release milestone ID {$milestone->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->error("Failed to auto-release milestone ID {$milestone->id}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully auto-released {$releasedCount} milestone(s).");
        return Command::SUCCESS;
    }
}
