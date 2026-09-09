<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Contract;
use App\Models\Credential;
use App\Models\EmployerProfile;
use App\Models\FreelancerProfile;
use App\Models\Job;
use App\Models\Milestone;
use App\Models\Payment;
use App\Models\PortfolioItem;
use App\Models\Proposal;
use App\Models\Review;
use App\Models\Skill;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DemoDataSeeder — realistic, clearly-tagged demo data for presentations.
 *
 * Every row created here is identifiable and removable:
 *  - Users:    demo.*@dreammore.com
 *  - Refs:     DEMO- prefix on payments / withdrawals / transactions
 *  - Metadata: {"demo": true}
 *
 * Idempotent: re-running updates the same demo rows instead of duplicating.
 * Remove later with:  php artisan tinker --execute="..." (see class comment)
 */
class DemoDataSeeder extends Seeder
{
    private const PW = 'DemoMore@2026';

    public function run(): void
    {
        // ── Skills cache ────────────────────────────────────────────────
        $skill = fn (string $name) => Skill::firstOrCreate(
            ['name' => $name],
            ['slug' => \Illuminate\Support\Str::slug($name)]
        );
        $cat = fn (string $slug) => Category::where('slug', $slug)->first();

        // ── Employers ───────────────────────────────────────────────────
        $sheba = $this->user('demo.employer@dreammore.com', 'Sheba Tech Solutions', 'employer');
        EmployerProfile::updateOrCreate(['user_id' => $sheba->id], [
            'company_name' => 'Sheba Tech Solutions PLC',
            'industry' => 'Software & IT Services',
            'location' => 'Addis Ababa',
            'company_size' => '11-50',
            'website' => 'https://shebatech.example.com',
            'posted_jobs_count' => 2,
            'total_spent' => 60000,
        ]);
        $bluenile = $this->user('demo.employer2@dreammore.com', 'Blue Nile Media', 'employer');
        EmployerProfile::updateOrCreate(['user_id' => $bluenile->id], [
            'company_name' => 'Blue Nile Media PLC',
            'industry' => 'Media & Production',
            'location' => 'Addis Ababa',
            'company_size' => '2-10',
            'posted_jobs_count' => 2,
            'total_spent' => 12000,
        ]);

        // ── Freelancers ─────────────────────────────────────────────────
        $marta = $this->user('demo.freelancer@dreammore.com', 'Marta Alemu', 'freelancer');
        $dawit = $this->user('demo.freelancer2@dreammore.com', 'Dawit Bekele', 'freelancer');
        $hanna = $this->user('demo.freelancer3@dreammore.com', 'Hanna Girma', 'freelancer');

        $profiles = [];
        $profiles[] = FreelancerProfile::updateOrCreate(['user_id' => $marta->id], [
            'category_id' => $cat('web-development')->id,
            'approval_status' => 'approved',
            'approved_at' => Carbon::now()->subDays(70),
            'headline' => 'Full-Stack Developer | React & Laravel',
            'overview' => 'I build fast, accessible web apps for Ethiopian businesses. 5 years of experience across startups and NGOs.',
            'hourly_rate' => 900,
            'experience_level' => 'expert',
            'location' => 'Addis Ababa',
            'github_url' => 'https://github.com/demo-marta',
            'linkedin_url' => 'https://linkedin.com/in/demo-marta',
            'rating' => 4.9,
            'completed_jobs_count' => 2,
        ]);
        $profiles[] = FreelancerProfile::updateOrCreate(['user_id' => $dawit->id], [
            'category_id' => $cat('vidio-editing')->id,
            'approval_status' => 'approved',
            'approved_at' => Carbon::now()->subDays(60),
            'headline' => 'Video Editor & Motion Designer',
            'overview' => 'Commercial edits, color grading and motion graphics. I have edited 200+ videos for brands and agencies.',
            'hourly_rate' => 600,
            'experience_level' => 'intermediate',
            'location' => 'Hawassa',
            'rating' => 4.7,
            'completed_jobs_count' => 1,
        ]);
        $profiles[] = FreelancerProfile::updateOrCreate(['user_id' => $hanna->id], [
            'category_id' => $cat('graphics-design')->id,
            'approval_status' => 'approved',
            'approved_at' => Carbon::now()->subDays(45),
            'headline' => 'Brand & UI Designer',
            'overview' => 'Logos, brand kits and clean UI design. Available for identity projects and design systems.',
            'hourly_rate' => 450,
            'experience_level' => 'intermediate',
            'location' => 'Bahir Dar',
            'rating' => 4.8,
            'completed_jobs_count' => 0,
        ]);

        // Skills
        $martaSkills = [$skill('React'), $skill('Laravel'), $skill('PHP'), $skill('MySQL'), $skill('JavaScript')];
        $dawitSkills = [$skill('Video Editing'), $skill('After Effects'), $skill('Color Grading')];
        $hannaSkills = [$skill('Illustrator'), $skill('Figma'), $skill('Logo Design')];
        foreach ([$marta, $dawit, $hanna] as $i => $u) {
            $ids = [$martaSkills, $dawitSkills, $hannaSkills][$i];
            $profiles[$i]->skills()->syncWithoutDetaching(array_map(fn ($s) => $s->id, $ids));
        }

        // Approved credentials (what unlocks proposals + "Verified Credentials" section)
        $this->credential($marta, 'AWS Certified Cloud Practitioner', 'professional_qualification', 'Amazon Web Services', 'DEMO-AWS-2025');
        $this->credential($dawit, 'Dream More Certificate — Video Production', 'dream_more_certificate', 'Dream More AppWorks', 'DEMO-DM-VID-2214');
        $this->credential($hanna, 'Google UX Design Certificate', 'external_certificate', 'Google / Coursera', 'DEMO-GUX-88410');

        // Portfolio
        $this->portfolio($marta, 'Telebirr Merchant Dashboard', 'Analytics dashboard for a mobile-money merchant portal.', 'web-development', 'https://example.com/demo/dashboard');
        $this->portfolio($marta, 'NGO Donation Platform', 'Laravel + React donation flow with local payment rails.', 'web-development', 'https://example.com/demo/ngo');
        $this->portfolio($dawit, 'Coffee Export Promo', '60s product promo, color graded in DaVinci.', 'vidio-editing', 'https://example.com/demo/coffee');
        $this->portfolio($hanna, 'Habesha Restaurant Rebrand', 'Full identity kit: logo, menu, signage.', 'graphics-design', null);

        // ── Jobs ────────────────────────────────────────────────────────
        $jobA = $this->job($sheba, $cat('web-development'), 'Custom React Dashboard for Logistics Startup', 'Build an operations dashboard: shipment tracking, driver management and analytics. React frontend on top of an existing Laravel API.', 'fixed', 45000, 90000, 'expert', 'in_progress', 3);
        $jobB = $this->job($bluenile, $cat('vidio-editing'), 'Product Promo Video for Coffee Brand', 'Edit a 60-second promo from raw footage: pacing, color grade, motion titles and sound design.', 'fixed', 8000, 15000, 'intermediate', 'completed', 2);
        $jobC = $this->job($bluenile, $cat('graphics-design'), 'Brand Identity + Social Media Kit', 'Design a fresh brand identity for a cafe chain: logo, palette, typography and a 12-post social kit.', 'fixed', 12000, 25000, 'intermediate', 'open', 1);
        $jobD = $this->job($sheba, $cat('web-development'), 'Landing Page + SEO Setup for E-Commerce Launch', 'One high-converting landing page with SEO basics for a new e-commerce launch.', 'fixed', 20000, 40000, 'entry', 'open', 0);

        // ── Proposals ───────────────────────────────────────────────────
        $pA1 = $this->proposal($jobA, $marta, 60000, 'accepted', 'I have shipped 3 similar dashboards. Two-week delivery for the full tracking module.');
        $this->proposal($jobA, $dawit, 75000, 'rejected', 'Interested — I can assemble a team if needed.');
        $pB1 = $this->proposal($jobB, $dawit, 12000, 'accepted', 'I edit coffee brand promos regularly. Sample reel attached in portfolio.');
        $this->proposal($jobB, $hanna, 9500, 'rejected', 'I can handle the edit and motion titles.');
        $this->proposal($jobC, $hanna, 18000, 'pending', 'Identity systems are my specialty — see the restaurant rebrand in my portfolio.');

        // ── Contracts + milestones + money ──────────────────────────────
        // Wallets first (funded by demo deposits)
        $shebaWallet = $this->seedWallet($sheba, 80000);
        $bluenileWallet = $this->seedWallet($bluenile, 30000);

        // Contract 1: Sheba Tech x Marta (active, 2 released + 1 funded milestone)
        $c1 = Contract::updateOrCreate(['proposal_id' => $pA1->id], [
            'job_id' => $jobA->id, 'employer_id' => $sheba->id, 'freelancer_id' => $marta->id,
            'title' => $jobA->title, 'budget_type' => 'fixed', 'agreed_rate' => 60000, 'total_amount' => 60000,
            'status' => 'active', 'start_date' => Carbon::now()->subDays(45),
        ]);
        $mA = $this->milestone($c1, $sheba, 'UI Design & Component Library', 'Figma designs + React component library.', 20000, 'released', 40, 30);
        $mB = $this->milestone($c1, $sheba, 'Dashboard Build + API Integration', 'Shipment tracking and driver management modules wired to the API.', 28000, 'released', 25, 10);
        $mC = $this->milestone($c1, $sheba, 'Reporting Module & QA', 'Exportable reports plus end-to-end QA pass.', 12000, 'funded', 3, null);

        // Contract 2: Blue Nile x Dawit (completed)
        $c2 = Contract::updateOrCreate(['proposal_id' => $pB1->id], [
            'job_id' => $jobB->id, 'employer_id' => $bluenile->id, 'freelancer_id' => $dawit->id,
            'title' => $jobB->title, 'budget_type' => 'fixed', 'agreed_rate' => 12000, 'total_amount' => 12000,
            'status' => 'completed', 'start_date' => Carbon::now()->subDays(60), 'end_date' => Carbon::now()->subDays(20),
        ]);
        $mD = $this->milestone($c2, $bluenile, 'Storyboard & Shot Planning', 'Shot list, storyboard and edit plan.', 3000, 'released', 58, 50);
        $mE = $this->milestone($c2, $bluenile, 'Full Edit + Color Grade', 'Final cut with grade, titles and sound mix.', 9000, 'released', 48, 20);

        // Money: escrow + releases (uses the REAL fee calculator so numbers match production logic)
        $relA = $this->money($shebaWallet, $marta, $sheba, $c1, $mA, 'DEMO', 1)[0];
        $relB = $this->money($shebaWallet, $marta, $sheba, $c1, $mB, 'DEMO', 2)[0];
        $this->money($shebaWallet, $marta, $sheba, $c1, $mC, 'DEMO', 3, true); // funded only
        $relD = $this->money($bluenileWallet, $dawit, $bluenile, $c2, $mD, 'DEMO', 4)[0];
        $relE = $this->money($bluenileWallet, $dawit, $bluenile, $c2, $mE, 'DEMO', 5)[0];

        // ── Reviews ─────────────────────────────────────────────────────
        Review::updateOrCreate(['contract_id' => $c1->id, 'reviewer_id' => $sheba->id], [
            'reviewee_id' => $marta->id, 'reviewer_role' => 'employer', 'rating' => 5,
            'comment' => 'Marta delivered ahead of schedule and the dashboard is rock solid. Communication was excellent throughout.',
        ]);
        Review::updateOrCreate(['contract_id' => $c1->id, 'reviewer_id' => $marta->id], [
            'reviewee_id' => $sheba->id, 'reviewer_role' => 'freelancer', 'rating' => 5,
            'comment' => 'Clear requirements, fast milestone approvals. A great client to work with.',
        ]);
        Review::updateOrCreate(['contract_id' => $c2->id, 'reviewer_id' => $bluenile->id], [
            'reviewee_id' => $dawit->id, 'reviewer_role' => 'employer', 'rating' => 5,
            'comment' => 'The promo outperformed our previous videos 3x in engagement. Dawit nailed the brand feel.',
        ]);
        Review::updateOrCreate(['contract_id' => $c2->id, 'reviewer_id' => $dawit->id], [
            'reviewee_id' => $bluenile->id, 'reviewer_role' => 'freelancer', 'rating' => 4,
            'comment' => 'Good feedback loop. One revision round took a few extra days but overall smooth.',
        ]);

        // ── Withdrawals (completed, so charts + history look real) ──────
        $this->withdrawal($marta, 8000, Carbon::now()->subDays(12));
        $this->withdrawal($dawit, 5000, Carbon::now()->subDays(7));
        // Hanna: wallet exists but empty (realistic for a newer freelancer)
        Wallet::firstOrCreate(['user_id' => $hanna->id], ['currency' => 'ETB']);

        // Refresh informational aggregates
        $martaProfile = FreelancerProfile::where('user_id', $marta->id)->first();
        $martaProfile->update(['total_earnings' => $relA + $relB]);
        $dawitProfile = FreelancerProfile::where('user_id', $dawit->id)->first();
        $dawitProfile->update(['total_earnings' => $relD + $relE]);

        $this->command?->info('  ✅ Demo data seeded — every row tagged demo.*@dreammore.com / DEMO- refs / metadata {"demo":true}');
        $this->command?->warn('  ⚠️  Demo accounts password: '.self::PW.'  |  Remove later via the documented cleanup query.');
    }

