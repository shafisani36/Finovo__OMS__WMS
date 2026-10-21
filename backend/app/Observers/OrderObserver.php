<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\CustomerService;

/**
 * Keeps each order linked to its customer, whether the order was imported from a store or created by hand.
 */
class OrderObserver
{
    public function __construct(
        protected CustomerService $customers
    ) {}

    public function saved(Order $order): void
    {
        if ($order->wasRecentlyCreated || $order->wasChanged(['customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'placed_at'])) {
            $this->customers->linkOrder($order);
        }
    }
}
