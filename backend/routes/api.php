<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomBridgeWebhookController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordChangeController;
use App\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\PickingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\WarehouseController;
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

    // Custom Bridge Inbound Webhook Endpoints (Protected by HMAC-SHA256 signature verification)
    Route::prefix('bridge')->group(function () {
        Route::post('orders', [CustomBridgeWebhookController::class, 'orderWebhook']);
        Route::get('health', [CustomBridgeWebhookController::class, 'health']);
    });

    // Authenticated OMS / WMS Enterprise Core Routes
    Route::middleware('auth:sanctum')->group(function () {
        // Stores & Channels
        Route::get('stores', [StoreController::class, 'index']);
        Route::post('stores', [StoreController::class, 'store']);
        Route::get('stores/{store}', [StoreController::class, 'show']);
        Route::post('stores/{store}/sync', [StoreController::class, 'sync']);
        Route::delete('stores/{store}', [StoreController::class, 'destroy']);

        // Warehouses & Locations
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::post('warehouses', [WarehouseController::class, 'store']);
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);

        // Catalog & Products
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'store']);

        // Inventory & Stock Movements
        Route::get('inventory/movements', [StockMovementController::class, 'index']);
        Route::post('inventory/transfers', [StockMovementController::class, 'transfer']);
        Route::post('inventory/adjustments', [StockMovementController::class, 'adjust']);
        Route::get('inventory/availability', [StockMovementController::class, 'availability']);

        // Orders & Lifecycle
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);

        // Picking & Packing (Fulfillment)
        Route::get('picking', [PickingController::class, 'index']);
        Route::put('picking/{pickTask}/status', [PickingController::class, 'updateStatus']);
        Route::post('picking/{pickTask}/complete', [PickingController::class, 'complete']);
        Route::post('picking/bulk-update', [PickingController::class, 'bulkUpdate']);

        // Role-guarded overview
        Route::get('admin/overview', [OperationsController::class, 'adminOverview'])->middleware('role:admin');
        Route::get('operator/tasks', [OperationsController::class, 'operatorTasks'])->middleware('role:operator,admin');
    });
});
