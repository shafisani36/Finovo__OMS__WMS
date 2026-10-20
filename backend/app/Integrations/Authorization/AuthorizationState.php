<?php

namespace App\Integrations\Authorization;

use App\Enums\StorePlatform;
use App\Models\Store;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Tamper-proof token that ties a platform's authorization callback back to the Finovo store that started it.
 * Short-lived by default; a longer lifetime is used where the merchant needs time (e.g. installing a module).
 */
class AuthorizationState
{
    public const DEFAULT_TTL_SECONDS = 1800;

    public function issue(Store $store, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        return Crypt::encryptString(json_encode([
            'store_id' => $store->id,
            'platform' => $store->platform->value,
            'expires_at' => time() + $ttlSeconds,
        ]));
    }

    /**
     * @throws RuntimeException when the token is forged, expired or for another platform
     */
    public function resolve(?string $token, StorePlatform $platform): Store
    {
        try {
            $payload = json_decode(Crypt::decryptString((string) $token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new RuntimeException('This connection link is invalid. Start the connection again from Finovo.');
        }

        if (($payload['platform'] ?? null) !== $platform->value || ($payload['expires_at'] ?? 0) < time()) {
            throw new RuntimeException('This connection link has expired. Start the connection again from Finovo.');
        }

        $store = Store::find($payload['store_id'] ?? 0);

        if (! $store || $store->platform !== $platform) {
            throw new RuntimeException('The store for this connection no longer exists.');
        }

        return $store;
    }
}
