<?php

namespace App\Integrations\Authorization;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eBay OAuth (authorization code grant) with automatic access-token refresh.
 */
class EbayAuthorization
{
    private const SCOPES = [
        'https://api.ebay.com/oauth/api_scope',
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
        'https://api.ebay.com/oauth/api_scope/commerce.notification.subscription',
    ];

    /** Refresh this many seconds before the access token actually expires. */
    private const EXPIRY_MARGIN_SECONDS = 300;

    public function environment(): string
    {
        return config('services.ebay.environment') === 'production' ? 'production' : 'sandbox';
    }

    public function apiBase(): string
    {
        return $this->environment() === 'production' ? 'https://api.ebay.com' : 'https://api.sandbox.ebay.com';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.ebay.app_id')) && filled(config('services.ebay.cert_id')) && filled(config('services.ebay.ru_name'));
    }

    public function authorizationUrl(string $state): string
    {
        $authBase = $this->environment() === 'production' ? 'https://auth.ebay.com' : 'https://auth.sandbox.ebay.com';

        return $authBase.'/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.ebay.app_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.ebay.ru_name'),
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, access_token_expires_at: int, refresh_token: string}
     */
    public function exchangeCode(string $code): array
    {
        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.ebay.ru_name'),
        ]);

        if (empty($tokens['refresh_token'])) {
            throw new RuntimeException('eBay did not return a refresh token.');
        }

        return [
            'access_token' => $tokens['access_token'],
            'access_token_expires_at' => time() + (int) ($tokens['expires_in'] ?? 7200),
            'refresh_token' => $tokens['refresh_token'],
        ];
    }

    /**
     * A valid access token for the store, refreshing and saving a new one when it is about to expire.
     */
    public function accessToken(Store $store): string
    {
        $refreshToken = $store->credential('refresh_token');

        if (! $refreshToken) {
            throw new RuntimeException('This eBay store is not authorized yet. Click "Connect" to sign in to eBay.');
        }

        if ($store->credential('access_token') && (int) $store->credential('access_token_expires_at') > time() + self::EXPIRY_MARGIN_SECONDS) {
            return $store->credential('access_token');
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', self::SCOPES),
        ]);

        $store->update([
            'credentials' => array_merge($store->credentials ?? [], [
                'access_token' => $tokens['access_token'],
                'access_token_expires_at' => time() + (int) ($tokens['expires_in'] ?? 7200),
            ]),
        ]);

        return $tokens['access_token'];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function requestToken(array $fields): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('eBay is not configured. Set EBAY_APP_ID, EBAY_CERT_ID and EBAY_RUNAME in .env.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->withBasicAuth((string) config('services.ebay.app_id'), (string) config('services.ebay.cert_id'))
            ->post($this->apiBase().'/identity/v1/oauth2/token', $fields);

        if (! $response->successful() || ! $response->json('access_token')) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}";

            throw new RuntimeException("eBay token request failed: {$reason}.");
        }

        return $response->json();
    }
}
