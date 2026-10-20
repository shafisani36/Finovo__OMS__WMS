<?php

namespace App\Integrations\Sync\Contracts;

use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\ProductData;
use App\Models\Store;
use DateTimeInterface;
use RuntimeException;

/**
 * Reads catalog and orders from one store platform. Results are streamed page by page.
 */
interface StoreDataSource
{
    /**
     * @return iterable<ProductData>
     *
     * @throws RuntimeException when the platform cannot be read
     */
    public function products(Store $store): iterable;

    /**
     * Orders created or changed since the given time (all available orders when null).
     *
     * @return iterable<OrderData>
     *
     * @throws RuntimeException when the platform cannot be read
     */
    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable;

    /**
     * Things the merchant should know about the last read, e.g. data the platform withheld.
     *
     * @return list<string>
     */
    public function warnings(): array;
}
