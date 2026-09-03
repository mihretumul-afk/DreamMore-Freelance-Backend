<?php

namespace App\Console\Commands;

use App\Models\FeaturedJob;
use App\Models\FeaturedProfile;
use Illuminate\Console\Command;

/**
 * ExpireFeaturedListings — runs daily to flip status from 'active' to 'expired'
 * on any featured_jobs or featured_profiles rows past their expires_at.
 *
 * This command is idempotent: running it multiple times with no new expired rows
 * will affect zero rows and exit cleanly.
 */
class ExpireFeaturedListings extends Command
{
    protected $signature = 'featured:listings:expire';

    protected $description = 'Expire featured job and profile listings past their expires_at date';

    public function handle(): int
    {
        $expiredJobs = 0;
        $expiredProfiles = 0;

        // ── Expire featured jobs ────────────────────────────────────────
        $expiredJobs = FeaturedJob::where('status', FeaturedJob::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update(['status' => FeaturedJob::STATUS_EXPIRED]);

        // ── Expire featured profiles ────────────────────────────────────
        $expiredProfiles = FeaturedProfile::where('status', FeaturedProfile::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update(['status' => FeaturedProfile::STATUS_EXPIRED]);

        $total = $expiredJobs + $expiredProfiles;

        if ($total > 0) {
            $this->info("Expired {$expiredJobs} featured job(s) and {$expiredProfiles} featured profile(s).");
        } else {
            $this->info('No expired listings found. All featured listings are current.');
        }

        return Command::SUCCESS;
    }
}
