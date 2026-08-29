<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\EmployerProfileController;
use App\Http\Controllers\Api\V1\FreelancerProfileController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\ProposalController;
use App\Http\Controllers\Api\V1\SkillController;
use App\Http\Controllers\Api\V1\PlatformSettingsController;
use App\Http\Controllers\Api\V1\VerificationSubmissionController;

/*
|--------------------------------------------------------------------------
| AppWorks API V1 Routes
|--------------------------------------------------------------------------
*/

// Health & System Status
Route::get('/health', [HealthController::class, 'health']);
Route::get('/status', [HealthController::class, 'status']);

// Public platform settings (no auth required)
Route::get('/platform-settings', [PlatformSettingsController::class, 'index']);

// Global Marketplace Search (public, maintenance-aware)
Route::middleware('maintenance')->group(function () {
    Route::get('/search', [SearchController::class, 'index']);
});

// Public Profiles & Portfolios (maintenance-aware)
Route::middleware('maintenance')->group(function () {
    Route::get('/freelancers', [FreelancerProfileController::class, 'index']);
    Route::get('/freelancers/{id}', [FreelancerProfileController::class, 'showPublic']);
    Route::get('/freelancers/{id}/portfolio', [PortfolioController::class, 'publicIndex']);
    Route::get('/employers/{id}', [EmployerProfileController::class, 'showPublic']);
});

// Marketplace Catalog (public, read-only, maintenance-aware)
Route::middleware('maintenance')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/skills', [SkillController::class, 'index']);
});

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

    // Portfolio Management (Freelancers)
    Route::get('/freelancer/portfolio', [PortfolioController::class, 'index']);
    Route::post('/freelancer/portfolio', [PortfolioController::class, 'store']);
    Route::get('/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'show']);
    Route::match(['put', 'patch'], '/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'update']);
    Route::delete('/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'destroy']);
    Route::put('/freelancer/portfolio-reorder', [PortfolioController::class, 'reorder']);

    // User Verification Submissions (Employer & Freelancer)
    Route::get('/verifications/me', [VerificationSubmissionController::class, 'showMe']);
    Route::post('/verifications', [VerificationSubmissionController::class, 'store']);
});

// Job Posting & Management
Route::middleware('maintenance')->group(function () {
    Route::get('/jobs', [JobController::class, 'index']);
    Route::get('/jobs/{job}', [JobController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/employer/jobs', [JobController::class, 'mine']);

    Route::post('/jobs', [JobController::class, 'store']);
    Route::put('/jobs/{job}', [JobController::class, 'update']);
    Route::delete('/jobs/{job}', [JobController::class, 'destroy']);
    Route::post('/jobs/{job}/close', [JobController::class, 'close']);
    Route::post('/jobs/{job}/reopen', [JobController::class, 'reopen']);
});

// Proposals & Bidding
Route::middleware('auth:sanctum')->group(function () {
    // Freelancer's own proposals
    Route::get('/proposals', [ProposalController::class, 'index']);
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show']);
    Route::put('/proposals/{proposal}', [ProposalController::class, 'update']);
    Route::post('/proposals/{proposal}/withdraw', [ProposalController::class, 'withdraw']);

    // Freelancer submits a proposal for a job (requires approved credentials)
    Route::post('/jobs/{job}/proposals', [ProposalController::class, 'store'])->middleware('verified.freelancer');

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
*/

use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VerificationController;
use App\Http\Controllers\Api\V1\Admin\JobController as AdminJobController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\SkillController as AdminSkillController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\FreelancerApprovalController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminSecurityController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SavedJobController;
use App\Http\Controllers\Api\V1\SavedFreelancerController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\MilestoneController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\Admin\FinanceController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\DisputeController;

// ── Disputes (User-facing) ──────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{report}', [DisputeController::class, 'show']);
    Route::post('/disputes/{report}/notes', [DisputeController::class, 'addNote']);
});

// ── Contracts ───────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/contracts', [ContractController::class, 'index']);
    Route::get('/contracts/{contract}', [ContractController::class, 'show']);
    Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
    Route::post('/contracts/{contract}/freelancer/accept', [ContractController::class, 'freelancerAccept']);
    Route::post('/contracts/{contract}/freelancer/decline', [ContractController::class, 'freelancerDecline']);
});

// ── Milestones ──────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/contracts/{contract}/milestones', [MilestoneController::class, 'index']);
    Route::get('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'show']);
    Route::post('/contracts/{contract}/milestones', [MilestoneController::class, 'store']);
    Route::put('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'update']);
    Route::delete('/contracts/{contract}/milestones/{milestone}', [MilestoneController::class, 'destroy']);
    Route::post('/contracts/{contract}/milestones/{milestone}/start', [MilestoneController::class, 'startWork']);
    Route::post('/contracts/{contract}/milestones/{milestone}/submit', [MilestoneController::class, 'submitWork']);
    Route::post('/contracts/{contract}/milestones/{milestone}/approve', [MilestoneController::class, 'approve']);
    Route::post('/contracts/{contract}/milestones/{milestone}/revision', [MilestoneController::class, 'requestRevision']);
    Route::post('/contracts/{contract}/milestones/{milestone}/dispute', [MilestoneController::class, 'openDispute']);

    // Delete a file from a submission
    Route::delete('/contracts/{contract}/milestones/{milestone}/submissions/{submission}/files', [MilestoneController::class, 'deleteSubmissionFile']);
});

// ── Serve milestone files ─────────────────────────────────────────────────
// Auth handled manually in controller (avoids Sanctum redirect-to-login issue)
Route::get('/milestone-submissions/{path}', [\App\Http\Controllers\Api\V1\FileController::class, 'milestoneSubmission'])->where('path', '.*');
Route::get('/milestone-attachments/{path}', [\App\Http\Controllers\Api\V1\FileController::class, 'milestoneAttachment'])->where('path', '.*');
Route::delete('/milestone-attachments/{attachment}', [\App\Http\Controllers\Api\V1\FileController::class, 'destroyAttachment']);

// ── Payment Methods ──────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'setDefault']);
    Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
});

