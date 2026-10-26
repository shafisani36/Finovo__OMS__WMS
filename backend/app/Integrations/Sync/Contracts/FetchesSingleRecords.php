<?php

namespace App\Integrations\Sync\Contracts;

use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\ProductData;
use App\Models\Store;
use RuntimeException;

/**
 * A platform that can read one product or one order by its ID, used when the store notifies Finovo
 * that a single record was created or changed (webhooks).
 */
interface FetchesSingleRecords
{
    /**
     * The product, or null when it no longer exists on the store.
     *
     * @throws RuntimeException when the platform cannot be read
     */
    public function product(Store $store, string $externalId): ?ProductData;

    /**
     * The order, or null when it no longer exists on the store.
     *
     * @throws RuntimeException when the platform cannot be read
     */
    public function order(Store $store, string $externalId): ?OrderData;
}
