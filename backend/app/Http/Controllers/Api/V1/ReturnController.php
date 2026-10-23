<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Services\InventoryEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer returns: requested for a shipped order, received at the warehouse, then inspected and restocked.
 */
class ReturnController extends Controller
{
    /** Orders in these statuses have goods with the customer that can come back. */
    private const RETURNABLE_ORDER_STATUSES = ['partially_shipped', 'shipped', 'delivered', 'returned'];

    private const RELATIONS = ['order.store', 'warehouse', 'creator', 'items'];

    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List returns with KPIs. With ?store_id= only that store's orders are listed and counted.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $scoped = fn () => OrderReturn::where('organization_id', $org->id)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->whereHas('order', fn ($o) => $o->where('store_id', $storeId)));

        $returns = $scoped()
            ->with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('return_number', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($o) => $o
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"))))
            ->latest('id')
            ->paginate($this->perPage($request, 10));

        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $restocked = (int) ReturnItem::whereHas('orderReturn', fn ($r) => $r->whereIn('id', $scoped()->select('id'))
            ->where('status', 'completed')
            ->where('disposition', 'sellable'))
            ->sum('quantity');

        return response()->json([
            'status' => 'success',
            'data' => collect($returns->items())->map(fn (OrderReturn $return) => $this->present($return)),
            'meta' => [
                'current_page' => $returns->currentPage(),
                'last_page' => $returns->lastPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
            ],
            'kpis' => [
                'requested' => (int) ($byStatus['requested'] ?? 0),
                'received' => (int) ($byStatus['received'] ?? 0),
                'completed' => (int) ($byStatus['completed'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
                'restocked_units' => $restocked,
            ],
        ]);
    }

    /**
     * Shipped orders a return can be raised for, with how much of each line can still come back.
     */
    public function returnableOrders(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $orders = Order::where('organization_id', $org->id)
            ->whereIn('order_status', self::RETURNABLE_ORDER_STATUSES)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('order_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")))
            ->with('items')
            ->latest('id')
            ->limit(50)
            ->get();

        $returned = $this->returnedQuantities($orders->flatMap->items->pluck('id')->all());

        return response()->json([
            'status' => 'success',
            'data' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer_name ?: 'Unknown',
                'items' => $order->items
                    ->map(fn (OrderItem $item) => [
                        'id' => $item->id,
                        'product_name' => $item->product_name,
                        'sku' => $item->external_sku,
                        'shipped_quantity' => $item->fulfilled_quantity,
                        'returnable_quantity' => max(0, $item->fulfilled_quantity - ($returned[$item->id] ?? 0)),
                    ])
                    ->filter(fn (array $item) => $item['returnable_quantity'] > 0)
                    ->values(),
            ])->filter(fn (array $order) => $order['items']->isNotEmpty())->values(),
        ]);
    }

    /**
     * Raise a return for some or all of the shipped units of an order.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->where('organization_id', $org->id)],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => ['required', 'integer', 'distinct', Rule::exists('order_items', 'id')->where('order_id', $request->integer('order_id'))],
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $return = DB::transaction(function () use ($validated, $org, $request) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($validated['order_id']);

            if (! in_array($order->order_status, self::RETURNABLE_ORDER_STATUSES, true)) {
                throw ValidationException::withMessages(['order_id' => "Order {$order->order_number} has not been shipped, so nothing can be returned yet."]);
            }

            $returned = $this->returnedQuantities($order->items->pluck('id')->all());
            $lines = [];

            foreach ($validated['items'] as $line) {
                $item = $order->items->firstWhere('id', $line['order_item_id']);
                $returnable = $item->fulfilled_quantity - ($returned[$item->id] ?? 0);

                if ($line['quantity'] > $returnable) {
                    throw ValidationException::withMessages(['items' => "Only {$returnable} unit(s) of {$item->product_name} can be returned."]);
                }

                $lines[] = [
                    'order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $item->external_sku,
                    'product_name' => $item->product_name,
                    'quantity' => (int) $line['quantity'],
                    // Goods go back to the warehouse they were shipped from.
                    'warehouse_id' => $item->allocated_warehouse_id,
                ];
            }

            $return = OrderReturn::create([
                'organization_id' => $org->id,
                'order_id' => $order->id,
                'warehouse_id' => collect($lines)->pluck('warehouse_id')->filter()->first(),
                'created_by_user_id' => $request->user()->id,
                'return_number' => OrderReturn::nextNumber($org->id),
                'status' => 'requested',
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'items_count' => array_sum(array_column($lines, 'quantity')),
            ]);

            foreach ($lines as $line) {
                unset($line['warehouse_id']);
                $return->items()->create($line);
            }

            return $return;
        });

        return response()->json([
            'status' => 'success',
            'message' => "Return {$return->return_number} created for order {$return->order->order_number}.",
            'data' => $this->present($return->load(self::RELATIONS)),
        ], 201);
    }

    /**
     * The returned parcel has arrived at the warehouse and waits for inspection.
     */
    public function receive(Request $request, int $id): JsonResponse
    {
        $return = $this->transition($request, $id, ['requested'], 'received', fn (OrderReturn $return) => $return->update([
            'status' => 'received',
            'received_at' => now(),
        ]));

        return $this->respond($return, "Return {$return->return_number} received at the warehouse.");
    }

    /**
     * Record the inspection result. Sellable units go back into available stock, damaged and quarantined units
     * are put on hand but held out of sale, and disposed units do not return to stock.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in(OrderReturn::DISPOSITIONS)],
            'notes' => 'nullable|string|max:1000',
        ]);

        $return = $this->transition($request, $id, ['received'], 'completed', function (OrderReturn $return) use ($validated, $request) {
            if ($validated['disposition'] !== 'disposed') {
                if (! $return->warehouse_id) {
                    throw ValidationException::withMessages(['disposition' => 'This order was not shipped from a warehouse, so the items cannot be restocked. Choose Disposed instead.']);
                }

                foreach ($return->items->whereNotNull('product_variant_id') as $item) {
                    $this->inventoryEngine->receiveReturn(
                        organizationId: $return->organization_id,
                        warehouseId: $return->warehouse_id,
                        productVariantId: $item->product_variant_id,
                        quantity: $item->quantity,
                        condition: $validated['disposition'],
                        returnId: $return->id,
                        returnNumber: $return->return_number,
                        userId: $request->user()->id,
                    );
                }
            }

            $return->update([
                'status' => 'completed',
                'disposition' => $validated['disposition'],
                'notes' => $this->appendNote($return->notes, $validated['notes'] ?? null),
                'completed_at' => now(),
            ]);

            $this->markOrderReturnedWhenFullyBack($return->order);
        });

        return $this->respond($return, "Return {$return->return_number} completed.");
    }

    /**
     * Turn down a return request.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|min:3|max:1000']);

        $return = $this->transition($request, $id, ['requested', 'received'], 'rejected', fn (OrderReturn $return) => $return->update([
            'status' => 'rejected',
            'notes' => $this->appendNote($return->notes, "Rejected: {$validated['reason']}"),
        ]));

        return $this->respond($return, "Return {$return->return_number} rejected.");
    }

    /**
     * Lock the return, check it may move to $target from its current status, and apply the change.
     *
     * @param  list<string>  $from
     */
    private function transition(Request $request, int $id, array $from, string $target, callable $apply): OrderReturn
    {
        return DB::transaction(function () use ($request, $id, $from, $target, $apply) {
            $return = OrderReturn::where('organization_id', $request->user()->currentOrganization()->id)
                ->with(['items', 'order'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (! in_array($return->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "Return {$return->return_number} is {$return->status} and cannot be marked {$target}."]);
            }

            $apply($return);

            return $return;
        });
    }

    /**
     * Units already on a return (rejected returns do not count), per order item.
     *
     * @param  list<int>  $orderItemIds
     * @return array<int, int>
     */
    private function returnedQuantities(array $orderItemIds): array
    {
        return ReturnItem::whereIn('order_item_id', $orderItemIds)
            ->whereHas('orderReturn', fn (Builder $r) => $r->where('status', '!=', 'rejected'))
            ->selectRaw('order_item_id, sum(quantity) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    private function markOrderReturnedWhenFullyBack(Order $order): void
    {
        $order->load('items');
        $completed = ReturnItem::whereIn('order_item_id', $order->items->pluck('id'))
            ->whereHas('orderReturn', fn (Builder $r) => $r->where('status', 'completed'))
            ->sum('quantity');

        if ($completed >= $order->items->sum('fulfilled_quantity')) {
            $order->update(['order_status' => 'returned']);
        }
    }

    private function appendNote(?string $notes, ?string $addition): ?string
    {
        return $addition ? trim(($notes ? $notes."\n" : '').$addition) : $notes;
    }

    private function respond(OrderReturn $return, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $this->present($return->fresh(self::RELATIONS)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderReturn $return): array
    {
        return [
            'id' => $return->id,
            'return_number' => $return->return_number,
            'order_id' => $return->order_id,
            'order_number' => $return->order?->order_number,
            'customer' => $return->order?->customer_name ?: 'Unknown',
            'store' => $return->order?->store?->name,
            'warehouse' => $return->warehouse?->name,
            'status' => $return->status,
            'reason' => $return->reason,
            'disposition' => $return->disposition,
            'notes' => $return->notes,
            'items_count' => $return->items_count,
            'items' => $return->items->map(fn (ReturnItem $item) => [
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
            ]),
            'created_by' => $return->creator?->name,
            'created_at' => $return->created_at?->toIso8601String(),
            'received_at' => $return->received_at?->toIso8601String(),
            'completed_at' => $return->completed_at?->toIso8601String(),
        ];
    }
}