// ── Payments ────────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/milestone/{milestone}', [PaymentController::class, 'getMilestonePayment']);
    Route::post('/payments/milestone/{milestone}/fund', [PaymentController::class, 'fundMilestone']);
});

// ── Earnings & Withdrawals ──────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/earnings', [WithdrawalController::class, 'earnings']);
    Route::get('/withdrawals', [WithdrawalController::class, 'index']);
    Route::post('/withdrawals', [WithdrawalController::class, 'store']);
    Route::post('/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel']);
});

// ── Transactions (Financial Ledger) ─────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/transactions', [TransactionController::class, 'index']);
});

// ── Webhooks ────────────────────────────────────────────────────────────
Route::post('/webhooks/{provider}', function ($provider) {
    $payload = file_get_contents('php://input');
    $signature = request()->header('X-Webhook-Signature', '');

    // Log the webhook
    \App\Models\WebhookLog::create([
        'provider'    => $provider,
        'event_type'  => request()->header('X-Webhook-Event', 'unknown'),
        'payload'     => json_decode($payload, true) ?? [],
        'status'      => 'received',
    ]);

    return response()->json(['received' => true]);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // User management
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('/users/{user}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.suspend,users.activate');
    Route::put('/users/{user}/role', [UserController::class, 'updateRole'])->middleware('permission:users.edit');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.edit');

    // Verification & credential management
    Route::get('/verifications', [VerificationController::class, 'index'])->middleware('permission:users.verify');
    Route::get('/verifications/{verification}', [VerificationController::class, 'show'])->middleware('permission:users.verify');
    Route::put('/verifications/{verification}/approve', [VerificationController::class, 'approve'])->middleware('permission:users.verify');
    Route::put('/verifications/{verification}/reject', [VerificationController::class, 'reject'])->middleware('permission:users.verify');

    Route::get('/credentials/{credential}', [VerificationController::class, 'showCredential'])->middleware('permission:users.verify');
    Route::get('/credentials/{credential}/download', [VerificationController::class, 'downloadCredential'])->middleware('permission:users.verify');
    Route::put('/credentials/{credential}/approve', [VerificationController::class, 'approveCredential'])->middleware('permission:users.verify');
    Route::put('/credentials/{credential}/reject', [VerificationController::class, 'rejectCredential'])->middleware('permission:users.verify');
    Route::put('/credentials/{credential}/resubmit', [VerificationController::class, 'requestResubmissionCredential'])->middleware('permission:users.verify');

    // Job moderation
    Route::get('/jobs', [AdminJobController::class, 'index'])->middleware('permission:jobs.view');
    Route::put('/jobs/{job}/status', [AdminJobController::class, 'moderate'])->middleware('permission:jobs.moderate');
    Route::delete('/jobs/{job}', [AdminJobController::class, 'destroy'])->middleware('permission:jobs.delete');

    // Reports / disputes
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:disputes.view');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->middleware('permission:disputes.view');
    Route::post('/reports/{report}/notes', [ReportController::class, 'addNote'])->middleware('permission:disputes.resolve');
    Route::put('/reports/{report}/resolve', [ReportController::class, 'resolve'])->middleware('permission:disputes.resolve');
    Route::delete('/reports/{report}', [ReportController::class, 'dismiss'])->middleware('permission:disputes.resolve');
    Route::delete('/reports/{report}/delete', [ReportController::class, 'destroy'])->middleware('permission:disputes.resolve');

    // Category management
    Route::get('/categories', [AdminCategoryController::class, 'index'])->middleware('permission:jobs.view');
    Route::post('/categories', [AdminCategoryController::class, 'store'])->middleware('permission:jobs.edit');
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->middleware('permission:jobs.edit');
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->middleware('permission:jobs.delete');

    // Skill management
    Route::get('/skills', [AdminSkillController::class, 'index'])->middleware('permission:jobs.view');
    Route::post('/skills', [AdminSkillController::class, 'store'])->middleware('permission:jobs.edit');
    Route::put('/skills/{skill}', [AdminSkillController::class, 'update'])->middleware('permission:jobs.edit');
    Route::delete('/skills/{skill}', [AdminSkillController::class, 'destroy'])->middleware('permission:jobs.delete');

    // Platform settings
    Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view');
    Route::put('/settings', [SettingController::class, 'update'])->middleware('permission:settings.edit');

    // Freelancer approval
    Route::get('/freelancers', [FreelancerApprovalController::class, 'index'])->middleware('permission:users.verify');
    Route::get('/freelancers/{freelancer}', [FreelancerApprovalController::class, 'show'])->middleware('permission:users.verify');
    Route::put('/freelancers/{freelancer}/approve', [FreelancerApprovalController::class, 'approve'])->middleware('permission:users.verify');
    Route::put('/freelancers/{freelancer}/reject', [FreelancerApprovalController::class, 'reject'])->middleware('permission:users.verify');

    // RBAC: Roles
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.edit');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:roles.assign');

    // RBAC: Permissions
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:roles.view');

    // RBAC: Admin user management
    Route::get('/admins', [AdminUserController::class, 'index'])->middleware('permission:admins.view');
    Route::post('/admins', [AdminUserController::class, 'store'])->middleware('permission:admins.create');
    Route::get('/admins/{user}', [AdminUserController::class, 'show'])->middleware('permission:admins.view');
    Route::put('/admins/{user}/status', [AdminUserController::class, 'updateStatus'])->middleware('permission:admins.activate,admins.deactivate');
    Route::put('/admins/{user}/roles', [AdminUserController::class, 'assignRoles'])->middleware('permission:admins.assign_role');
    Route::delete('/admins/{user}', [AdminUserController::class, 'destroy'])->middleware('permission:admins.create');

    // Admin Account Security
    Route::get('/account/security', [AdminSecurityController::class, 'show'])->middleware('permission:admin_account.view');
    Route::put('/account/email', [AdminSecurityController::class, 'updateEmail'])->middleware('permission:admin_account.update_email');
    Route::put('/account/password', [AdminSecurityController::class, 'updatePassword'])->middleware('permission:admin_account.change_password');

    // Finance
    Route::get('/finance/dashboard', [FinanceController::class, 'dashboard'])->middleware('permission:finance.view');
    Route::get('/finance/payments', [FinanceController::class, 'payments'])->middleware('permission:payments.view');
    Route::get('/finance/withdrawals', [FinanceController::class, 'withdrawals'])->middleware('permission:withdrawals.view');
    Route::post('/finance/withdrawals/{withdrawal}/approve', [FinanceController::class, 'approveWithdrawal'])->middleware('permission:withdrawals.manage');

    // Audit logs
    Route::get('/audit-logs', [AdminUserController::class, 'auditLogs'])->middleware('permission:audit_logs.view');
});

