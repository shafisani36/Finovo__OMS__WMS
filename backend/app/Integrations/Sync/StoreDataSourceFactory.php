<?php

namespace App\Integrations\Sync;

use App\Enums\StorePlatform;
use App\Integrations\Sync\Contracts\StoreDataSource;
use App\Integrations\Sync\Sources\BigCommerceDataSource;
use App\Integrations\Sync\Sources\EbayDataSource;
use App\Integrations\Sync\Sources\MagentoDataSource;
use App\Integrations\Sync\Sources\OpenCartDataSource;
use App\Integrations\Sync\Sources\OsCommerceDataSource;
use App\Integrations\Sync\Sources\PrestaShopDataSource;
use App\Integrations\Sync\Sources\ShopifyDataSource;
use App\Integrations\Sync\Sources\WixDataSource;
use App\Integrations\Sync\Sources\WooCommerceDataSource;
use InvalidArgumentException;

/**
 * Picks the reader for a store platform. Manual stores and Custom Bridge sites have nothing to import.
 */
class StoreDataSourceFactory
{
    /** @var array<string, class-string<StoreDataSource>> */
    private const SOURCES = [
        'shopify' => ShopifyDataSource::class,
        'woocommerce' => WooCommerceDataSource::class,
        'bigcommerce' => BigCommerceDataSource::class,
        'prestashop' => PrestaShopDataSource::class,
        'wix' => WixDataSource::class,
        'ebay' => EbayDataSource::class,
        'magento' => MagentoDataSource::class,
        'opencart' => OpenCartDataSource::class,
        'oscommerce' => OsCommerceDataSource::class,
    ];

    public function supports(StorePlatform $platform): bool
    {
        return isset(self::SOURCES[$platform->value]);
    }

    public function for(StorePlatform $platform): StoreDataSource
    {
        $class = self::SOURCES[$platform->value]
            ?? throw new InvalidArgumentException("Syncing {$platform->label()} stores is not available yet.");

        // A new instance per sync, so warnings from one store never leak into another.
        return app()->make($class);
    }
}
