<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Models\Store;

class PrestaShopConnector extends HttpStoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        if (blank($store->external_reference) || blank($store->credential('webservice_key'))) {
            return ConnectionResult::failure('Not connected yet. Click Connect, download the Finovo module and upload it in PrestaShop → Modules → Module Manager.');
        }

        $url = rtrim((string) $store->external_reference, '/').'/api/products';

        return $this->attempt(
            'PrestaShop',
            fn () => $this->http()->get($url, [
                'ws_key' => (string) $store->credential('webservice_key'),
                'output_format' => 'JSON',
                'limit' => 1,
            ]),
            fn () => 'Connected to PrestaShop at '.$store->external_reference.'.',
        );
    }
}
