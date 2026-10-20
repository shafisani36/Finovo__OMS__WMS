<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Models\Store;

class ShopifyConnector extends HttpStoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        $shop = $store->external_reference;
        $version = config('services.shopify.api_version');

        return $this->attempt(
            'Shopify',
            fn () => $this->http()
                ->withHeaders(['X-Shopify-Access-Token' => (string) $store->credential('access_token')])
                ->get("https://{$shop}/admin/api/{$version}/shop.json"),
            fn ($response) => 'Connected to Shopify store "'.($response->json('shop.name') ?? $shop).'".',
        );
    }
}
