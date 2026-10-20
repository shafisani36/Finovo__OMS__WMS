<?php

namespace App\Integrations\Authorization;

use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wix app install (external install flow). The merchant installs the Finovo app from a link Finovo builds;
 * Wix sends the browser back with a signed app instance, and Finovo creates access tokens for that instance
 * with the app ID and secret (OAuth client credentials).
 */
class WixAuthorization
{
    private const API = 'https://www.wixapis.com';

    /**
     * One-click install needs the Finovo app's ID and secret from the Wix app dashboard.
     */
    public function supportsOneClickInstall(): bool
    {
        return filled(config('services.wix.app_id')) && filled(config('services.wix.app_secret'));
    }

    /**
     * Wix page where the merchant picks a site and approves the Finovo app.
     * Unlisted (not yet published) apps also need the share link ID.
     */
    public function installUrl(string $state): string
    {
        $query = array_filter([
            'appId' => config('services.wix.app_id'),
            'shareUrlId' => config('services.wix.share_url_id'),
            'postInstallationUrl' => route('integrations.wix.callback', ['state' => $state]),
        ]);

        return 'https://www.wix.com/app-installer?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The signed instance is "signature.payload", both base64url; the signature is HMAC-SHA256 of the payload
     * with the app secret. Returns the instance ID when the signature is valid, otherwise null.
     */
    public function instanceIdFromSignedInstance(string $signedInstance): ?string
    {
        $parts = explode('.', $signedInstance, 2);
        $secret = (string) config('services.wix.app_secret');

        if ($secret === '' || count($parts) !== 2) {
            return null;
        }

        [$signature, $payload] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));

        if (! hash_equals($expected, rtrim(strtr($signature, '+/', '-_'), '='))) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && is_string($data['instanceId'] ?? null) ? $data['instanceId'] : null;
    }

    /**
     * Access token for one installed instance. Wix tokens last 4 hours, so they are cached (encrypted) and reused.
     *
     * @throws RuntimeException when Wix refuses (e.g. the app was uninstalled from the site)
     */
    public function accessToken(string $instanceId): string
    {
        $cacheKey = "wix:access-token:{$instanceId}";

        if ($cached = Cache::get($cacheKey)) {
            return Crypt::decryptString($cached);
        }

        $response = $this->send(fn () => Http::acceptJson()->timeout(15)->post(self::API.'/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.wix.app_id'),
            'client_secret' => config('services.wix.app_secret'),
            'instance_id' => $instanceId,
        ]));

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException("Wix did not issue an access token (HTTP {$response->status()}). Check the Finovo app is still installed on the site.");
        }

        Cache::put($cacheKey, Crypt::encryptString($token), now()->addSeconds(max(60, (int) $response->json('expires_in', 14400) - 300)));

        return $token;
    }

    /**
     * Request headers for a store: the app instance token for stores installed through the Finovo Wix app,
     * otherwise a Wix account API key (the store's own, or the server's WIX_API_KEY) with the site ID.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    public function headersFor(Store $store): array
    {
        if ($instanceId = $store->credential('instance_id')) {
            return ['Authorization' => $this->accessToken($instanceId)];
        }

        if (blank($store->external_reference)) {
            throw new RuntimeException('Not connected yet. Click Connect and approve the Finovo app on Wix.');
        }

        $apiKey = $store->credential('api_key') ?: config('services.wix.api_key');

        if (! $apiKey) {
            throw new RuntimeException('No Wix API key. Enter one for this store or set WIX_API_KEY in .env.');
        }

        return ['Authorization' => $apiKey, 'wix-site-id' => (string) $store->external_reference];
    }

    /**
     * The Wix site the instance belongs to, read from Wix rather than trusted from the redirect URL.
     *
     * @throws RuntimeException
     */
    public function siteId(string $instanceId): string
    {
        $token = $this->accessToken($instanceId);
        $response = $this->send(fn () => Http::acceptJson()->timeout(15)->withHeaders(['Authorization' => $token])->get(self::API.'/apps/v1/instance'));
        $siteId = $response->json('site.siteId');

        if (! $response->successful() || ! is_string($siteId)) {
            throw new RuntimeException("Could not read the Wix site for this installation (HTTP {$response->status()}).");
        }

        return $siteId;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Wix. Try again in a minute.');
        }
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
