<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\CustomBridgeWebhookController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CycleCountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\GoodsReceivedNoteController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryReceiptController;
use App\Http\Controllers\Api\V1\InventoryTransferController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordChangeController;
use App\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\PickingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequisitionController;
use App\Http\Controllers\Api\V1\ReplenishmentController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StoreAuthorizationController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\StoreWebhookController;
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

    // Store notifications (webhooks): verified by the store's signature, processed after the answer is sent
    Route::prefix('webhooks')->middleware('throttle:600,1')->group(function () {
        Route::post('shopify/compliance', [StoreWebhookController::class, 'shopifyCompliance'])->name('webhooks.shopify.compliance');
        Route::post('{platform}/{store}', [StoreWebhookController::class, 'receive'])->whereNumber('store')->name('webhooks.store');
    });

    // Store Platform Authorization Callbacks (called by Shopify, WooCommerce, BigCommerce and eBay; verified by signed state / platform signature)
    Route::prefix('integrations')->name('integrations.')->middleware('throttle:60,1')->group(function () {
        Route::get('shopify/callback', [StoreAuthorizationController::class, 'shopifyCallback'])->name('shopify.callback');
        Route::post('woocommerce/callback', [StoreAuthorizationController::class, 'wooCommerceCallback'])->name('woocommerce.callback');
        Route::get('bigcommerce/install', [StoreAuthorizationController::class, 'bigCommerceInstall'])->name('bigcommerce.install');
        Route::get('bigcommerce/callback', [StoreAuthorizationController::class, 'bigCommerceCallback'])->name('bigcommerce.callback');
        Route::get('bigcommerce/load', [StoreAuthorizationController::class, 'bigCommerceLoad'])->name('bigcommerce.load');
        Route::get('bigcommerce/uninstall', [StoreAuthorizationController::class, 'bigCommerceUninstall'])->name('bigcommerce.uninstall');
        Route::get('modules/{store}', [StoreAuthorizationController::class, 'moduleDownload'])->middleware('signed')->name('module.download');
        Route::post('prestashop/callback', [StoreAuthorizationController::class, 'prestaShopCallback'])->name('prestashop.callback');
        Route::post('opencart/callback', [StoreAuthorizationController::class, 'openCartCallback'])->name('opencart.callback');
        Route::get('wix/callback', [StoreAuthorizationController::class, 'wixCallback'])->name('wix.callback');
        Route::get('ebay/callback', [StoreAuthorizationController::class, 'ebayCallback'])->name('ebay.callback');
    });

    // Authenticated OMS / WMS Enterprise Core Routes.
    // Every action names the permission it needs (App\Enums\Permission); which warehouse or record the
    // request touches is checked again in the controllers (App\Services\AccessControl).
    Route::middleware('auth:sanctum')->group(function () {
        // Stores & Channels (the list feeds the store switcher, so any employee can read it)
        Route::get('stores', [StoreController::class, 'index']);
        Route::get('stores/{store}', [StoreController::class, 'show']);
        Route::post('stores', [StoreController::class, 'store'])->middleware('permission:stores.connect');
        Route::get('stores/{store}/authorization', [StoreController::class, 'authorization'])->middleware('permission:stores.connect');
        Route::post('stores/{store}/test-connection', [StoreController::class, 'testConnection'])->middleware('permission:stores.connect');
        Route::post('stores/{store}/sync', [StoreController::class, 'sync'])->middleware('permission:stores.sync');
        Route::post('stores/{store}/webhooks', [StoreController::class, 'enableWebhooks'])->middleware('permission:stores.connect');
        Route::delete('stores/{store}', [StoreController::class, 'destroy'])->middleware('permission:stores.delete');

        // Warehouses (the list feeds warehouse pickers and only shows the employee's own warehouses)
        Route::get('warehouses/metadata', [WarehouseController::class, 'metadata']);
        Route::get('warehouses', [WarehouseController::class, 'index']);
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouses.manage');
        Route::middleware('permission:warehouses.view')->group(function () {
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show']);
            Route::get('warehouses/{warehouse}/readiness', [WarehouseController::class, 'readiness']);
            Route::get('warehouses/{warehouse}/locations', [WarehouseController::class, 'locations']);
            Route::get('warehouses/{warehouse}/audit-logs', [WarehouseController::class, 'auditLogs']);
            Route::get('warehouses/{warehouse}/overview', [WarehouseController::class, 'overview']);
            Route::get('warehouses/{warehouse}/activity', [WarehouseController::class, 'activity']);
        });
        Route::middleware('permission:warehouses.manage')->group(function () {
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate']);
            Route::post('warehouses/{warehouse}/deactivate', [WarehouseController::class, 'deactivate']);
            Route::post('warehouses/{warehouse}/begin-closure', [WarehouseController::class, 'beginClosure']);
            Route::post('warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
            Route::put('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'updateLocation']);
            Route::delete('warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'destroyLocation']);
            Route::post('warehouses/{warehouse}/locations/starter-layout', [WarehouseController::class, 'starterLayout']);
            Route::post('warehouses/{warehouse}/opening-stock/preview', [WarehouseController::class, 'previewOpeningStock']);
            Route::post('warehouses/{warehouse}/opening-stock/commit', [WarehouseController::class, 'commitOpeningStock']);
        });
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('permission:warehouses.delete');
        Route::get('warehouses/{warehouse}/staff', [WarehouseController::class, 'staff'])->middleware('permission:warehouse.employees.view');
        Route::post('warehouses/{warehouse}/staff', [WarehouseController::class, 'assignStaff'])->middleware('permission:warehouse.employees.assign');
        Route::delete('warehouses/{warehouse}/staff/{staffAssignment}', [WarehouseController::class, 'removeStaff'])->middleware('permission:warehouse.employees.assign');

        // Catalog & Products
        Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view');
        Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.manage');
        Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.manage');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');

        // Inventory
        Route::prefix('inventory')->group(function () {
            Route::middleware('permission:inventory.view')->group(function () {
                Route::get('/', [InventoryController::class, 'index']);
                Route::get('kpis', [InventoryController::class, 'kpis']);
                Route::get('sku/{variantId}', [InventoryController::class, 'skuDetail']);
                Route::get('receipts', [InventoryReceiptController::class, 'index']);
                Route::get('transfers', [InventoryTransferController::class, 'index']);
                Route::get('adjustments', [StockAdjustmentController::class, 'index']);
                Route::get('cycle-counts', [CycleCountController::class, 'index']);
                Route::get('replenishment/rules', [ReplenishmentController::class, 'rules']);
                Route::get('replenishment/recommendations', [ReplenishmentController::class, 'recommendations']);
                Route::get('replenishment/alerts', [ReplenishmentController::class, 'alerts']);
                Route::get('movements', [StockMovementController::class, 'index']);
                Route::get('availability', [StockMovementController::class, 'availability']);
            });

            Route::middleware('permission:inventory.receive')->group(function () {
                Route::post('receipts', [InventoryReceiptController::class, 'store']);
                Route::post('receipts/{receiptId}/confirm', [InventoryReceiptController::class, 'confirm']);
                Route::post('receipts/{receiptId}/putaway', [InventoryReceiptController::class, 'putaway']);
            });

            Route::middleware('permission:inventory.transfer')->group(function () {
                Route::post('transfers', [InventoryTransferController::class, 'store']);
                Route::post('transfers/{transferId}/receive', [InventoryTransferController::class, 'receive']);
                Route::post('legacy-transfers', [StockMovementController::class, 'transfer']);
            });

            Route::middleware('permission:inventory.adjust')->group(function () {
                Route::post('reconcile', [InventoryController::class, 'reconcile']);
                Route::post('adjustments', [StockAdjustmentController::class, 'store']);
                Route::post('cycle-counts', [CycleCountController::class, 'store']);
                Route::put('cycle-counts/{countId}/record-counts', [CycleCountController::class, 'recordCounts']);
                Route::post('replenishment/rules', [ReplenishmentController::class, 'saveRule']);
                Route::post('legacy-adjustments', [StockMovementController::class, 'adjust']);
            });

            Route::middleware('permission:inventory.approve')->group(function () {
                Route::post('adjustments/{adjustmentId}/approve', [StockAdjustmentController::class, 'approve']);
                Route::post('adjustments/{adjustmentId}/reject', [StockAdjustmentController::class, 'reject']);
                Route::post('cycle-counts/{countId}/approve-and-post', [CycleCountController::class, 'approveAndPost']);
            });
        });

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');

        // Orders & Lifecycle
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order}', [OrderController::class, 'show']);
            Route::get('orders/{order}/invoice', [OrderController::class, 'invoice']);
            Route::get('orders/{order}/fulfillment', [OrderController::class, 'fulfillment']);
        });
        Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.manage');
        Route::put('orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.manage');
        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete');
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->middleware('permission:orders.allocate');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:orders.cancel');

        // Picking & Packing
        Route::get('picking', [PickingController::class, 'index'])->middleware('permission:picking.view');
        Route::middleware('permission:picking.update')->group(function () {
            Route::put('picking/{pickTask}/status', [PickingController::class, 'updateStatus']);
            Route::post('picking/{pickTask}/complete', [PickingController::class, 'complete']);
            Route::post('picking/bulk-update', [PickingController::class, 'bulkUpdate']);
        });

        // Shipments (packed orders are dispatched from here)
        Route::get('shipments', [ShipmentController::class, 'index'])->middleware('permission:shipments.view');
        Route::post('shipments/{id}/dispatch', [ShipmentController::class, 'dispatch'])->middleware('permission:shipments.dispatch');
        Route::post('shipments/{id}/deliver', [ShipmentController::class, 'deliver'])->middleware('permission:shipments.dispatch');

        // Barcodes (label settings and scanning)
        Route::get('barcode/settings', [BarcodeController::class, 'settings']);
        Route::put('barcode/settings', [BarcodeController::class, 'updateSettings'])->middleware('permission:settings.barcode');
        Route::get('barcode/scan', [BarcodeController::class, 'scan']);

        // Returns (customer RMAs, inspection and restocking)
        Route::prefix('returns')->group(function () {
            Route::get('/', [ReturnController::class, 'index'])->middleware('permission:returns.view');
            Route::middleware('permission:returns.manage')->group(function () {
                Route::get('returnable-orders', [ReturnController::class, 'returnableOrders']);
                Route::post('/', [ReturnController::class, 'store']);
                Route::post('{id}/receive', [ReturnController::class, 'receive']);
                Route::post('{id}/complete', [ReturnController::class, 'complete']);
                Route::post('{id}/reject', [ReturnController::class, 'reject']);
            });
        });

        // Purchase Requisitions (Procurement Planning & Approval)
        Route::prefix('purchase-requisitions')->group(function () {
            Route::middleware('permission:purchasing.view')->group(function () {
                Route::get('/', [PurchaseRequisitionController::class, 'index']);
                Route::get('stock-context', [PurchaseRequisitionController::class, 'stockContext']);
                Route::get('{id}', [PurchaseRequisitionController::class, 'show']);
                Route::get('{id}/pdf', [PurchaseRequisitionController::class, 'downloadPdf']);
            });
            Route::post('/', [PurchaseRequisitionController::class, 'store'])->middleware('permission:purchasing.request');
            Route::post('{id}/submit', [PurchaseRequisitionController::class, 'submit'])->middleware('permission:purchasing.request');
            Route::post('{id}/approve', [PurchaseRequisitionController::class, 'approve'])->middleware('permission:purchasing.approve');
            Route::post('{id}/reject', [PurchaseRequisitionController::class, 'reject'])->middleware('permission:purchasing.approve');
            Route::post('{id}/convert-po', [PurchaseRequisitionController::class, 'convertToPo'])->middleware('permission:purchasing.order');
        });

        // Purchase Orders (Supplier Commitments)
        Route::prefix('purchase-orders')->group(function () {
            Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.view');
            Route::get('{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.view');
            Route::middleware('permission:purchasing.order')->group(function () {
                Route::post('/', [PurchaseOrderController::class, 'store']);
                Route::post('{id}/issue', [PurchaseOrderController::class, 'issue']);
                Route::post('{id}/cancel', [PurchaseOrderController::class, 'cancel']);
            });
        });

        // Goods Received Notes (Physical Receiving, Inspection & Authoritative Stock Posting)
        Route::prefix('goods-received-notes')->group(function () {
            Route::middleware('permission:purchasing.view')->group(function () {
                Route::get('/', [GoodsReceivedNoteController::class, 'index']);
                Route::get('{id}', [GoodsReceivedNoteController::class, 'show']);
                Route::get('{id}/pdf', [GoodsReceivedNoteController::class, 'downloadPdf']);
            });
            Route::middleware('permission:purchasing.receive')->group(function () {
                Route::post('/', [GoodsReceivedNoteController::class, 'store']);
                Route::put('{id}/inspect', [GoodsReceivedNoteController::class, 'inspect']);
                Route::post('{id}/post', [GoodsReceivedNoteController::class, 'postToInventory']);
            });
        });

        // Employees: accounts, roles, per-employee permissions and warehouses
        Route::prefix('employees')->group(function () {
            Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employees.view');
            Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:employees.create');
            Route::put('{id}', [EmployeeController::class, 'update'])->middleware('permission:employees.update');
            Route::put('{id}/status', [EmployeeController::class, 'updateStatus'])->middleware('permission:employees.suspend');
            Route::post('{id}/reset-password', [EmployeeController::class, 'resetPassword'])->middleware('permission:employees.reset_password');
            Route::delete('{id}', [EmployeeController::class, 'destroy'])->middleware('permission:employees.delete');
            Route::get('{id}/permissions', [EmployeeController::class, 'permissions'])->middleware('permission:employees.manage_permissions');
            Route::put('{id}/permissions', [EmployeeController::class, 'updatePermissions'])->middleware('permission:employees.manage_permissions');
        });

        // Role-guarded overview
        Route::get('admin/overview', [OperationsController::class, 'adminOverview'])->middleware('role:admin');
        Route::get('operator/tasks', [OperationsController::class, 'operatorTasks'])->middleware('role:operator,manager,sales,admin');
    });
});
