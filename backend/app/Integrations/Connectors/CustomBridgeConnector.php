<?php

namespace App\Integrations\Connectors;

use App\Integrations\BridgeSignature;
use App\Integrations\ConnectionResult;
use App\Models\Store;

/**
 * Talks to the Finovo bridge files installed on the client's own website, with signed requests.
 */
class CustomBridgeConnector extends HttpStoreConnector
{
    public function testConnection(Store $store): ConnectionResult
    {
        $url = rtrim((string) $store->bridge_url, '/').'/health.php';

        return $this->attempt(
            'Custom Bridge',
            fn () => $this->http()->withHeaders(BridgeSignature::headers((string) $store->api_key, (string) $store->api_secret))->get($url),
            fn () => 'Connected to the bridge at '.$store->bridge_url.'.',
            'Check that the API key and secret on the website match the ones Finovo generated.',
        );
    }
}
