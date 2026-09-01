<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Category;
use App\Models\Contract;
use App\Models\Credential;
use App\Models\Job;
use App\Models\Payment;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Skill;
use App\Models\User;
use App\Models\Verification;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseApiController
{
    /**
     * Platform-wide aggregated statistics and recent activity for administrators.
     * Requires auth:sanctum and role:admin.
     */
    public function index(): JsonResponse
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();

        // ── User metrics ────────────────────────────────────────────────
        $totalUsers = User::count();
        $totalFreelancers = User::where('role', 'freelancer')->count();
        $totalEmployers = User::where('role', 'employer')->count();
        $totalAdmins = User::where('role', 'admin')->count();

        // ── Job metrics ─────────────────────────────────────────────────
        $totalJobs = Job::count();
        $openJobs = Job::where('status', 'open')->count();
        $completedJobs = Job::where('status', 'completed')->count();
        $inProgressJobs = Job::where('status', 'in_progress')->count();

        // ── Proposal metrics ────────────────────────────────────────────
        $totalProposals = Proposal::count();

        // ── Contract metrics ────────────────────────────────────────────
        $totalContracts = Contract::count();
        $activeContracts = Contract::where('status', 'active')->count();
        $completedContracts = Contract::where('status', 'completed')->count();
        $cancelledContracts = Contract::where('status', 'cancelled')->count();

        // ── Financial metrics ───────────────────────────────────────────
        // Total payment volume (all completed escrow fundings)
        $totalPaymentVolume = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->sum('amount');

        // Monthly payment volume
        $monthlyPaymentVolume = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('amount');

        // Platform revenue = sum of platform fees collected from all completed escrow payments
        // After our workflow fix, platform_fee is calculated at release time and stored on the
        // escrow payment. We also sum from the milestone_released payments for accuracy.
        $totalPlatformFeesFromEscrow = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->sum('platform_fee');

        $totalPlatformFeesFromRelease = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->where('platform_fee', '>', 0)
            ->sum('platform_fee');

        // Use the greater of the two (release fees are more accurate after the workflow fix)
        $totalRevenue = max((float) $totalPlatformFeesFromEscrow, (float) $totalPlatformFeesFromRelease);

        // Monthly platform revenue
        $monthlyPlatformFeesFromEscrow = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->where('platform_fee', '>', 0)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('platform_fee');

        $monthlyPlatformFeesFromRelease = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_MILESTONE_RELEASED)
            ->where('platform_fee', '>', 0)
            ->where('processed_at', '>=', $startOfMonth)
            ->sum('platform_fee');

        $monthlyRevenue = max((float) $monthlyPlatformFeesFromEscrow, (float) $monthlyPlatformFeesFromRelease);

        // Total fees (platform + processing) for display
        $totalFees = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('type', Payment::TYPE_ESCROW_FUNDED)
            ->sum(DB::raw('COALESCE(platform_fee, 0) + COALESCE(processing_fee, 0)'));

        // ── Withdrawal metrics ──────────────────────────────────────────
        $pendingWithdrawals = Withdrawal::where('status', Withdrawal::STATUS_REQUESTED)
            ->count();

        $completedWithdrawals = Withdrawal::where('status', Withdrawal::STATUS_COMPLETED)
            ->sum('amount');

        // ── Moderation metrics ──────────────────────────────────────────
        $totalCategories = Category::count();
        $totalSkills = Skill::count();
        $pendingVerifications = 0;
        $pendingCredentials = Credential::where('status', 'pending')->count();
        $pendingReports = Report::where('status', 'pending')->count();
        $activeDisputes = Report::where('status', 'pending')
            ->where('target_type', 'milestone')
            ->count();
        $totalMessages = DB::table('messages')->count();

        // ── Recent activity ─────────────────────────────────────────────
        $recentUsers = User::select('id', 'name', 'email', 'role', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        $recentJobs = Job::select('id', 'title', 'status', 'min_budget', 'max_budget', 'currency', 'created_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // ── User growth data (last 7 days) ─────────────────────────────
        $userGrowth = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $count = User::whereDate('created_at', $day->toDateString())->count();
            $userGrowth[] = [
                'label' => $day->format('D'),
                'value' => $count,
            ];
        }

        // ── Revenue growth data (last 7 days) ──────────────────────────
        $revenueGrowth = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i);
            $dayRevenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->where('type', Payment::TYPE_ESCROW_FUNDED)
                ->whereDate('processed_at', $day->toDateString())
                ->sum('platform_fee');
            $revenueGrowth[] = [
                'label' => $day->format('D'),
                'value' => (float) $dayRevenue,
            ];
        }

        return $this->sendResponse([
            // Users
            'total_users'         => $totalUsers,
            'total_freelancers'   => $totalFreelancers,
            'total_employers'     => $totalEmployers,
            'total_admins'        => $totalAdmins,

            // Jobs
            'total_jobs'          => $totalJobs,
            'open_jobs'           => $openJobs,
            'active_jobs'         => $openJobs,
            'completed_jobs'      => $completedJobs,
            'in_progress_jobs'    => $inProgressJobs,

            // Proposals
            'total_proposals'     => $totalProposals,

            // Contracts
            'total_contracts'     => $totalContracts,
            'active_contracts'    => $activeContracts,
            'completed_contracts' => $completedContracts,
            'cancelled_contracts' => $cancelledContracts,

            // Finance
            'total_revenue'       => (float) $totalRevenue,
            'monthly_revenue'     => (float) $monthlyRevenue,
            'platform_fees'       => (float) $totalFees,
            'total_payment_volume'=> (float) $totalPaymentVolume,

            // Withdrawals
            'pending_withdrawals'   => $pendingWithdrawals,
            'completed_withdrawals' => (float) $completedWithdrawals,

            // Moderation
            'total_categories'      => $totalCategories,
            'total_skills'          => $totalSkills,
            'pending_verifications' => $pendingVerifications,
            'pending_credentials'   => $pendingCredentials,
            'pending_reports'       => $pendingReports,
            'active_disputes'       => $activeDisputes,
            'total_messages'        => $totalMessages,

            // Charts
            'user_growth'      => $userGrowth,
            'revenue_growth'   => $revenueGrowth,

            // Recent data
            'recent_users' => $recentUsers,
            'recent_jobs'  => $recentJobs,
        ], 'Admin dashboard retrieved successfully.');
    }
}