// Avatar management (all authenticated users)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/avatar', [AvatarController::class, 'store']);
    Route::delete('/avatar', [AvatarController::class, 'destroy']);
});

// Credential management (freelancers)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/credentials', [CredentialController::class, 'index']);
    Route::post('/credentials', [CredentialController::class, 'store']);
    Route::get('/credentials/{credential}', [CredentialController::class, 'show']);
    Route::put('/credentials/{credential}', [CredentialController::class, 'update']);
    Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy']);
    Route::get('/credentials/{credential}/download', [CredentialController::class, 'download']);
});

// Public verified credentials for a freelancer (maintenance-aware)
Route::middleware('maintenance')->get('/freelancers/{userId}/credentials', [CredentialController::class, 'publicCredentials']);

// Messaging
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/messages/conversations', [MessageController::class, 'conversations']);
    Route::get('/messages/unread', [MessageController::class, 'unreadCount']);
    Route::get('/messages/{userId}', [MessageController::class, 'messages']);
    Route::post('/messages', [MessageController::class, 'store']);
    Route::put('/messages/{message}/read', [MessageController::class, 'markRead']);
    Route::put('/messages/{userId}/read-all', [MessageController::class, 'markAllRead']);
});

// Notifications
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications/unread', [NotificationController::class, 'unreadCount']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
});

