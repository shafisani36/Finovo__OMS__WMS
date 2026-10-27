<?php

namespace App\Integrations\Push;

use App\Enums\StorePlatform;
use App\Models\Store;

/**
 * Picks the writer for a store platform. Every connected platform gets Finovo's orders, statuses and stock;
 * some also take Finovo's product changes (ProductWriter) or notify Finovo of new orders at once (WebhookSubscriber).
 */
class StoreWriterFactory
{
    /** @var array<string, class-string<StoreWriter>> */
    private const WRITERS = [
        'shopify' => ShopifyWriter::class,
        'woocommerce' => WooCommerceWriter::class,
        'bigcommerce' => BigCommerceWriter::class,
        'prestashop' => PrestaShopWriter::class,
        'wix' => WixWriter::class,
        'ebay' => EbayWriter::class,
        'magento' => MagentoWriter::class,
        'opencart' => OpenCartWriter::class,
        'oscommerce' => OsCommerceWriter::class,
    ];

    public function supports(StorePlatform $platform): bool
    {
        return isset(self::WRITERS[$platform->value]);
    }

    /**
     * Whether stores on this platform can notify Finovo of new and changed orders (webhooks).
     */
    public function supportsWebhooks(StorePlatform $platform): bool
    {
        return $this->supports($platform) && is_subclass_of(self::WRITERS[$platform->value], WebhookSubscriber::class);
    }

    /**
     * The writer for a connected store, or null when Finovo cannot write to it.
     */
    public function for(Store $store): ?StoreWriter
    {
        return $store->isConnected() && $this->supports($store->platform) ? app(self::WRITERS[$store->platform->value]) : null;
    }

    /**
     * The writer for a connected store whose catalog Finovo edits.
     */
    public function products(Store $store): ?ProductWriter
    {
        $writer = $this->for($store);

        return $writer instanceof ProductWriter ? $writer : null;
    }

    /**
     * The writer for a connected store that can notify Finovo of changes (webhooks).
     */
    public function webhooks(Store $store): ?WebhookSubscriber
    {
        $writer = $this->for($store);

        return $writer instanceof WebhookSubscriber ? $writer : null;
    }
}
