<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Store;
use App\Services\InventoryEngine;
use App\Services\InvoiceService;
use App\Services\OrderAllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        protected OrderAllocationEngine $allocationEngine,
        protected InventoryEngine $inventoryEngine,
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

        // Store filter (the store being managed); metrics follow the same store
        $storeId = $request->query('store_id');
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $perPage = (int) $request->query('per_page', 10);
        $orders = $query->latest('placed_at')->paginate($perPage);

        // Metrics
        $metrics = fn () => Order::where('organization_id', $org->id)->when($storeId, fn ($q) => $q->where('store_id', $storeId));
        $totalOrders = $metrics()->count();
        $pending = $metrics()->whereIn('order_status', ['imported', 'pending'])->count();
        $allocated = $metrics()->where('order_status', 'allocated')->count();
        $shipped = $metrics()->whereIn('order_status', ['partially_shipped', 'shipped', 'delivered', 'returned'])->count();

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
     * The order's fulfillment state and the next steps it allows (allocate, pick, dispatch, deliver, cancel).
     */
    public function fulfillment(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->organization_id === $request->user()->currentOrganization()->id, 404);

        return response()->json(['status' => 'success', 'data' => $order->fulfillmentSummary()]);
    }

    /**
     * Download the order's invoice PDF (numbered on first download).
     */
    public function invoice(Request $request, Order $order, InvoiceService $invoices): Response
    {
        abort_if($order->organization_id !== $request->user()->currentOrganization()->id, 404);

        return $invoices->download($order);
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

        if (! in_array($order->order_status, Order::ALLOCATABLE_STATUSES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => "Order {$order->order_number} is {$order->order_status} and has nothing left to allocate.",
            ], 422);
        }

        $order = $this->allocationEngine->allocateOrder($order);

        return response()->json([
            'status' => 'success',
            'message' => match ($order->order_status) {
                'allocated' => "Stock reserved for order {$order->order_number}. It is now on the picking list.",
                'partially_allocated' => "Only part of order {$order->order_number} could be reserved; the rest is out of stock.",
                default => "Order {$order->order_number} could not be allocated: ".($order->notes ?: 'not enough stock in any linked warehouse.'),
            },
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

        if (in_array($order->order_status, Order::SHIPPED_STATUSES, true)) {
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

            // Take the order off the picking list and withdraw a shipment still waiting for dispatch.
            $order->pickTasks()->update(['status' => 'cancelled', 'notes' => 'Order cancelled']);
            $order->shipments()->where('status', 'pending')->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Order cancelled and inventory reservations released.',
            'data' => $order,
        ]);
    }
}
