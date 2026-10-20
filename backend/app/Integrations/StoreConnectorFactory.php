<?php

namespace App\Integrations;

use App\Enums\StorePlatform;
use App\Integrations\Connectors\BigCommerceConnector;
use App\Integrations\Connectors\CustomBridgeConnector;
use App\Integrations\Connectors\EbayConnector;
use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Connectors\ManualConnector;
use App\Integrations\Connectors\OpenCartConnector;
use App\Integrations\Connectors\PrestaShopConnector;
use App\Integrations\Connectors\ShopifyConnector;
use App\Integrations\Connectors\WixConnector;
use App\Integrations\Connectors\WooCommerceConnector;
use App\Integrations\Contracts\StoreConnector;

class StoreConnectorFactory
{
    public function for(StorePlatform $platform): StoreConnector
    {
        return app(match ($platform) {
            StorePlatform::SHOPIFY => ShopifyConnector::class,
            StorePlatform::WOOCOMMERCE => WooCommerceConnector::class,
            StorePlatform::BIGCOMMERCE => BigCommerceConnector::class,
            StorePlatform::PRESTASHOP => PrestaShopConnector::class,
            StorePlatform::WIX => WixConnector::class,
            StorePlatform::EBAY => EbayConnector::class,
            StorePlatform::MAGENTO => MagentoConnector::class,
            StorePlatform::OPENCART => OpenCartConnector::class,
            StorePlatform::OSCOMMERCE => ExternalDatabaseConnector::class,
            StorePlatform::CUSTOM_BRIDGE => CustomBridgeConnector::class,
            StorePlatform::MANUAL => ManualConnector::class,
        });
    }
}
