<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Services\CustomBridgeService;
use App\Services\OrderAllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomBridgeWebhookController extends Controller
{
    public function __construct(
        protected CustomBridgeService $bridgeService,
        protected OrderAllocationEngine $allocationEngine
    ) {}

    /**
     * Inbound order webhook from customer custom website via Finovo Custom Bridge.
     */
    public function orderWebhook(Request $request): JsonResponse
    {
        try {
            $store = $this->bridgeService->verifyRequest($request);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 401);
        }

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        return DB::transaction(function () use ($store, $validated) {
            // Deduplicate external order
            $existing = Order::where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('external_order_id', $validated['order_id'])
                ->first();

            if ($existing) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Order already accepted (idempotent response).',
                    'order_id' => $existing->order_number,
                ], 200);
            }

            $orderNumber = 'ORD-'.strtoupper(substr(uniqid(), -6));

            $order = Order::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'order_number' => $orderNumber,
                'external_order_id' => $validated['order_id'],
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,
                'order_status' => 'imported',
                'payment_status' => 'paid',
                'fulfillment_status' => 'unfulfilled',
                'total_amount' => $validated['total_amount'] ?? 0,
                'currency' => $validated['currency'] ?? 'USD',
                'placed_at' => now(),
            ]);

            $total = 0;
            foreach ($validated['items'] as $item) {
                // Try to resolve matching product/variant by SKU
                $product = Product::where('organization_id', $store->organization_id)
                    ->where('sku', $item['sku'])
                    ->first();

                $variant = $product ? $product->variants()->first() : null;
                $lineTotal = $item['quantity'] * $item['price'];
                $total += $lineTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variant ? $variant->id : null,
                    'external_sku' => $item['sku'],
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'total_amount' => $lineTotal,
                    'status' => 'pending',
                ]);
            }

            if (! isset($validated['total_amount'])) {
                $order->update(['total_amount' => $total]);
            }

            // Create Outbox Event for reliable downstream notification
            OutboxEvent::create([
                'organization_id' => $store->organization_id,
                'event_type' => 'order.imported',
                'payload' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'store_id' => $store->id,
                ],
                'status' => 'pending',
            ]);

            // Allocate and reserve stock
            $this->allocationEngine->allocateOrder($order);

            return response()->json([
                'status' => 'success',
                'message' => 'Order successfully received, signed, and queued for fulfillment.',
                'order_number' => $order->order_number,
                'external_id' => $order->external_order_id,
            ], 201);
        });
    }

    /**
     * Custom Bridge health check endpoint.
     */
    public function health(Request $request): JsonResponse
    {
        try {
            $store = $this->bridgeService->verifyRequest($request);

            return response()->json([
                'status' => 'healthy',
                'store' => $store->name,
                'platform' => $store->platform,
                'timestamp' => time(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 401);
        }
    }
}
