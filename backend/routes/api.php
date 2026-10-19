<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\PasswordChangeController;
use App\Http\Controllers\Api\V1\PasswordRecoveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Finovo OMS / WMS API Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Authentication & Recovery Endpoints
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
        Route::post('otp/resend', [AuthController::class, 'resendOtp'])->middleware('throttle:otp-resend');
        Route::post('forgot-password', [PasswordRecoveryController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
        Route::post('password/verify-reset-otp', [PasswordRecoveryController::class, 'verifyResetOtp'])->middleware('throttle:otp-verify');
        Route::post('password/reset', [PasswordRecoveryController::class, 'resetPassword'])->middleware('throttle:login');

        // Protected Auth Routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::put('password/change', [PasswordChangeController::class, 'changePassword'])->middleware('throttle:password-change');
        });
    });

    // Role-Guarded Demonstration Routes
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('admin/overview', [OperationsController::class, 'adminOverview']);
    });

    Route::middleware(['auth:sanctum', 'role:operator,admin'])->group(function () {
        Route::get('operator/tasks', [OperationsController::class, 'operatorTasks']);
    });
});
