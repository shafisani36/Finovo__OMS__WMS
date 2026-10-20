<?php

namespace App\Integrations;

/**
 * Signs requests to code Finovo installs on a merchant's website (Custom Bridge files, the OpenCart module).
 * Signature: HMAC-SHA256(timestamp + nonce + body) with the shared API secret.
 */
class BridgeSignature
{
    /**
     * @return array<string, string>
     */
    public static function headers(string $apiKey, string $apiSecret, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Bridge-Api-Key' => $apiKey,
            'X-Bridge-Timestamp' => $timestamp,
            'X-Bridge-Nonce' => $nonce,
            'X-Bridge-Signature' => hash_hmac('sha256', $timestamp.$nonce.$body, $apiSecret),
        ];
    }
}
