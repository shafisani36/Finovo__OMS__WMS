<?php

namespace App\Integrations\Push;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use RuntimeException;

/**
 * Writes Finovo's changes to a connected store, so products and orders created or edited in Finovo
 * also change on the store, and registers the notifications (webhooks) that bring the store's own
 * changes into Finovo automatically.
 *
 * Every method throws RuntimeException with a message the merchant understands when the store refuses.
 */
interface StoreWriter
{
    /**
     * Create the product on the store (no external ID yet) or update the one it is linked to.
     *
     * @throws RuntimeException
     */
    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct;

    /**
     * @throws RuntimeException
     */
    public function deleteProduct(Store $store, string $externalId): void;

    /**
     * Create the order on the store; returns the store's ID for it.
     *
     * @throws RuntimeException
     */
    public function createOrder(Store $store, Order $order): string;

    /**
     * Send the order's customer, address and note to the store.
     *
     * @throws RuntimeException
     */
    public function updateOrder(Store $store, Order $order): void;

    /**
     * @throws RuntimeException
     */
    public function cancelOrder(Store $store, Order $order): void;

    /**
     * Subscribe Finovo's address to the store's order and product notifications (missing ones only).
     *
     * @throws RuntimeException
     */
    public function registerWebhooks(Store $store, string $callbackUrl): void;

    /**
     * Remove Finovo's notification subscriptions from the store (when the store is removed from Finovo).
     *
     * @throws RuntimeException
     */
    public function removeWebhooks(Store $store, string $callbackUrl): void;
}
