<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\CustomerService;
use App\Services\OrderControlService;
use App\Services\StoreSyncOutbox;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps each order linked to its customer, whether the order was imported from a store or created by hand,
 * shows the warehouse progress of a store's order on that store, writes the order timeline and holds orders
 * that need approval or exceed the customer's credit limit.
 */
class OrderObserver
{
    public function __construct(
        protected CustomerService $customers,
        protected StoreSyncOutbox $outbox,
        protected OrderControlService $controls,
    ) {}

    public function created(Order $order): void
    {
        $order->record('created', $order->external_order_id ? 'Imported from the store' : 'Created in Finovo', Auth::id());
    }

    public function saved(Order $order): void
    {
        if ($order->wasRecentlyCreated || $order->wasChanged(['customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'placed_at'])) {
            $this->customers->linkOrder($order);
        }

        if ($order->wasChanged('order_status') && in_array($order->order_status, Order::PROCESSING_STATUSES, true)) {
            $this->outbox->orderStatusChanged($order);
        }

        $previous = $order->getOriginal('order_status');
        if ($order->wasChanged('order_status') && $previous !== null && $previous !== $order->order_status) {
            $order->record('status', "Status changed from {$previous} to {$order->order_status}", Auth::id());
        }

        if ($order->wasRecentlyCreated || $order->wasChanged('total_amount')) {
            $this->controls->screen($order);
        }
    }
}