    // ────────────────────────── helpers ──────────────────────────

    private function user(string $email, string $name, string $role): User
    {
        return User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make(self::PW), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function credential(User $u, string $title, string $type, string $org, string $identifier): void
    {
        // Demo credentials reuse existing placeholder PDFs already on disk
        $demoFile = 'credentials/1_1787295922_6df553264cc2d8ed.pdf';
        Credential::updateOrCreate(['user_id' => $u->id, 'title' => $title, 'certificate_identifier' => $identifier], [
            'type' => $type, 'issuing_organization' => $org, 'status' => 'approved',
            'file_path' => $demoFile, 'file_original_name' => 'demo-certificate.pdf',
            'verification_source' => $type === 'dream_more_certificate' ? 'lms' : 'document',
            'reviewed_at' => Carbon::now()->subDays(50), 'issue_date' => Carbon::now()->subDays(120),
        ]);
    }

    private function portfolio(User $u, string $title, string $desc, string $catSlug, ?string $url): void
    {
        PortfolioItem::updateOrCreate(['user_id' => $u->id, 'title' => $title], [
            'description' => $desc, 'category_id' => Category::where('slug', $catSlug)->value('id'),
            'project_url' => $url, 'display_order' => 0,
        ]);
    }

    private function job(User $employer, $category, string $title, string $desc, string $budgetType, $min, $max, string $level, string $status, int $proposals): Job
    {
        return Job::updateOrCreate(['employer_id' => $employer->id, 'title' => $title], [
            'category_id' => $category?->id, 'slug' => \Illuminate\Support\Str::slug($title).'-demo',
            'description' => $desc, 'budget_type' => $budgetType, 'min_budget' => $min, 'max_budget' => $max,
            'experience_level' => $level, 'location_type' => 'remote', 'location' => 'Addis Ababa',
            'status' => $status, 'currency' => 'ETB', 'proposals_count' => $proposals,
            'deadline' => Carbon::now()->addDays(21), 'published_at' => Carbon::now()->subDays(35),
        ]);
    }

    private function proposal(Job $job, User $freelancer, float $bid, string $status, string $cover): Proposal
    {
        return Proposal::updateOrCreate(['job_id' => $job->id, 'freelancer_id' => $freelancer->id], [
            'cover_letter' => $cover, 'bid_amount' => $bid, 'currency' => 'ETB',
            'estimated_duration' => '3 weeks', 'status' => $status,
        ]);
    }

    private function milestone(Contract $contract, User $creator, string $title, string $desc, float $amount, string $status, int $fundedDaysAgo, ?int $releasedDaysAgo): Milestone
    {
        return Milestone::updateOrCreate(['contract_id' => $contract->id, 'title' => $title], [
            'description' => $desc, 'amount' => $amount, 'status' => $status, 'created_by' => $creator->id,
            'funded_at' => Carbon::now()->subDays($fundedDaysAgo),
            'submitted_at' => $releasedDaysAgo !== null ? Carbon::now()->subDays($releasedDaysAgo + 1) : null,
            'approved_at' => $releasedDaysAgo !== null ? Carbon::now()->subDays($releasedDaysAgo) : null,
            'released_at' => $releasedDaysAgo !== null ? Carbon::now()->subDays($releasedDaysAgo) : null,
        ]);
    }

    private function seedWallet(User $u, float $depositAmount): Wallet
    {
        $wallet = Wallet::updateOrCreate(['user_id' => $u->id], ['currency' => 'ETB']);
        if ($depositAmount > 0) {
            // Idempotency guard: only credit + ledger on first creation
            $alreadySeeded = Payment::where('reference', 'DEMO-DEP-'.$u->id)->exists();
            if (! $alreadySeeded) {
                $wallet->increment('available_balance', $depositAmount);
                $payment = Payment::create([
                    'reference' => 'DEMO-DEP-'.$u->id,
                    'payer_id' => $u->id, 'type' => Payment::TYPE_WALLET_DEPOSIT, 'amount' => $depositAmount,
                    'platform_fee' => 0, 'processing_fee' => 0, 'net_amount' => $depositAmount,
                    'currency' => 'ETB', 'status' => Payment::STATUS_COMPLETED, 'provider' => 'chapa',
                    'provider_reference' => 'DEMO-CHAPA-DEP-'.$u->id, 'processed_at' => Carbon::now()->subDays(50),
                ]);
                $this->tx($u, $wallet->fresh(), $payment, Transaction::DIR_CREDIT, Transaction::TYPE_WALLET_DEPOSIT, $depositAmount, 'Demo wallet deposit (Chapa)', Carbon::now()->subDays(50));
            }
        }

        return $wallet->fresh();
    }

    /**
     * Create escrow payment + release payment + ledger rows for a milestone.
     * Uses PaymentService::calculateFees() so demo numbers match production logic.
     * Returns [releaseNetA, releaseNetB, ...] of net amounts (for aggregates).
     */
    private function money(Wallet $employerWallet, User $freelancer, User $employer, Contract $contract, Milestone $milestone, string $tag, int $seq, bool $fundedOnly = false): array
    {
        $fees = PaymentService::calculateFees((float) $milestone->amount);
        $releasedAt = $milestone->released_at;

        // Idempotency: capture whether the escrow/release rows are new
        $escrow = Payment::updateOrCreate(['reference' => "DEMO-ESC-$seq"], [
            'payer_id' => $employer->id, 'contract_id' => $contract->id, 'milestone_id' => $milestone->id,
            'type' => Payment::TYPE_ESCROW_FUNDED, 'amount' => $milestone->amount,
            'platform_fee' => 0, 'processing_fee' => 0, 'net_amount' => $milestone->amount,
            'currency' => 'ETB', 'status' => Payment::STATUS_COMPLETED, 'provider' => 'wallet',
            'provider_reference' => 'WALLET-DEMO-ESC-'.$seq, 'processed_at' => $milestone->funded_at,
        ]);

        // Employer ledger: funds held (only ledger once, on first creation)
        if ($escrow->wasRecentlyCreated) {
            // Mirror production: escrow funding locks money (decrements available balance)
            $employerWallet->decrement('available_balance', (float) $milestone->amount);
            $employerWallet = $employerWallet->fresh();
            $this->tx($employer, $employerWallet, $escrow, Transaction::DIR_DEBIT, Transaction::TYPE_FUNDS_HELD, (float) $milestone->amount, "Funds held in escrow for milestone \"$milestone->title\"", $milestone->funded_at);
        }

        if ($fundedOnly || !$releasedAt) {
            return [0];
        }

        $release = Payment::updateOrCreate(['reference' => "DEMO-REL-$seq"], [
            'payer_id' => $employer->id, 'payee_id' => $freelancer->id, 'contract_id' => $contract->id, 'milestone_id' => $milestone->id,
            // Mirror production releaseMilestone(): freelancer receives gross - platform_fee
            // (processing fee is recorded for bookkeeping but NOT deducted from the freelancer)
            'type' => Payment::TYPE_MILESTONE_RELEASED, 'amount' => round($milestone->amount - $fees['platform_fee'], 2),
            'platform_fee' => $fees['platform_fee'], 'processing_fee' => $fees['processing_fee'],
            'net_amount' => round($milestone->amount - $fees['platform_fee'], 2),
            'currency' => 'ETB', 'status' => Payment::STATUS_COMPLETED, 'provider' => 'wallet',
            'provider_reference' => 'WALLET-DEMO-REL-'.$seq, 'processed_at' => $releasedAt,
        ]);
        $netRelease = (float) $release->amount;

        $freelancerWallet = Wallet::firstOrCreate(['user_id' => $freelancer->id], ['currency' => 'ETB']);
        if ($release->wasRecentlyCreated) {
            $freelancerWallet->increment('available_balance', $netRelease);
            $freelancerWallet->increment('total_earned', $netRelease);
            $freelancerWallet = $freelancerWallet->fresh();

            $this->tx($freelancer, $freelancerWallet, $release, Transaction::DIR_CREDIT, Transaction::TYPE_FUNDS_RELEASED, $netRelease, "Funds released for milestone \"$milestone->title\"", $releasedAt);
            if ($fees['platform_fee'] > 0) {
                $this->tx($freelancer, $freelancerWallet, $release, Transaction::DIR_DEBIT, Transaction::TYPE_PLATFORM_FEE, $fees['platform_fee'], "Platform fee deducted for milestone \"$milestone->title\"", $releasedAt);
            }
        }

        return [$netRelease];
    }

    private function withdrawal(User $u, float $amount, Carbon $completedAt): void
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $u->id], ['currency' => 'ETB']);
        $fee = round($amount * 0.015, 2);

        // Idempotency: only deduct + ledger on first creation
        $existing = Withdrawal::where('reference', 'DEMO-WTH-'.$u->id)->first();
        if ($existing) {
            return;
        }

        $withdrawal = Withdrawal::create([
            'reference' => 'DEMO-WTH-'.$u->id,
            'user_id' => $u->id, 'amount' => $amount, 'fee' => $fee, 'net_amount' => $amount - $fee,
            'currency' => 'ETB', 'status' => Withdrawal::STATUS_COMPLETED, 'provider' => 'chapa',
            'provider_reference' => 'DEMO-CHAPA-TRF-'.$u->id,
            'processed_at' => $completedAt, 'completed_at' => $completedAt,
        ]);

        $wallet->decrement('available_balance', $amount);
        $wallet->increment('total_withdrawn', $amount);
        $wallet = $wallet->fresh();

        $this->tx($u, $wallet, null, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL, $amount, "Withdrawal $withdrawal->reference", $completedAt);
        if ($fee > 0) {
            $this->tx($u, $wallet, null, Transaction::DIR_DEBIT, Transaction::TYPE_WITHDRAWAL_FEE, $fee, "Withdrawal fee for $withdrawal->reference", $completedAt);
        }
    }

    private function tx(User $u, ?Wallet $wallet, ?Payment $payment, string $dir, string $type, float $amount, string $desc, ?Carbon $at = null): void
    {
        $ref = 'DEMO-TX-'.md5($u->id.$type.$desc.$amount);
        if (Transaction::where('reference', $ref)->exists()) {
            return; // already ledgered — keep balances correct on re-run
        }

        $before = (float) ($wallet?->available_balance ?? 0) - ($dir === 'credit' ? $amount : 0) + ($dir === 'debit' ? $amount : 0);
        Transaction::create([
            'reference' => $ref, 'payment_id' => $payment?->id, 'user_id' => $u->id, 'wallet_id' => $wallet?->id,
            'direction' => $dir, 'type' => $type, 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $wallet?->available_balance,
            'currency' => 'ETB', 'status' => 'completed', 'description' => $desc,
            'metadata' => json_encode(['demo' => true]),
            // Historical timestamps so Finance charts show a realistic spread
            'created_at' => $at ?? Carbon::now(), 'updated_at' => $at ?? Carbon::now(),
        ]);
    }
}
