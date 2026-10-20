<?php

namespace App\Integrations\Authorization;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopify OAuth (authorization code grant) for the Finovo public / custom app.
 */
class ShopifyAuthorization
{
    private const SCOPES = [
        'read_products', 'write_products',
        'read_orders', 'write_orders',
        'read_inventory', 'write_inventory',
        'read_customers', 'write_customers',
        'read_merchant_managed_fulfillment_orders', 'write_merchant_managed_fulfillment_orders',
        'read_assigned_fulfillment_orders', 'write_assigned_fulfillment_orders',
    ];

    /**
     * "my-shop", "my-shop.myshopify.com" or "https://my-shop.myshopify.com/" → "my-shop.myshopify.com".
     */
    public static function normalizeShopDomain(string $input): ?string
    {
        $domain = strtolower(trim(preg_replace('#^https?://#i', '', trim($input)), '/'));

        if (! str_ends_with($domain, '.myshopify.com')) {
            $domain .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $domain) ? $domain : null;
    }

    public function authorizationUrl(Store $store, string $state): string
    {
        return "https://{$store->external_reference}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => implode(',', self::SCOPES),
            'redirect_uri' => route('integrations.shopify.callback'),
            'state' => $state,
        ]);
    }

    /**
     * Shopify signs every callback with the app secret: HMAC-SHA256 over the decoded
     * query parameters (without "hmac"), sorted by key and joined as key=value&key=value.
     *
     * @param  array<string, mixed>  $query
     */
    public function hasValidSignature(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = collect($query)
            ->map(fn ($value, $key) => $key.'='.(is_array($value) ? '["'.implode('", "', $value).'"]' : $value))
            ->implode('&');

        $expected = hash_hmac('sha256', $message, (string) config('services.shopify.client_secret'));

        return $hmac !== '' && hash_equals($expected, $hmac);
    }

    public function exchangeCode(string $shop, string $code): string
    {
        $response = Http::acceptJson()->timeout(15)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            throw new RuntimeException("Shopify did not issue an access token (HTTP {$response->status()}).");
        }

        return $token;
    }
}
