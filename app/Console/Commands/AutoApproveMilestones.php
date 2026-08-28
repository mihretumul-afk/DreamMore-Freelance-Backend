<?php

namespace App\Console\Commands;

use App\Models\Milestone;
use App\Models\PlatformSetting;
use App\Services\NotificationService;
use App\Services\WalletService;
use Illuminate\Console\Command;

class AutoApproveMilestones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milestones:auto-approve';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically approve submitted milestones that exceed the auto-approval timeframe';

    /**
     * Execute the console command.
     */
    public function handle(WalletService $walletService): int
    {
        $days = (int) PlatformSetting::get('auto_approve_days', '5');
        $cutoffDate = now()->subDays($days);

        $milestones = Milestone::where('status', 'submitted')
            ->where('submitted_at', '<=', $cutoffDate)
            ->get();

        $count = 0;

        foreach ($milestones as $milestone) {
            try {
                $walletService->releaseMilestone($milestone, null);

                $contract = $milestone->contract;
                if ($contract) {
                    NotificationService::milestoneApproved(
                        $contract->freelancer_id,
                        $milestone->title,
                        $contract->title,
                        $contract->id
                    );
                }

                $count++;
                $this->info("Auto-approved milestone #{$milestone->id}: '{$milestone->title}'");
            } catch (\Exception $e) {
                $this->error("Failed to auto-approve milestone #{$milestone->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully auto-approved {$count} milestone(s).");

        return Command::SUCCESS;
    }
}
