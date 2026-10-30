<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Push\StoreWriterFactory;
use App\Models\Order;
use App\Models\Store;
use App\Services\AccessControl;
use App\Services\InvoiceService;
use App\Services\OrderAllocationEngine;
use App\Services\OrderService;
use App\Services\StorePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        protected OrderAllocationEngine $allocationEngine,
        protected OrderService $orders,
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

        // Warehouse filter: orders sent to that warehouse (a warehouse's Orders tab), only for the user's warehouses
        $warehouseId = $request->validate(['warehouse_id' => ['nullable', 'integer', $this->accessibleWarehouse($request)]])['warehouse_id'] ?? null;
        $query->when($warehouseId, fn ($q) => $q->assignedToWarehouse($warehouseId));

        $perPage = $this->perPage($request, 10);
        $orders = $query->latest('placed_at')->paginate($perPage);

        // Metrics
        $metrics = fn () => Order::where('organization_id', $org->id)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($warehouseId, fn ($q) => $q->assignedToWarehouse($warehouseId));
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

        $summary = $order->fulfillmentSummary();
        // Offer only warehouses the user works in.
        $ids = app(AccessControl::class)->warehouseIds($request->user());
        if ($ids !== null) {
            $summary['warehouse_options'] = array_values(array_filter($summary['warehouse_options'], fn (array $option) => in_array($option['id'], $ids, true)));
        }

        return response()->json(['status' => 'success', 'data' => $summary]);
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

        $warehouseId = $request->validate(['warehouse_id' => ['nullable', 'integer', $this->accessibleWarehouse($request)]])['warehouse_id'] ?? null;
        $order = $this->allocationEngine->allocateOrder($order, $warehouseId);

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
     * Cancel the order: reserved stock is released, and the order is cancelled on its store too.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $report = $this->orders->cancel($order);

        return response()->json([
            'status' => 'success',
            'message' => trim("Order {$order->order_number} was cancelled and its reserved stock released. ".$report->message('cancelled')),
            'data' => $order->fresh(),
            'push' => $report->toArray(),
        ]);
    }

    /**
     * Put an order taken in Finovo on its store, with the status it has reached. Orders already on their store
     * (imported from it, or sent before) are kept in step automatically, so they are not pushed.
     */
    public function push(Request $request, Order $order, StorePushService $push, StoreWriterFactory $writers): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $store = $order->store;

        $refusal = match (true) {
            ! $store => "Order {$order->order_number} has no store. Edit it and choose its store first.",
            ! $writers->for($store) => $store->isConnected()
                ? "{$store->name} is a {$store->platform->label()}, which keeps its orders in Finovo only."
                : "{$store->name} is not connected. Connect it on the Stores page first.",
            (bool) $order->external_order_id => "Order {$order->order_number} is already on {$store->name}; its status, shipments and cancellation are sent there automatically.",
            $order->order_status === 'cancelled' => "Order {$order->order_number} is cancelled and was never on {$store->name}, so there is nothing to send.",
            default => null,
        };

        if ($refusal) {
            return response()->json(['status' => 'error', 'message' => $refusal], 422);
        }

        $report = $push->pushOrder($order);

        if ($report->hasFailures()) {
            return response()->json(['status' => 'error', 'message' => "Order {$order->order_number} could not be sent to {$store->name}: ".$report->failed[$store->name]], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order {$order->order_number} was sent to {$store->name} with its current status (".str_replace('_', ' ', $order->order_status).').',
            'data' => $order->fresh(['store']),
        ]);
    }

    /**
     * Create an order by hand (phone, walk-in or wholesale); it is also created on the chosen store.
     */
    public function store(Request $request): JsonResponse
    {
        [$order, $report] = $this->orders->create($request->user(), $this->validateOrder($request));

        return response()->json([
            'status' => 'success',
            'message' => trim("Order {$order->order_number} was created. ".$report->message('created')),
            'data' => $order,
            'push' => $report->toArray(),
        ], 201);
    }

    /**
     * Change an order that is still waiting for stock.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $this->orders->ensureEditable($order);
        [$order, $report] = $this->orders->update($order, $this->validateOrder($request, $order));

        return response()->json([
            'status' => 'success',
            'message' => trim("Order {$order->order_number} was saved. ".$report->message('updated')),
            'data' => $order,
            'push' => $report->toArray(),
        ]);
    }

    /**
     * Delete an order created in Finovo by mistake (never one on a store or one that moved stock).
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $this->orders->delete($order);

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} was deleted."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request, ?Order $order = null): array
    {
        $address = fn (string $field) => [
            $field => ['nullable', 'array'],
            "{$field}.name" => ['nullable', 'string', 'max:255'],
            "{$field}.company" => ['nullable', 'string', 'max:255'],
            "{$field}.address1" => ['nullable', 'string', 'max:255'],
            "{$field}.address2" => ['nullable', 'string', 'max:255'],
            "{$field}.city" => ['nullable', 'string', 'max:120'],
            "{$field}.province" => ['nullable', 'string', 'max:120'],
            "{$field}.zip" => ['nullable', 'string', 'max:30'],
            "{$field}.country_code" => ['nullable', 'string', 'size:2'],
            "{$field}.phone" => ['nullable', 'string', 'max:50'],
        ];
        // Lines are required for a new order; on an edit they are sent only when they change.
        $linesRule = $order ? 'sometimes' : 'required';

        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            ...$address('shipping_address'),
            ...$address('billing_address'),
            'payment_status' => ['required', Rule::in(['pending', 'paid'])],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items' => [$linesRule, 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', $this->inOrganization($request, 'product_variants')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'items.required' => 'Add at least one product to the order.',
            'items.*.product_variant_id.distinct' => 'A product is listed twice; change its quantity instead.',
        ]);

        $validated['currency'] = strtoupper($validated['currency']);

        return $validated;
    }

    private function ensureSameOrganization(Request $request, Order $order): void
    {
        abort_unless($order->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
