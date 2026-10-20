<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Models\Store;

class BigCommerceConnector extends HttpStoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        $hash = $store->external_reference;

        return $this->attempt(
            'BigCommerce',
            fn () => $this->http()
                ->withHeaders(['X-Auth-Token' => (string) $store->credential('access_token')])
                ->get("https://api.bigcommerce.com/stores/{$hash}/v2/store"),
            fn ($response) => 'Connected to BigCommerce store "'.($response->json('name') ?? $hash).'".',
        );
    }
}
