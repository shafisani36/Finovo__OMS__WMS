<?php

namespace App\Integrations;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Outcome of a store connection check.
 */
final class ConnectionResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly string $message,
    ) {}

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * Turn a failed platform HTTP response into a readable reason.
     */
    public static function fromFailedResponse(Response $response, string $platform, ?string $credentialsHint = null): self
    {
        $hint = $credentialsHint ?? 'Check the key or reconnect the store.';
        $reason = self::platformMessage($response);

        $result = match (true) {
            in_array($response->status(), [401, 403], true) => self::failure("{$platform} rejected the credentials (HTTP {$response->status()}). {$hint}"),
            $response->status() === 404 => self::failure("{$platform} API was not found at this address (HTTP 404). Check the store URL."),
            default => self::failure("{$platform} returned an error (HTTP {$response->status()})."),
        };

        return $reason === null ? $result : self::failure($result->message." {$platform} said: \"{$reason}\"");
    }

    /**
     * The platform's own error text, when it sends one (e.g. WooCommerce "Invalid signature").
     */
    private static function platformMessage(Response $response): ?string
    {
        $message = $response->json('message') ?? $response->json('error_description') ?? $response->json('errors.0.message');

        return is_string($message) && $message !== '' ? Str::limit(strip_tags($message), 160) : null;
    }
}
