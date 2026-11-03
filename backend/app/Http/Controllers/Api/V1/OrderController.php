<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Push\StoreWriterFactory;
use App\Models\Order;
use App\Models\RecurringOrder;
use App\Models\Store;
use App\Services\AccessControl;
use App\Services\InvoiceService;
use App\Services\OrderAllocationEngine;
use App\Services\OrderBulkService;
use App\Services\OrderControlService;
use App\Services\OrderImportService;
use App\Services\OrderService;
use App\Services\RecurringOrderService;
use App\Services\StorePushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
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

        // The company's own status
        $query->when($request->query('custom_status'), fn ($q, $key) => $q->where('custom_status', $key));

        // Hold filter: one kind of hold, or any held order
        $hold = $request->validate(['hold' => ['nullable', Rule::in([...OrderControlService::HOLD_TYPES, 'any'])]])['hold'] ?? null;
        $query->when($hold === 'any', fn ($q) => $q->whereNotNull('hold_type'))
            ->when($hold && $hold !== 'any', fn ($q) => $q->where('hold_type', $hold));

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
        $onHold = $metrics()->whereNotNull('hold_type')->count();

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
                'on_hold' => $onHold,
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
        // Lines are required for a new order; on an edit they are sent only when they change.
        $validated = $request->validate($this->orders->rules($request->user()->currentOrganization()->id, $order === null), OrderService::VALIDATION_MESSAGES);
        $validated['currency'] = strtoupper($validated['currency']);

        return $validated;
    }

    /**
     * Stop an order from being allocated, picked or dispatched until it is released.
     */
    public function hold(Request $request, Order $order, OrderControlService $controls): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        if ($order->hold_type !== null) {
            return response()->json(['status' => 'error', 'message' => "Order {$order->order_number} is already on hold."], 422);
        }
        $controls->hold($order, 'manual', $reason, $request->user());

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} is on hold.", 'data' => $this->holdState($order)]);
    }

    /**
     * Release a hold; releasing an approval hold approves the order.
     */
    public function release(Request $request, Order $order, OrderControlService $controls): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $message = $controls->release($order, $request->user());

        return response()->json(['status' => 'success', 'message' => $message, 'data' => $this->holdState($order)]);
    }

    /**
     * Everything that happened to the order, oldest first: its own history and its shipments' tracking.
     */
    public function timeline(Request $request, Order $order): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);

        $events = $order->events()->with('user:id,name')->get()->map(fn ($event) => [
            'type' => $event->type,
            'description' => $event->description,
            'user' => $event->user?->name,
            'at' => $event->created_at,
        ]);
        $tracking = $order->shipments()->with('events.user:id,name')->get()->flatMap(fn ($shipment) => $shipment->events->map(fn ($event) => [
            'type' => "shipment_{$event->type}",
            'description' => "{$shipment->shipment_number}: {$event->description}",
            'user' => $event->user?->name,
            'at' => $event->happened_at,
        ]));

        return response()->json(['status' => 'success', 'data' => $events->concat($tracking)->sortBy('at')->values()]);
    }

    /**
     * Move some quantities into a new order, e.g. to ship what is in stock now and the rest later.
     */
    public function split(Request $request, Order $order, OrderControlService $controls): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);
        $new = $controls->split($order, $data['items'], $request->user());

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} was split; the moved items are on order {$new->order_number}.", 'data' => $new], 201);
    }

    /**
     * Combine orders of one customer into the first of them; the others are cancelled.
     */
    public function merge(Request $request, OrderControlService $controls): JsonResponse
    {
        $ids = $request->validate([
            'order_ids' => ['required', 'array', 'min:2', 'max:20'],
            'order_ids.*' => ['integer', 'distinct', $this->inOrganization($request, 'orders')],
        ])['order_ids'];
        $merged = $controls->merge(Order::whereIn('id', $ids)->get(), $request->user());

        return response()->json(['status' => 'success', 'message' => count($ids)." orders were merged into order {$merged->order_number}.", 'data' => $merged]);
    }

    /**
     * Open orders past the shipping deadline or close to it.
     */
    public function sla(Request $request, OrderControlService $controls): JsonResponse
    {
        $storeId = $request->validate(['store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')]])['store_id'] ?? null;

        return response()->json(['status' => 'success', 'data' => $controls->sla($request->user()->currentOrganization(), $storeId)]);
    }

    public function controlSettings(Request $request, OrderControlService $controls): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $controls->settings($request->user()->currentOrganization())]);
    }

    /**
     * Approval limit for high-value orders (empty for none) and the hours an order may take to ship.
     */
    public function updateControlSettings(Request $request, OrderControlService $controls): JsonResponse
    {
        $data = $request->validate([
            'approval_threshold' => ['present', 'nullable', 'numeric', 'min:0.01', 'max:999999999'],
            'sla_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);
        $settings = ['approval_threshold' => $data['approval_threshold'] === null ? null : (float) $data['approval_threshold'], 'sla_hours' => (int) $data['sla_hours']];
        $controls->saveSettings($request->user()->currentOrganization(), $settings);

        return response()->json(['status' => 'success', 'message' => 'Order rules saved.', 'data' => $settings]);
    }

    /**
     * @return array<string, mixed>
     */
    private function holdState(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'hold' => $order->hold_type === null ? null : ['type' => $order->hold_type, 'reason' => $order->hold_reason, 'at' => $order->held_at],
        ];
    }

    /**
     * One action on many orders (allocate, hold, release, cancel, set the company status); each order is reported.
     */
    public function bulk(Request $request, OrderBulkService $bulk, AccessControl $access): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(OrderBulkService::ACTIONS))],
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct', $this->inOrganization($request, 'orders')],
            'reason' => ['required_if:action,hold', 'nullable', 'string', 'max:500'],
            'custom_status' => ['nullable', 'string', 'max:40'],
        ]);
        abort_unless($access->allows($request->user(), OrderBulkService::ACTIONS[$data['action']]), 403, 'You do not have permission for this action.');

        $result = $bulk->run($data['action'], Order::whereIn('id', $data['order_ids'])->orderBy('id')->get(), $request->user(), $data);
        $message = count($result['done']).' order(s) done.'.($result['failed'] ? ' '.count($result['failed']).' could not be changed.' : '');

        return response()->json(['status' => 'success', 'message' => $message, 'data' => $result]);
    }

    /**
     * Create orders from a CSV file (one row per line, rows with the same order_ref make one order).
     */
    public function import(Request $request, OrderImportService $imports): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')],
        ]);
        $numbers = $imports->import($data['file'], $request->user(), isset($data['store_id']) ? (int) $data['store_id'] : null);

        return response()->json(['status' => 'success', 'message' => count($numbers).' orders were imported.', 'data' => $numbers], 201);
    }

    public function customStatuses(Request $request, OrderControlService $controls): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $controls->customStatuses($request->user()->currentOrganization())]);
    }

    public function saveCustomStatuses(Request $request, OrderControlService $controls): JsonResponse
    {
        $statuses = $request->validate([
            'statuses' => ['present', 'array', 'max:30'],
            'statuses.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'distinct'],
            'statuses.*.label' => ['required', 'string', 'max:40'],
            'statuses.*.color' => ['required', Rule::in(OrderControlService::STATUS_COLORS)],
        ])['statuses'];
        $statuses = array_map(fn (array $status) => ['key' => $status['key'], 'label' => $status['label'], 'color' => $status['color']], $statuses);
        $controls->saveCustomStatuses($request->user()->currentOrganization(), $statuses);

        return response()->json(['status' => 'success', 'message' => 'Order statuses saved.', 'data' => $statuses]);
    }

    public function setCustomStatus(Request $request, Order $order, OrderControlService $controls): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $key = $request->validate(['custom_status' => ['present', 'nullable', 'string', 'max:40']])['custom_status'];
        $controls->setCustomStatus($order, $key, $request->user());

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} updated.", 'data' => $order->only(['id', 'order_number', 'custom_status'])]);
    }

    /**
     * Place this order again on a schedule.
     */
    public function repeat(Request $request, Order $order, RecurringOrderService $plans): JsonResponse
    {
        $this->ensureSameOrganization($request, $order);
        $data = $request->validate([
            'interval' => ['required', Rule::in(RecurringOrder::INTERVALS)],
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $plan = $plans->repeat($order, $data['interval'], Carbon::parse($data['starts_on']), $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Order {$order->order_number} will be placed again {$data['interval']}, starting {$plan->next_run_at->toFormattedDateString()}.",
            'data' => [...$plan->toArray(), 'items' => $plan->template['items']],
        ], 201);
    }

    private function ensureSameOrganization(Request $request, Order $order): void
    {
        abort_unless($order->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
