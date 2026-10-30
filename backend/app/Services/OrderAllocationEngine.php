<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PickTask;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAllocationEngine
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * Active warehouses that can fulfil the order: the store's linked warehouses, or every active warehouse
     * when the store has none linked. In the store's warehouse order (or the warehouses' own priority).
     *
     * @return Collection<int, Warehouse>
     */
    public function eligibleWarehouses(Order $order): Collection
    {
        $order->loadMissing('store.warehouses');

        return $order->store && $order->store->warehouses->isNotEmpty()
            ? $order->store->warehouses()->where('warehouses.is_active', true)->get()
            : Warehouse::where('organization_id', $order->organization_id)->where('is_active', true)->orderBy('priority', 'asc')->get();
    }

    /**
     * The warehouses the order can be sent to, and whether each has stock for everything still unallocated.
     *
     * @return list<array{id: int, name: string, code: string|null, can_fulfil: bool}>
     */
    public function warehouseOptions(Order $order): array
    {
        $order->loadMissing('items');
        $open = $order->items->filter(fn ($item) => in_array($item->status, ['pending', 'exception'], true));

        return $this->eligibleWarehouses($order)->map(fn (Warehouse $wh) => [
            'id' => $wh->id,
            'name' => $wh->name,
            'code' => $wh->code,
            'can_fulfil' => $open->every(fn ($item) => $item->product_variant_id
                && $this->inventoryEngine->getAvailability($order->organization_id, $wh->id, $item->product_variant_id)['available'] >= $item->quantity),
        ])->values()->all();
    }

    /**
     * Allocate an order's items across eligible warehouses, or only to the chosen warehouse.
     */
    public function allocateOrder(Order $order, ?int $warehouseId = null): Order
    {
        return DB::transaction(function () use ($order, $warehouseId) {
            $order->load(['items', 'store.warehouses']);

            $eligibleWarehouses = $this->eligibleWarehouses($order);

            if ($warehouseId !== null) {
                $eligibleWarehouses = $eligibleWarehouses->where('id', $warehouseId)->values();
                if ($eligibleWarehouses->isEmpty()) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => 'Choose an active warehouse linked to this order\'s store.',
                    ]);
                }
            }

            if ($eligibleWarehouses->isEmpty()) {
                $order->update(['order_status' => 'exception', 'notes' => 'No active warehouses available for allocation.']);

                return $order;
            }

            $allAllocated = true;
            $anyAllocated = false;
            $itemsToPickCount = 0;
            $primaryWarehouseId = null;

            foreach ($order->items as $item) {
                if ($item->status !== 'pending' && $item->status !== 'exception') {
                    if ($item->status === 'allocated') {
                        $anyAllocated = true;
                    }

                    continue;
                }

                if (! $item->product_variant_id) {
                    $item->update(['status' => 'exception']);
                    $allAllocated = false;

                    continue;
                }

                $allocated = false;

                // Try warehouses in priority order
                foreach ($eligibleWarehouses as $wh) {
                    $avail = $this->inventoryEngine->getAvailability($order->organization_id, $wh->id, $item->product_variant_id);

                    if ($avail['available'] >= $item->quantity) {
                        // Reserve stock
                        $this->inventoryEngine->reserveStock(
                            organizationId: $order->organization_id,
                            orderId: $order->id,
                            orderItemId: $item->id,
                            warehouseId: $wh->id,
                            productVariantId: $item->product_variant_id,
                            quantity: $item->quantity,
                            idempotencyKey: "order-alloc-{$order->id}-{$item->id}-{$wh->id}"
                        );

                        $item->update([
                            'allocated_warehouse_id' => $wh->id,
                            'status' => 'allocated',
                        ]);

                        $allocated = true;
                        $anyAllocated = true;
                        $itemsToPickCount += $item->quantity;
                        $primaryWarehouseId = $primaryWarehouseId ?: $wh->id;
                        break;
                    }
                }

                if (! $allocated) {
                    $item->update(['status' => 'exception']);
                    $allAllocated = false;
                }
            }

            // Update overall order status
            if ($allAllocated && $anyAllocated) {
                $order->order_status = 'allocated';
            } elseif ($anyAllocated) {
                $order->order_status = 'partially_allocated';
            } else {
                $order->order_status = 'exception';
            }

            $order->save();

            // Create Pick Task if any items were allocated
            if ($anyAllocated) {
                PickTask::firstOrCreate(
                    [
                        'organization_id' => $order->organization_id,
                        'order_id' => $order->id,
                    ],
                    [
                        'warehouse_id' => $primaryWarehouseId,
                        'status' => 'not_started',
                        'items_count' => $itemsToPickCount,
                        'priority' => 'normal',
                    ]
                );
            }

            return $order;
        });
    }
}