// Reviews
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/contracts/{contract}/review', [ReviewController::class, 'store']);
});

Route::middleware('maintenance')->group(function () {
    Route::get('/reviews/{review}', [ReviewController::class, 'show']);
    Route::get('/users/{userId}/reviews', [ReviewController::class, 'userReviews']);
});

// Saved Jobs & Saved Freelancers
Route::middleware('auth:sanctum')->group(function () {
    // Saved Jobs
    Route::get('/saved-jobs', [SavedJobController::class, 'index']);
    Route::post('/jobs/{job}/save', [SavedJobController::class, 'save']);
    Route::get('/jobs/{job}/saved', [SavedJobController::class, 'saved']);
    Route::delete('/jobs/{job}/save', [SavedJobController::class, 'destroy']);

    // Saved Freelancers
    Route::get('/saved-freelancers', [SavedFreelancerController::class, 'index']);
    Route::post('/freelancers/{freelancer}/save', [SavedFreelancerController::class, 'save']);
    Route::get('/freelancers/{freelancer}/saved', [SavedFreelancerController::class, 'saved']);
    Route::delete('/freelancers/{freelancer}/save', [SavedFreelancerController::class, 'destroy']);
});

// Recommendations
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/recommendations/jobs', [RecommendationController::class, 'jobs']);
    Route::get('/recommendations/freelancers', [RecommendationController::class, 'freelancers']);
});

// LMS Integration & Skill Tests
use App\Http\Controllers\Api\V1\LmsWebhookController;
use App\Http\Controllers\Api\V1\SkillTestController;

Route::middleware('maintenance')->group(function () {
    Route::post('/lms/certificate-completed', [LmsWebhookController::class, 'certificateCompleted']);
    Route::post('/lms/certificate-revoked', [LmsWebhookController::class, 'certificateRevoked']);
});

Route::middleware('maintenance')->group(function () {
    Route::get('/skill-tests', [SkillTestController::class, 'index']);
    Route::get('/skill-tests/{skillTest}', [SkillTestController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/skill-tests/{skillTest}/exemption', [SkillTestController::class, 'checkExemption']);
    Route::post('/skill-tests/{skillTest}/submit', [SkillTestController::class, 'submit']);
    Route::get('/my-test-history', [SkillTestController::class, 'history']);
});
