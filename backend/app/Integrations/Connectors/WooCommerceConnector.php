<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Integrations\WooCommerceClient;
use App\Models\Store;

class WooCommerceConnector extends HttpStoreConnector
{
    public function __construct(
        protected WooCommerceClient $client
    ) {}

    public function testConnection(Store $store): ConnectionResult
    {
        return $this->attempt(
            'WooCommerce',
            fn () => $this->client->get($store, 'products', ['per_page' => 1]),
            fn () => 'Connected to WooCommerce at '.$store->external_reference.'.',
        );
    }
}
