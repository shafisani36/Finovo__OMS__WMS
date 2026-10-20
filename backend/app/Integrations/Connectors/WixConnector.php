<?php

namespace App\Integrations\Connectors;

use App\Integrations\Authorization\WixAuthorization;
use App\Integrations\ConnectionResult;
use App\Models\Store;
use RuntimeException;

class WixConnector extends HttpStoreConnector
{
    public function __construct(
        protected WixAuthorization $authorization
    ) {}

    public function testConnection(Store $store): ConnectionResult
    {
        try {
            $headers = $this->authorization->headersFor($store);
        } catch (RuntimeException $e) {
            return ConnectionResult::failure($e->getMessage());
        }

        return $this->attempt(
            'Wix',
            fn () => $this->http()
                ->withHeaders($headers)
                ->post('https://www.wixapis.com/stores/v3/products/search', [
                    'search' => ['cursorPaging' => ['limit' => 1]],
                ]),
            fn () => 'Connected to Wix site '.$store->external_reference.'.',
        );
    }
}
