<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\CustomerService;
use App\Services\StoreSyncOutbox;

/**
 * Keeps each order linked to its customer, whether the order was imported from a store or created by hand,
 * and shows the warehouse progress of a store's order on that store.
 */
class OrderObserver
{
    public function __construct(
        protected CustomerService $customers,
        protected StoreSyncOutbox $outbox,
    ) {}

    public function saved(Order $order): void
    {
        if ($order->wasRecentlyCreated || $order->wasChanged(['customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'placed_at'])) {
            $this->customers->linkOrder($order);
        }

        if ($order->wasChanged('order_status') && in_array($order->order_status, Order::PROCESSING_STATUSES, true)) {
            $this->outbox->orderStatusChanged($order);
        }
    }
}
