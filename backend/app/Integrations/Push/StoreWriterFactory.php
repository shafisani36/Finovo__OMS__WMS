<?php

namespace App\Integrations\Push;

use App\Enums\StorePlatform;
use App\Models\Store;

/**
 * Picks the writer for a store platform. Platforms without one keep products and orders in Finovo only.
 */
class StoreWriterFactory
{
    /** @var array<string, class-string<StoreWriter>> */
    private const WRITERS = [
        'shopify' => ShopifyWriter::class,
        'woocommerce' => WooCommerceWriter::class,
    ];

    public function supports(StorePlatform $platform): bool
    {
        return isset(self::WRITERS[$platform->value]);
    }

    /**
     * The writer for a connected store, or null when Finovo cannot write to it.
     */
    public function for(Store $store): ?StoreWriter
    {
        return $store->isConnected() && $this->supports($store->platform) ? app(self::WRITERS[$store->platform->value]) : null;
    }
}
