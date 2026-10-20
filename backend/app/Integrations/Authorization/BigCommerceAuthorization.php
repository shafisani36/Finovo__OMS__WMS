<?php

namespace App\Integrations\Authorization;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * BigCommerce app OAuth. The merchant installs the Finovo app either with one click from Finovo
 * (external install) or from the BigCommerce control panel; BigCommerce then calls the auth
 * callback with the store hash in "context".
 */
class BigCommerceAuthorization
{
    /** Cookie that remembers which Finovo store started a one-click install (set on Finovo's own domain). */
    public const INSTALL_COOKIE = 'finovo_bc_install';

    /**
     * One-click install needs the app's numeric ID from the BigCommerce Developer Portal.
     */
    public function supportsOneClickInstall(): bool
    {
        return filled(config('services.bigcommerce.app_id'));
    }

    /**
     * BigCommerce page where the merchant logs in and approves the Finovo app.
     */
    public function installUrl(): string
    {
        return 'https://login.bigcommerce.com/app/'.config('services.bigcommerce.app_id').'/install';
    }

    /**
     * For installs started outside the control panel, BigCommerce waits for Finovo to report the outcome.
     */
    public function reportExternalInstall(bool $succeeded): void
    {
        Http::timeout(10)->get('https://login.bigcommerce.com/app/'.config('services.bigcommerce.app_id').'/install/'.($succeeded ? 'succeeded' : 'failed'));
    }

    /**
     * @return array{store_hash: string, access_token: string}
     */
    public function exchangeCode(string $code, string $scope, string $context): array
    {
        $response = Http::acceptJson()->timeout(15)->post('https://login.bigcommerce.com/oauth2/token', [
            'client_id' => config('services.bigcommerce.client_id'),
            'client_secret' => config('services.bigcommerce.client_secret'),
            'redirect_uri' => route('integrations.bigcommerce.callback'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => $scope,
            'context' => $context,
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! $token) {
            throw new RuntimeException("BigCommerce did not issue an access token (HTTP {$response->status()}).");
        }

        return [
            'store_hash' => $this->storeHashFromContext((string) ($response->json('context') ?? $context)),
            'access_token' => $token,
        ];
    }

    /**
     * Load and uninstall callbacks carry a JWT signed (HS256) with the app's client secret.
     * Returns the store hash when the signature is valid, otherwise null.
     */
    public function storeHashFromSignedPayload(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        $secret = (string) config('services.bigcommerce.client_secret');

        if ($secret === '' || count($parts) !== 3) {
            return null;
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true)), '+/', '-_'), '=');

        if (! hash_equals($expected, $parts[2])) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (! is_array($payload) || (isset($payload['exp']) && (int) $payload['exp'] < time())) {
            return null;
        }

        return $this->storeHashFromContext((string) ($payload['sub'] ?? '')) ?: null;
    }

    private function storeHashFromContext(string $context): string
    {
        return str_replace('stores/', '', $context);
    }
}
