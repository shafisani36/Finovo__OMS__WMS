<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomBridgeWebhookController;
use App\Http\Controllers\Api\V1\CycleCountController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryReceiptController;
use App\Http\Controllers\Api\V1\InventoryTransferController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordChangeController;
use App\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\PickingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReplenishmentController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
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

        // Warehouses & Configuration Lifecycle
        Route::get('warehouses/metadata', [WarehouseController::class, 'metadata']);
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::post('warehouses', [WarehouseController::class, 'store']);
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::get('warehouses/{warehouse}/readiness', [WarehouseController::class, 'readiness']);
        Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate']);
        Route::post('warehouses/{warehouse}/deactivate', [WarehouseController::class, 'deactivate']);
        Route::post('warehouses/{warehouse}/begin-closure', [WarehouseController::class, 'beginClosure']);
        Route::get('warehouses/{warehouse}/locations', [WarehouseController::class, 'locations']);
        Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
        Route::put('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'updateLocation']);
        Route::delete('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'destroyLocation']);
        Route::post('warehouses/{warehouse}/locations/starter-layout', [WarehouseController::class, 'starterLayout']);
        Route::get('warehouses/{warehouse}/staff', [WarehouseController::class, 'staff']);
        Route::post('warehouses/{warehouse}/staff', [WarehouseController::class, 'assignStaff']);
        Route::delete('warehouses/{warehouse}/staff/{staffAssignment}', [WarehouseController::class, 'removeStaff']);
        Route::post('warehouses/{warehouse}/opening-stock/preview', [WarehouseController::class, 'previewOpeningStock']);
        Route::post('warehouses/{warehouse}/opening-stock/commit', [WarehouseController::class, 'commitOpeningStock']);
        Route::get('warehouses/{warehouse}/audit-logs', [WarehouseController::class, 'auditLogs']);

        // Catalog & Products
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'store']);

        // Enterprise Inventory Engine Endpoints
        Route::prefix('inventory')->group(function () {
            Route::get('/', [InventoryController::class, 'index']);
            Route::get('kpis', [InventoryController::class, 'kpis']);
            Route::get('sku/{variantId}', [InventoryController::class, 'skuDetail']);
            Route::post('reconcile', [InventoryController::class, 'reconcile']);

            // Receipts & Putaway
            Route::get('receipts', [InventoryReceiptController::class, 'index']);
            Route::post('receipts', [InventoryReceiptController::class, 'store']);
            Route::post('receipts/{receiptId}/confirm', [InventoryReceiptController::class, 'confirm']);
            Route::post('receipts/{receiptId}/putaway', [InventoryReceiptController::class, 'putaway']);

            // Multi-Stage Transfers
            Route::get('transfers', [InventoryTransferController::class, 'index']);
            Route::post('transfers', [InventoryTransferController::class, 'store']);
            Route::post('transfers/{transferId}/receive', [InventoryTransferController::class, 'receive']);

            // Adjustments & Approvals
            Route::get('adjustments', [StockAdjustmentController::class, 'index']);
            Route::post('adjustments', [StockAdjustmentController::class, 'store']);
            Route::post('adjustments/{adjustmentId}/approve', [StockAdjustmentController::class, 'approve']);
            Route::post('adjustments/{adjustmentId}/reject', [StockAdjustmentController::class, 'reject']);

            // Cycle Counts & Reconciliation
            Route::get('cycle-counts', [CycleCountController::class, 'index']);
            Route::post('cycle-counts', [CycleCountController::class, 'store']);
            Route::put('cycle-counts/{countId}/record-counts', [CycleCountController::class, 'recordCounts']);
            Route::post('cycle-counts/{countId}/approve-and-post', [CycleCountController::class, 'approveAndPost']);

            // Replenishment & Reorder Rules
            Route::get('replenishment/rules', [ReplenishmentController::class, 'rules']);
            Route::post('replenishment/rules', [ReplenishmentController::class, 'saveRule']);
            Route::get('replenishment/recommendations', [ReplenishmentController::class, 'recommendations']);

            // Backward Compatibility
            Route::get('movements', [StockMovementController::class, 'index']);
            Route::post('legacy-transfers', [StockMovementController::class, 'transfer']);
            Route::post('legacy-adjustments', [StockMovementController::class, 'adjust']);
            Route::get('availability', [StockMovementController::class, 'availability']);
        });

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
