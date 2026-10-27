<?php

namespace App\Integrations\Push;

use App\Models\Product;
use App\Models\Store;
use RuntimeException;

/**
 * Stores whose catalog Finovo edits: products created or changed in Finovo are created or changed there too.
 */
interface ProductWriter
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
}
