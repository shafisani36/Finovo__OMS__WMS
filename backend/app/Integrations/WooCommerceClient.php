<?php

namespace App\Integrations;

use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated calls to the WooCommerce REST API (wp-json/wc/v3) of one store.
 */
class WooCommerceClient
{
    /** WordPress can be slow to build large product pages, especially on shared or local hosting. */
    private const TIMEOUT_SECONDS = 120;

    /**
     * @param  array<string, scalar>  $query
     */
    public function get(Store $store, string $path, array $query = []): Response
    {
        $url = rtrim((string) $store->external_reference, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
        $key = (string) $store->credential('consumer_key');
        $secret = (string) $store->credential('consumer_secret');
        $request = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS);

        return str_starts_with($url, 'https://')
            ? $request->withBasicAuth($key, $secret)->get($url, $query)
            : $request->get($url, $this->signedQuery('GET', $url, $query, $key, $secret));
    }

    /**
     * WooCommerce only accepts Basic Auth over HTTPS. Plain HTTP sites need a
     * one-legged OAuth 1.0a signature (same algorithm as WooCommerce's check_oauth_signature()).
     *
     * @param  array<string, scalar>  $params
     * @return array<string, scalar>
     */
    private function signedQuery(string $method, string $url, array $params, string $key, string $secret): array
    {
        $params += [
            'oauth_consumer_key' => $key,
            'oauth_timestamp' => (string) time(),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA256',
        ];

        uksort($params, 'strcmp');

        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $this->encode($this->encode((string) $name).'='.$this->encode((string) $value));
        }

        $base = strtoupper($method).'&'.rawurlencode($url).'&'.implode('%26', $pairs);
        $params['oauth_signature'] = base64_encode(hash_hmac('sha256', $base, $secret.'&', true));

        return $params;
    }

    private function encode(string $value): string
    {
        return str_replace(['+', '%7E'], [' ', '~'], rawurlencode($value));
    }
}
