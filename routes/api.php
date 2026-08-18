<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\FreelancerProfileController;
use App\Http\Controllers\Api\V1\EmployerProfileController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\MilestoneController;

/*
|--------------------------------------------------------------------------
| AppWorks API V1 Routes
|--------------------------------------------------------------------------
*/

// Health & System Status
Route::get('/health', [HealthController::class, 'health']);
Route::get('/status', [HealthController::class, 'status']);

// Public Profiles
Route::get('/freelancers/{id}', [FreelancerProfileController::class, 'showPublic']);
Route::get('/employers/{id}', [EmployerProfileController::class, 'showPublic']);

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

