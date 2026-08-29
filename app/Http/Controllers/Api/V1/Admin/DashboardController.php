<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Category;
use App\Models\Job;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Skill;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;

class DashboardController extends BaseApiController
{
    /**
     * Platform-wide aggregated statistics and recent activity for administrators.
     * Requires auth:sanctum and role:admin.
     */
    public function index(): JsonResponse
    {
        // User metrics
        $totalUsers = User::count();
        $totalFreelancers = User::where('role', 'freelancer')->count();
        $totalEmployers = User::where('role', 'employer')->count();
        $totalAdmins = User::where('role', 'admin')->count();

        // Job metrics
        $totalJobs = Job::count();
        $openJobs = Job::where('status', 'open')->count();
        $completedJobs = Job::where('status', 'completed')->count();

        // Proposal metrics
        $totalProposals = Proposal::count();
        $totalContracts = 0;
        $activeContracts = 0;
        $completedContracts = 0;
        $totalRevenue = 0;

        // Platform catalog & moderation metrics
        $totalCategories = Category::count();
        $totalSkills = Skill::count();
        $pendingVerifications = Verification::where('status', 'pending')->count();
        $pendingReports = Report::where('status', 'pending')->count();

        // Recent activity (sensitive attributes like password/remember_token omitted)
        $recentUsers = User::select('id', 'name', 'email', 'role', 'status', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentJobs = Job::select('id', 'title', 'status', 'min_budget', 'max_budget', 'currency', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $this->sendResponse([
            'total_users' => $totalUsers,
            'total_freelancers' => $totalFreelancers,
            'total_employers' => $totalEmployers,
            'total_admins' => $totalAdmins,
            'total_jobs' => $totalJobs,
            'open_jobs' => $openJobs,
            'active_jobs' => $openJobs,
            'completed_jobs' => $completedJobs,
            'total_proposals' => $totalProposals,
            'total_contracts' => $totalContracts,
            'active_contracts' => $activeContracts,
            'completed_contracts' => $completedContracts,
            'total_revenue' => (float) $totalRevenue,
            'total_categories' => $totalCategories,
            'total_skills' => $totalSkills,
            'pending_verifications' => $pendingVerifications,
            'pending_reports' => $pendingReports,
            'recent_users' => $recentUsers,
            'recent_jobs' => $recentJobs,
        ], 'Admin dashboard retrieved successfully.');
    }
}
