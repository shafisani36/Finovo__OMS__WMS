<?php

namespace App\Integrations\Connectors;

use App\Integrations\Authorization\EbayAuthorization;
use App\Integrations\ConnectionResult;
use App\Models\Store;
use RuntimeException;

class EbayConnector extends HttpStoreConnector
{
    public function __construct(
        protected EbayAuthorization $authorization
    ) {}

    public function testConnection(Store $store): ConnectionResult
    {
        try {
            $token = $this->authorization->accessToken($store);
        } catch (RuntimeException $e) {
            return ConnectionResult::failure($e->getMessage());
        }

        return $this->attempt(
            'eBay',
            fn () => $this->http()->withToken($token)->get($this->authorization->apiBase().'/sell/inventory/v1/inventory_item', ['limit' => 1]),
            fn () => 'Connected to eBay ('.$this->authorization->environment().').',
        );
    }
}
