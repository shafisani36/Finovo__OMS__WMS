<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Models\Store;

class MagentoConnector extends HttpStoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        // Reading one catalog product proves both the token and the catalog permission Finovo needs.
        $url = rtrim((string) $store->external_reference, '/').'/rest/V1/products';

        return $this->attempt(
            'Magento',
            fn () => $this->http()->withToken((string) $store->credential('access_token'))->get($url, [
                'searchCriteria' => ['pageSize' => 1, 'currentPage' => 1],
            ]),
            fn () => 'Connected to Magento at '.$store->external_reference.'.',
            'Check the access token, and in Magento enable "Allow OAuth Access Tokens to be used as standalone Bearer tokens".',
        );
    }
}
