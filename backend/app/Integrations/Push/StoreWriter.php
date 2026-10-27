<?php

namespace App\Integrations\Push;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Shipment;
use App\Models\Store;
use RuntimeException;

/**
 * Keeps a connected store's orders and stock in line with Finovo: orders created in Finovo are created on the
 * store, and every change Finovo makes (payment, warehouse progress, dispatch with tracking, delivery,
 * cancellation, returns, available stock) is shown on the store too.
 *
 * Every method throws RuntimeException with a message the merchant understands when the store refuses.
 */
interface StoreWriter
{
    /**
     * Create the order on the store with its customer, address, lines and payment; returns the store's ID for it.
     *
     * @throws RuntimeException
     */
    public function createOrder(Store $store, Order $order): string;

    /**
     * Send the order's customer details, address, note and payment to the store.
     *
     * @throws RuntimeException
     */
    public function updateOrder(Store $store, Order $order): void;

    /**
     * Show the warehouse progress of an order Finovo is preparing (allocated, picking, picked, packed: see
     * Order::PROCESSING_STATUSES) in the store's own terms.
     *
     * @throws RuntimeException
     */
    public function updateStatus(Store $store, Order $order): void;

    /**
     * @throws RuntimeException
     */
    public function cancelOrder(Store $store, Order $order): void;

    /**
     * Set the quantity the store shows as available for one variant.
     *
     * @throws RuntimeException
     */
    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void;

    /**
     * Mark the order shipped on the store with the shipment's courier and tracking; returns the store's
     * fulfillment or shipment ID when it gives one.
     *
     * @throws RuntimeException
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string;

    /**
     * Report that a shipped order reached the customer.
     *
     * @throws RuntimeException
     */
    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void;

    /**
     * Show a completed return (its items and their condition) on the store's order. Finovo does not move money:
     * a refund is made on the store itself.
     *
     * @throws RuntimeException
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void;
}
