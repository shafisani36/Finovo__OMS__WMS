<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PickTask;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class OrderAllocationEngine
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * Allocate an order's items across eligible warehouses.
     */
    public function allocateOrder(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order->load(['items', 'store.warehouses']);

            // Get eligible warehouses for the store or all active warehouses
            $eligibleWarehouses = $order->store && $order->store->warehouses->isNotEmpty()
                ? $order->store->warehouses()->where('is_active', true)->orderBy('priority', 'asc')->get()
                : Warehouse::where('organization_id', $order->organization_id)->where('is_active', true)->orderBy('priority', 'asc')->get();

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
