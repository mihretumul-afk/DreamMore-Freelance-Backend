<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Removes ALL rows created by DemoDataSeeder.
 * Safe to run multiple times. Nothing else is touched.
 *
 * Disables FK checks for the duration (session-scoped) so deletion order
 * never fails, and deletes every child row explicitly to avoid orphans.
 */
class DemoDataCleanupSeeder extends Seeder
{
    public function run(): void
    {
        $demoUsers = DB::table('users')->where('email', 'like', 'demo.%@dreammore.com')->get();
        $ids = $demoUsers->pluck('id');

        if ($ids->isEmpty()) {
            $this->command?->info('  No demo data found — nothing to clean.');

            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Money rows are tagged DEMO- (covers any user references)
        DB::table('transactions')->where('reference', 'like', 'DEMO-%')->delete();
        DB::table('payments')->where('reference', 'like', 'DEMO-%')->delete();
        DB::table('withdrawals')->where('reference', 'like', 'DEMO-%')->delete();

        // Marketplace rows owned by demo users
        $jobs = DB::table('marketplace_jobs')->whereIn('employer_id', $ids)->pluck('id');
        $proposals = DB::table('proposals')->whereIn('freelancer_id', $ids)->pluck('id');
        $contracts = DB::table('contracts')->where(function ($q) use ($ids) {
            $q->whereIn('employer_id', $ids)->orWhereIn('freelancer_id', $ids);
        })->pluck('id');
        $milestones = DB::table('milestones')->whereIn('contract_id', $contracts)->pluck('id');
        $profiles = DB::table('freelancer_profiles')->whereIn('user_id', $ids)->pluck('id');

        DB::table('milestone_submissions')->whereIn('milestone_id', $milestones)->delete();
        DB::table('milestone_attachments')->whereIn('milestone_id', $milestones)->delete();
        DB::table('milestones')->whereIn('id', $milestones)->delete();
        DB::table('contracts')->whereIn('id', $contracts)->delete();
        DB::table('proposals')->whereIn('id', $proposals)->delete();
        DB::table('marketplace_jobs')->whereIn('id', $jobs)->delete();

        DB::table('reviews')->where(function ($q) use ($ids) {
            $q->whereIn('reviewer_id', $ids)->orWhereIn('reviewee_id', $ids);
        })->delete();

        DB::table('freelancer_skills')->whereIn('freelancer_profile_id', $profiles)->delete();
        DB::table('freelancer_profiles')->whereIn('id', $profiles)->delete();
        DB::table('employer_profiles')->whereIn('user_id', $ids)->delete();
        DB::table('credentials')->whereIn('user_id', $ids)->delete();
        DB::table('portfolio_items')->whereIn('user_id', $ids)->delete();
        DB::table('verifications')->whereIn('user_id', $ids)->delete();
        DB::table('wallets')->whereIn('user_id', $ids)->delete();
        DB::table('payment_methods')->whereIn('user_id', $ids)->delete();
        DB::table('saved_jobs')->whereIn('user_id', $ids)->delete();
        DB::table('saved_freelancers')->whereIn('user_id', $ids)->orWhereIn('freelancer_profile_id', $profiles)->delete();
        DB::table('messages')->where(function ($q) use ($ids) {
            $q->whereIn('sender_id', $ids)->orWhereIn('receiver_id', $ids);
        })->delete();
        DB::table('admin_user_roles')->whereIn('user_id', $ids)->delete();

        // Schema-guarded deletes (table shapes vary between Laravel versions)
        if (\Schema::hasColumns('personal_access_tokens', ['tokenable_type', 'tokenable_id'])) {
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\Models\User')->whereIn('tokenable_id', $ids)->delete();
        }
        if (\Schema::hasColumn('notifications', 'user_id')) {
            DB::table('notifications')->whereIn('user_id', $ids)->delete();
        } elseif (\Schema::hasColumns('notifications', ['notifiable_type', 'notifiable_id'])) {
            DB::table('notifications')->where('notifiable_type', 'App\Models\User')->whereIn('notifiable_id', $ids)->delete();
        }

        // Users last
        DB::table('users')->whereIn('id', $ids)->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->command?->info('  ✅ Demo data removed ('.$ids->count().' demo users, all DEMO- tagged money rows).');
    }
}
