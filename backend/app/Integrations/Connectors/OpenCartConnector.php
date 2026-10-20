<?php

namespace App\Integrations\Connectors;

use App\Integrations\BridgeSignature;
use App\Integrations\ConnectionResult;
use App\Models\Store;

/**
 * Stores with the Finovo OpenCart module are reached over HTTPS with signed requests.
 * Stores added with database details are read directly from the shop database.
 */
class OpenCartConnector extends HttpStoreConnector
{
    public function __construct(
        protected ExternalDatabaseConnector $database
    ) {}

    public function testConnection(Store $store): ConnectionResult
    {
        if (blank($store->credential('api_secret'))) {
            return filled($store->credential('db_name'))
                ? $this->database->testConnection($store)
                : ConnectionResult::failure('Not connected yet. Click Connect, download the Finovo module and upload it in OpenCart → Extensions → Installer.');
        }

        return $this->attempt(
            'OpenCart',
            fn () => $this->http()
                ->withHeaders(BridgeSignature::headers((string) $store->credential('api_key'), (string) $store->credential('api_secret')))
                ->get(rtrim((string) $store->external_reference, '/').'/index.php', ['route' => 'extension/finovo/api/health']),
            fn ($response) => 'Connected to OpenCart at '.$store->external_reference.' ('.(int) $response->json('orders').' orders found).',
            'Open Extensions → Modules → Finovo in OpenCart and click Connect again.',
        );
    }
}
