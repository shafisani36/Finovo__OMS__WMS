<?php

namespace App\Integrations\Push;

use App\Models\Store;
use RuntimeException;

/**
 * Stores that notify Finovo the moment an order or product changes (webhooks), so it is imported at once.
 */
interface WebhookSubscriber
{
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
