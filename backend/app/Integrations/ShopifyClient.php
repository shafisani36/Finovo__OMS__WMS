<?php

namespace App\Integrations;

use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated calls to the Shopify GraphQL Admin API of one shop, used for reading and for writing.
 */
class ShopifyClient
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function graphql(Store $store, string $query, array $variables = []): Response
    {
        return Http::acceptJson()
            ->timeout(30)
            ->withHeaders(['X-Shopify-Access-Token' => (string) $store->credential('access_token')])
            ->post($this->endpoint($store), ['query' => $query, 'variables' => $variables ?: new \stdClass]);
    }

    public function endpoint(Store $store): string
    {
        return 'https://'.$store->external_reference.'/admin/api/'.config('services.shopify.api_version').'/graphql.json';
    }

    /**
     * Shopify signals throttling as HTTP 429 or as a THROTTLED GraphQL error.
     */
    public static function isThrottled(Response $response): bool
    {
        $errors = $response->json('errors');

        return $response->status() === 429 || (is_array($errors) && ($errors[0]['extensions']['code'] ?? null) === 'THROTTLED');
    }

    /**
     * "gid://shopify/Product/123" for a numeric ID (an ID that is already global is returned as it is).
     */
    public static function gid(string $type, string|int $id): string
    {
        return str_starts_with((string) $id, 'gid://') ? (string) $id : "gid://shopify/{$type}/{$id}";
    }

    /**
     * "123" from "gid://shopify/Product/123".
     */
    public static function legacyId(?string $gid): ?string
    {
        return $gid === null ? null : (string) last(explode('/', $gid));
    }
}
