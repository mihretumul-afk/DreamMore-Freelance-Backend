<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\EmployerProfileController;
use App\Http\Controllers\Api\V1\FreelancerProfileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\ProposalController;
use App\Http\Controllers\Api\V1\SkillController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AppWorks API V1 Routes
|--------------------------------------------------------------------------
*/

// Health & System Status
Route::get('/health', [HealthController::class, 'health']);
Route::get('/status', [HealthController::class, 'status']);

// Public Profiles
Route::get('/freelancers', [FreelancerProfileController::class, 'index']);
Route::get('/freelancers/{id}', [FreelancerProfileController::class, 'showPublic']);
Route::get('/employers/{id}', [EmployerProfileController::class, 'showPublic']);

// Marketplace Catalog (public, read-only)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/skills', [SkillController::class, 'index']);

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Authenticated Auth Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Authenticated Freelancer & Employer Profile Endpoints
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/freelancer/profile', [FreelancerProfileController::class, 'showCurrent']);
    Route::put('/freelancer/profile', [FreelancerProfileController::class, 'update']);

    Route::get('/employer/profile', [EmployerProfileController::class, 'showCurrent']);
    Route::put('/employer/profile', [EmployerProfileController::class, 'update']);
});

// Stage 12 — Contracts & Milestones
Route::middleware('auth:sanctum')->group(function () {
    // Contracts
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/{contract}', [ContractController::class, 'show']);
    Route::post('/contracts/{contract}/pause', [ContractController::class, 'pause']);
    Route::post('/contracts/{contract}/resume', [ContractController::class, 'resume']);
    Route::post('/contracts/{contract}/complete', [ContractController::class, 'complete']);
    Route::post('/contracts/{contract}/cancel', [ContractController::class, 'cancel']);

    // Milestones
    Route::get('/contracts/{contract}/milestones', [MilestoneController::class, 'index']);
    Route::post('/contracts/{contract}/milestones', [MilestoneController::class, 'store']);
    Route::get('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'show']);
    Route::put('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'update']);
    Route::post('/contracts/{contract}/milestones/{milestone}/submit', [MilestoneController::class, 'submit']);
    Route::post('/contracts/{contract}/milestones/{milestone}/approve', [MilestoneController::class, 'approve']);
    Route::post('/contracts/{contract}/milestones/{milestone}/revision', [MilestoneController::class, 'revision']);
    Route::delete('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'destroy']);
});

// Stage 13 — Job Posting & Management
// Public job browsing (open jobs only).
Route::get('/jobs', [JobController::class, 'index']);
Route::get('/jobs/{job}', [JobController::class, 'show']);

// Authenticated job management (employer/admin).
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/employer/jobs', [JobController::class, 'mine']);

    Route::post('/jobs', [JobController::class, 'store']);
    Route::put('/jobs/{job}', [JobController::class, 'update']);
    Route::delete('/jobs/{job}', [JobController::class, 'destroy']);
    Route::post('/jobs/{job}/close', [JobController::class, 'close']);
    Route::post('/jobs/{job}/reopen', [JobController::class, 'reopen']);
});

// Stage 14 — Proposals & Bidding
Route::middleware('auth:sanctum')->group(function () {
    // Freelancer's own proposals
    Route::get('/proposals', [ProposalController::class, 'index']);
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show']);
    Route::put('/proposals/{proposal}', [ProposalController::class, 'update']);
    Route::post('/proposals/{proposal}/withdraw', [ProposalController::class, 'withdraw']);

    // Freelancer submits a proposal for a job
    Route::post('/jobs/{job}/proposals', [ProposalController::class, 'store']);

    // Employer proposal management (own jobs only)
    Route::get('/jobs/{job}/proposals', [ProposalController::class, 'jobProposals']);
    Route::get('/jobs/{job}/proposals/{proposal}', [ProposalController::class, 'showJobProposal']);
    Route::post('/jobs/{job}/proposals/{proposal}/shortlist', [ProposalController::class, 'shortlist']);
    Route::post('/jobs/{job}/proposals/{proposal}/reject', [ProposalController::class, 'reject']);
    Route::post('/jobs/{job}/proposals/{proposal}/accept', [ProposalController::class, 'accept']);
});

// Role-Protected Test Authorization Endpoints
Route::middleware(['auth:sanctum'])->group(function () {
    Route::middleware('role:freelancer')->get('/freelancer/dashboard-test', function () {
        return response()->json(['success' => true, 'message' => 'Welcome Freelancer! Access granted.']);
    });

    Route::middleware('role:employer')->get('/employer/dashboard-test', function () {
        return response()->json(['success' => true, 'message' => 'Welcome Employer! Access granted.']);
    });

    Route::middleware('role:admin')->get('/admin/dashboard-test', function () {
        return response()->json(['success' => true, 'message' => 'Welcome Admin! Access granted.']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Routes — /api/v1/admin/*
|--------------------------------------------------------------------------
|
| All admin endpoints require authentication + role=admin.
| The existing EnsureRole middleware already returns 403 for wrong roles.
|
*/

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VerificationController;
use App\Http\Controllers\Api\V1\Admin\JobController as AdminJobController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\Api\V1\Admin\SettingController;

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // User management
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
    Route::put('/users/{user}/role', [UserController::class, 'updateRole']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Verification management
    Route::get('/verifications', [VerificationController::class, 'index']);
    Route::get('/verifications/{verification}', [VerificationController::class, 'show']);
    Route::put('/verifications/{verification}/approve', [VerificationController::class, 'approve']);
    Route::put('/verifications/{verification}/reject', [VerificationController::class, 'reject']);

    // Job moderation
    Route::get('/jobs', [AdminJobController::class, 'index']);
    Route::put('/jobs/{job}/status', [AdminJobController::class, 'moderate']);
    Route::delete('/jobs/{job}', [AdminJobController::class, 'destroy']);

    // Reports management
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{report}', [ReportController::class, 'show']);
    Route::put('/reports/{report}/resolve', [ReportController::class, 'resolve']);
    Route::delete('/reports/{report}', [ReportController::class, 'dismiss']);
    Route::delete('/reports/{report}/delete', [ReportController::class, 'destroy']);

    // Category management
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

    // Skill management
    Route::get('/skills', [AdminSkillController::class, 'index']);
    Route::post('/skills', [AdminSkillController::class, 'store']);
    Route::put('/skills/{skill}', [AdminSkillController::class, 'update']);
    Route::delete('/skills/{skill}', [AdminSkillController::class, 'destroy']);

    // Platform settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);
});
