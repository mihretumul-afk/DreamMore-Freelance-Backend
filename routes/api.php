<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Auth\GoogleAuthController;
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
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\Admin\ContactMessageController as AdminContactMessageController;
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
use App\Http\Controllers\Api\V1\Admin\FinanceController;
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
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\FeaturedController;
use App\Http\Controllers\Api\V1\AddFundsController;
use App\Http\Controllers\Api\V1\LmsWebhookController;
use App\Http\Controllers\Api\V1\SkillTestController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

// ── Cache Clearing Helper Route ──
Route::get('/clear-all-caches', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    
    return response()->json([
        'success' => true,
        'message' => 'Laravel route, config, and application caches cleared successfully!'
    ]);
});

// ── Temporary Admin Promotion Route ──
Route::get('/make-me-admin/{email}', function ($email) {
    try {
        $user = \App\Models\User::where('email', $email)->first();
        
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found.'], 404);
        }

        $user->role = 'admin';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => "User {$email} successfully promoted to admin!"
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'line' => $e->getLine()
        ], 500);
    }
});

/*
|--------------------------------------------------------------------------
| AppWorks API V1 Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    // ── Seed Super Admin Route ──
    Route::get('/seed-super-admin', function () {
        $user = \App\Models\User::updateOrCreate(
            ['email' => 'admin@dreammore.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('Admin123'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Super admin admin@dreammore.com created successfully with password Admin123!'
        ]);
    });

    // Health & System Status
    Route::get('/health', [HealthController::class, 'health']);
    Route::get('/status', [HealthController::class, 'status']);

    // Public platform settings
    Route::get('/platform-settings', [PlatformSettingsController::class, 'index']);

    // Public Contact form
    Route::post('/contact-messages', [ContactMessageController::class, 'store'])->middleware('throttle:5,1');

    // Global Marketplace Search
    Route::middleware('maintenance')->group(function () {
        Route::get('/search', [SearchController::class, 'index']);
    });

    // Public Profiles & Portfolios
    Route::middleware('maintenance')->group(function () {
        Route::get('/freelancers', [FreelancerProfileController::class, 'index']);
        Route::get('/freelancers/{id}', [FreelancerProfileController::class, 'showPublic']);
        Route::get('/freelancers/{id}/portfolio', [PortfolioController::class, 'publicIndex']);
        Route::get('/employers/{id}', [EmployerProfileController::class, 'showPublic']);
    });

    // Marketplace Catalog
    Route::middleware('maintenance')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/skills', [SkillController::class, 'index']);
    });

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

        // Authenticated Auth Routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/google/complete-role', [GoogleAuthController::class, 'completeRole']);
        });
    });

    // Root API aliases
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

    // Authenticated Profiles
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/freelancer/profile', [FreelancerProfileController::class, 'showCurrent']);
        Route::put('/freelancer/profile', [FreelancerProfileController::class, 'update']);

        Route::get('/employer/profile', [EmployerProfileController::class, 'showCurrent']);
        Route::put('/employer/profile', [EmployerProfileController::class, 'update']);

        // Portfolio Management
        Route::get('/freelancer/portfolio', [PortfolioController::class, 'index']);
        Route::post('/freelancer/portfolio', [PortfolioController::class, 'store']);
        Route::get('/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'show']);
        Route::match(['put', 'patch'], '/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'update']);
        Route::delete('/freelancer/portfolio/{portfolio}', [PortfolioController::class, 'destroy']);
        Route::put('/freelancer/portfolio-reorder', [PortfolioController::class, 'reorder']);

        // User Verification Submissions
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
        Route::get('/proposals', [ProposalController::class, 'index']);
        Route::get('/proposals/{proposal}', [ProposalController::class, 'show']);
        Route::put('/proposals/{proposal}', [ProposalController::class, 'update']);
        Route::post('/proposals/{proposal}/withdraw', [ProposalController::class, 'withdraw']);

        Route::post('/jobs/{job}/proposals', [ProposalController::class, 'store'])->middleware('verified.freelancer');

        Route::get('/jobs/{job}/proposals', [ProposalController::class, 'jobProposals']);
        Route::get('/jobs/{job}/proposals/{proposal}', [ProposalController::class, 'showJobProposal']);
        Route::post('/jobs/{job}/proposals/{proposal}/shortlist', [ProposalController::class, 'shortlist']);
        Route::post('/jobs/{job}/proposals/{proposal}/reject', [ProposalController::class, 'reject']);
        Route::post('/jobs/{job}/proposals/{proposal}/accept', [ProposalController::class, 'accept']);
    });

    // Test Endpoints
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

    // Featured Listings
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/jobs/{job}/feature', [FeaturedController::class, 'featureJob']);
        Route::get('/jobs/{job}/featured', [FeaturedController::class, 'jobFeaturedStatus']);
        Route::post('/freelancer-profile/feature', [FeaturedController::class, 'featureProfile']);
        Route::get('/freelancer-profile/featured', [FeaturedController::class, 'profileFeaturedStatus']);
    });

    // Disputes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/disputes', [DisputeController::class, 'index']);
        Route::get('/disputes/{report}', [DisputeController::class, 'show']);
        Route::post('/disputes/{report}/notes', [DisputeController::class, 'addNote']);
        Route::delete('/disputes/{report}', [DisputeController::class, 'destroy']);
    });

    // Contracts
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index']);
        Route::get('/contracts/{contract}', [ContractController::class, 'show']);
        Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
        Route::post('/contracts/{contract}/freelancer/accept', [ContractController::class, 'freelancerAccept']);
        Route::post('/contracts/{contract}/freelancer/decline', [ContractController::class, 'freelancerDecline']);
    });

    // Milestones
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
        Route::delete('/contracts/{contract}/milestones/{milestone}/submissions/{submission}/files', [MilestoneController::class, 'deleteSubmissionFile']);
    });

    // Serve milestone files
    Route::get('/milestone-submissions/{path}', [\App\Http\Controllers\Api\V1\FileController::class, 'milestoneSubmission'])->where('path', '.*');
    Route::get('/milestone-attachments/{path}', [\App\Http\Controllers\Api\V1\FileController::class, 'milestoneAttachment'])->where('path', '.*');
    Route::delete('/milestone-attachments/{attachment}', [\App\Http\Controllers\Api\V1\FileController::class, 'destroyAttachment']);

    // Payment Methods
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        Route::put('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'setDefault']);
        Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);
    });

    // Payments
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/milestone/{milestone}', [PaymentController::class, 'getMilestonePayment']);
        Route::post('/payments/milestone/{milestone}/fund', [PaymentController::class, 'fundMilestone']);
    });

    // Earnings & Withdrawals
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/earnings', [WithdrawalController::class, 'earnings']);
        Route::get('/employer-finance', [WithdrawalController::class, 'employerFinance']);
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
        Route::post('/withdrawals/{withdrawal}/cancel', [WithdrawalController::class, 'cancel']);
        Route::delete('/withdrawals/{withdrawal}', [WithdrawalController::class, 'destroy']);
    });

    // Transactions
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);
    });

    // Add Funds
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/wallet/deposit', [AddFundsController::class, 'initiate']);
        Route::get('/wallet/deposit/{payment}', [AddFundsController::class, 'status']);
        Route::post('/wallet/deposit/reconcile/{reference}', [AddFundsController::class, 'reconcile']);
        Route::post('/wallet/deposit/reconcile-all', [AddFundsController::class, 'reconcileAll']);
    });

    Route::post('/webhooks/deposit/{provider}', [AddFundsController::class, 'webhook']);

    // Budget
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/budget', [BudgetController::class, 'show']);
        Route::post('/budget', [BudgetController::class, 'store']);
    });

    // Webhooks
    Route::post('/webhooks/{provider}', function ($provider) {
        $payload = file_get_contents('php://input');

        \App\Models\WebhookLog::create([
            'provider'    => $provider,
            'event_type'  => request()->header('X-Webhook-Event', 'unknown'),
            'payload'     => json_decode($payload, true) ?? [],
            'status'      => 'received',
        ]);

        return response()->json(['received' => true]);
    });

    // ── Admin Group Protected by AdminOnly Middleware ──
    Route::middleware(['auth:sanctum', AdminOnly::class])->prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // User management
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::put('/users/{user}/role', [UserController::class, 'updateRole']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        // Verification & credential management
        Route::get('/verifications', [VerificationController::class, 'index']);
        Route::get('/verifications/{verification}', [VerificationController::class, 'show']);
        Route::put('/verifications/{verification}/approve', [VerificationController::class, 'approve']);
        Route::put('/verifications/{verification}/reject', [VerificationController::class, 'reject']);

        Route::get('/credentials/{credential}', [VerificationController::class, 'showCredential']);
        Route::get('/credentials/{credential}/download', [VerificationController::class, 'downloadCredential']);
        Route::put('/credentials/{credential}/approve', [VerificationController::class, 'approveCredential']);
        Route::put('/credentials/{credential}/reject', [VerificationController::class, 'rejectCredential']);
        Route::put('/credentials/{credential}/resubmit', [VerificationController::class, 'requestResubmissionCredential']);

        // Job moderation
        Route::get('/jobs', [AdminJobController::class, 'index']);
        Route::put('/jobs/{job}/status', [AdminJobController::class, 'moderate']);
        Route::delete('/jobs/{job}', [AdminJobController::class, 'destroy']);

        // Reports / disputes
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{report}', [ReportController::class, 'show']);
        Route::post('/reports/{report}/notes', [ReportController::class, 'addNote']);
        Route::put('/reports/{report}/resolve', [ReportController::class, 'resolve']);
        Route::delete('/reports/{report}', [ReportController::class, 'dismiss']);
        Route::delete('/reports/{report}/delete', [ReportController::class, 'destroy']);

        // Contact messages
        Route::get('/contacts', [AdminContactMessageController::class, 'index']);
        Route::get('/contacts/{contactMessage}', [AdminContactMessageController::class, 'show']);
        Route::patch('/contacts/{contactMessage}', [AdminContactMessageController::class, 'updateStatus']);
        Route::delete('/contacts/{contactMessage}', [AdminContactMessageController::class, 'destroy']);

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

        // Freelancer approval
        Route::get('/freelancers', [FreelancerApprovalController::class, 'index']);
        Route::get('/freelancers/{freelancer}', [FreelancerApprovalController::class, 'show']);
        Route::put('/freelancers/{freelancer}/approve', [FreelancerApprovalController::class, 'approve']);
        Route::put('/freelancers/{freelancer}/reject', [FreelancerApprovalController::class, 'reject']);

        // RBAC: Roles
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

        // RBAC: Permissions
        Route::get('/permissions', [PermissionController::class, 'index']);

        // RBAC: Admin user management
        Route::get('/admins', [AdminUserController::class, 'index']);
        Route::post('/admins', [AdminUserController::class, 'store']);
        Route::get('/admins/{user}', [AdminUserController::class, 'show']);
        Route::put('/admins/{user}/status', [AdminUserController::class, 'updateStatus']);
        Route::put('/admins/{user}/roles', [AdminUserController::class, 'assignRoles']);
        Route::delete('/admins/{user}', [AdminUserController::class, 'destroy']);

        // Admin Account Security
        Route::get('/account/security', [AdminSecurityController::class, 'show']);
        Route::put('/account/email', [AdminSecurityController::class, 'updateEmail']);
        Route::put('/account/password', [AdminSecurityController::class, 'updatePassword']);

        // Finance
        Route::get('/finance/dashboard', [FinanceController::class, 'dashboard']);
        Route::get('/finance/payments', [FinanceController::class, 'payments']);
        Route::get('/finance/withdrawals', [FinanceController::class, 'withdrawals']);
        Route::post('/finance/withdrawals/{withdrawal}/approve', [FinanceController::class, 'approveWithdrawal']);
        Route::delete('/finance/withdrawals/{withdrawal}', [FinanceController::class, 'deleteWithdrawal']);
        Route::delete('/finance/payments/{payment}', [FinanceController::class, 'deletePayment']);
        
        // Platform Revenue Management
        Route::get('/finance/platform-revenue', [FinanceController::class, 'getPlatformRevenue']);
        Route::get('/finance/banks', [FinanceController::class, 'banks']);
        Route::post('/finance/withdraw-revenue', [FinanceController::class, 'withdrawPlatformRevenue']);
        Route::post('/finance/add-funds', [FinanceController::class, 'addPlatformFunds']);
        Route::post('/finance/add-funds/reconcile/{reference}', [FinanceController::class, 'reconcileDeposit']);
        Route::post('/finance/add-funds/reconcile-all', [FinanceController::class, 'reconcileAllDeposits']);

        // Audit logs
        Route::get('/audit-logs', [AdminUserController::class, 'auditLogs']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/avatar', [AvatarController::class, 'store']);
        Route::delete('/avatar', [AvatarController::class, 'destroy']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/credentials', [CredentialController::class, 'index']);
        Route::post('/credentials', [CredentialController::class, 'store']);
        Route::get('/credentials/{credential}', [CredentialController::class, 'show']);
        Route::put('/credentials/{credential}', [CredentialController::class, 'update']);
        Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy']);
        Route::get('/credentials/{credential}/download', [CredentialController::class, 'download']);
    });

    Route::middleware('maintenance')->get('/freelancers/{userId}/credentials', [CredentialController::class, 'publicCredentials']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/messages/conversations', [MessageController::class, 'conversations']);
        Route::get('/messages/unread', [MessageController::class, 'unreadCount']);
        Route::get('/messages/{userId}', [MessageController::class, 'messages']);
        Route::post('/messages', [MessageController::class, 'store']);
        Route::put('/messages/{message}/read', [MessageController::class, 'markRead']);
        Route::put('/messages/{userId}/read-all', [MessageController::class, 'markAllRead']);
        Route::post('/messages/call/signal', [MessageController::class, 'sendCallSignal']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notifications/unread', [NotificationController::class, 'unreadCount']);
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/contracts/{contract}/review', [ReviewController::class, 'store']);
    });

    Route::middleware('maintenance')->group(function () {
        Route::get('/reviews/{review}', [ReviewController::class, 'show']);
        Route::get('/users/{userId}/reviews', [ReviewController::class, 'userReviews']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/saved-jobs', [SavedJobController::class, 'index']);
        Route::post('/jobs/{job}/save', [SavedJobController::class, 'save']);
        Route::get('/jobs/{job}/saved', [SavedJobController::class, 'saved']);
        Route::delete('/jobs/{job}/save', [SavedJobController::class, 'destroy']);

        Route::get('/saved-freelancers', [SavedFreelancerController::class, 'index']);
        Route::post('/freelancers/{freelancer}/save', [SavedFreelancerController::class, 'save']);
        Route::get('/freelancers/{freelancer}/saved', [SavedFreelancerController::class, 'saved']);
        Route::delete('/freelancers/{freelancer}/save', [SavedFreelancerController::class, 'destroy']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/recommendations/jobs', [RecommendationController::class, 'jobs']);
        Route::get('/recommendations/freelancers', [RecommendationController::class, 'recommendations']);
    });

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

});