<?php

namespace App\Integrations;

use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated calls to the WooCommerce REST API (wp-json/wc/v3) of one store, for reading and writing.
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
        return $this->request($store, 'GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(Store $store, string $path, array $body): Response
    {
        return $this->request($store, 'POST', $path, [], $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function put(Store $store, string $path, array $body): Response
    {
        return $this->request($store, 'PUT', $path, [], $body);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    public function delete(Store $store, string $path, array $query = []): Response
    {
        return $this->request($store, 'DELETE', $path, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body  sent as JSON
     */
    private function request(Store $store, string $method, string $path, array $query = [], ?array $body = null): Response
    {
        $url = rtrim((string) $store->external_reference, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
        $key = (string) $store->credential('consumer_key');
        $secret = (string) $store->credential('consumer_secret');
        $request = Http::acceptJson()->timeout(self::TIMEOUT_SECONDS);

        if (str_starts_with($url, 'https://')) {
            $request = $request->withBasicAuth($key, $secret);
        } else {
            $query = $this->signedQuery($method, $url, $query, $key, $secret);
        }

        $target = $query ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;

        return $body === null ? $request->send($method, $target) : $request->asJson()->send($method, $target, ['json' => $body]);
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
