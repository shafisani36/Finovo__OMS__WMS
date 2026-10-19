<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\InventoryEngine;
use App\Services\OrderAllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        protected OrderAllocationEngine $allocationEngine,
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List orders with server-side pagination, search, status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = Order::where('organization_id', $org->id)
            ->with(['store', 'items.variant.product', 'items.allocatedWarehouse', 'pickTasks', 'shipments']);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('external_order_id', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->query('status')) {
            $query->where('order_status', $status);
        }

        // Store filter
        if ($storeId = $request->query('store_id')) {
            $query->where('store_id', $storeId);
        }

        $perPage = (int) $request->query('per_page', 10);
        $orders = $query->latest('placed_at')->paginate($perPage);

        // Metrics
        $totalOrders = Order::where('organization_id', $org->id)->count();
        $pending = Order::where('organization_id', $org->id)->whereIn('order_status', ['imported', 'pending'])->count();
        $allocated = Order::where('organization_id', $org->id)->where('order_status', 'allocated')->count();
        $shipped = Order::where('organization_id', $org->id)->where('order_status', 'shipped')->count();

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
            'kpis' => [
                'total_orders' => $totalOrders,
                'pending' => $pending,
                'allocated' => $allocated,
                'shipped' => $shipped,
            ],
        ]);
    }

    /**
     * Create manual order.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'store_id' => ['required', 'exists:stores,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'shipping_address' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($org, $validated) {
            $store = Store::where('organization_id', $org->id)->findOrFail($validated['store_id']);
            $orderNum = 'ORD-'.strtoupper(substr(uniqid(), -6));
            $extId = 'MAN-'.rand(1000, 9999);

            $order = Order::create([
                'organization_id' => $org->id,
                'store_id' => $store->id,
                'order_number' => $orderNum,
                'external_order_id' => $extId,
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'shipping_address' => $validated['shipping_address'] ?? null,
                'order_status' => 'pending',
                'payment_status' => 'paid',
                'fulfillment_status' => 'unfulfilled',
                'total_amount' => 0,
                'placed_at' => now(),
            ]);

            $total = 0;
            foreach ($validated['items'] as $itemData) {
                $product = Product::where('organization_id', $org->id)->findOrFail($itemData['product_id']);
                $variant = $product->variants()->first();
                $lineTotal = $itemData['quantity'] * $itemData['unit_price'];
                $total += $lineTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $variant ? $variant->id : null,
                    'external_sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'total_amount' => $lineTotal,
                    'status' => 'pending',
                ]);
            }

            $order->update(['total_amount' => $total]);

            // Attempt automatic allocation
            $order = $this->allocationEngine->allocateOrder($order);

            return response()->json([
                'status' => 'success',
                'message' => 'Order created and processed.',
                'data' => $order->load(['items', 'store']),
            ], 201);
        });
    }

    /**
     * Show order details.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($order->organization_id !== $org->id) {
            abort(403, 'Unauthorized.');
        }

        $order->load(['store', 'items.variant.product', 'items.allocatedWarehouse', 'pickTasks', 'shipments']);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    /**
     * Re-allocate order.
     */
    public function allocate(Request $request, Order $order): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($order->organization_id !== $org->id) {
            abort(403, 'Unauthorized.');
        }

        $order = $this->allocationEngine->allocateOrder($order);

        return response()->json([
            'status' => 'success',
            'message' => 'Order allocation recalculated.',
            'data' => $order->load(['items', 'pickTasks']),
        ]);
    }

    /**
     * Cancel order and release reservations.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($order->organization_id !== $org->id) {
            abort(403, 'Unauthorized.');
        }

        if (in_array($order->order_status, ['shipped', 'delivered'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot cancel an order that has already shipped.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            // Find and release all reservations
            $reservations = InventoryReservation::where('order_id', $order->id)
                ->where('status', 'active')
                ->get();

            foreach ($reservations as $res) {
                $this->inventoryEngine->releaseReservation($res->id);
            }

            $order->update([
                'order_status' => 'cancelled',
                'fulfillment_status' => 'cancelled',
            ]);

            // Cancel pick tasks
            $order->pickTasks()->update(['status' => 'not_started', 'notes' => 'Order cancelled']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Order cancelled and inventory reservations released.',
            'data' => $order,
        ]);
    }
}
