<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PickTask;

/**
 * Cross-docking: stock that just arrived goes first to the orders already waiting for it. Those orders are
 * allocated at once (oldest first) and their pick tasks marked urgent, so the units move from the dock straight to
 * packing instead of to a shelf and back.
 */
class CrossDockService
{
    /** Orders still waiting for stock. */
    private const WAITING = ['imported', 'pending', 'exception', 'partially_allocated'];

    public function __construct(
        protected OrderAllocationEngine $allocation
    ) {}

    /**
     * @param  list<int>  $variantIds  products that just arrived in the warehouse
     * @return list<string> numbers of the orders that can now ship from the delivery
     */
    public function allocateWaiting(int $organizationId, int $warehouseId, array $variantIds): array
    {
        $orders = Order::where('organization_id', $organizationId)
            ->whereIn('order_status', self::WAITING)
            ->whereNull('hold_type')
            ->whereHas('items', fn ($q) => $q->whereIn('product_variant_id', $variantIds)->whereIn('status', ['pending', 'exception']))
            ->orderByRaw('COALESCE(placed_at, created_at)')
            ->get();

        $served = [];
        foreach ($orders as $order) {
            $order = $this->allocation->allocateOrder($order);
            $fromDelivery = $order->items->contains(fn ($item) => $item->status === 'allocated' && $item->allocated_warehouse_id === $warehouseId && in_array($item->product_variant_id, $variantIds, true));

            if ($fromDelivery) {
                PickTask::where('order_id', $order->id)->where('status', 'not_started')->update(['priority' => 'urgent']);
                $served[] = $order->order_number;
            }
        }

        return $served;
    }
}
