<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Sync\Contracts\StoreDataSource;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Shared request handling for platforms read over HTTP: rate-limit retries and clear error messages.
 */
abstract class HttpDataSource implements StoreDataSource
{
    private const MAX_RETRIES = 5;

    /** @var list<string> */
    protected array $warnings = [];

    /** Platform name used in error messages, e.g. "WooCommerce". */
    abstract protected function platform(): string;

    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Send a request, waiting and retrying while the platform rate-limits Finovo.
     *
     * @param  Closure(): Response  $request
     * @param  list<int>  $acceptedStatuses  non-2xx statuses the caller handles itself (e.g. 404)
     *
     * @throws RuntimeException when the platform cannot be reached or refuses the request
     */
    protected function send(Closure $request, array $acceptedStatuses = []): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $e) {
                throw new RuntimeException(str_contains(strtolower($e->getMessage()), 'timed out')
                    ? "{$this->platform()} took too long to answer. Try again; if it keeps happening the shop's server is too slow or overloaded."
                    : "Could not reach {$this->platform()}. Check the store is online and try again.");
            } catch (RequestException $e) {
                $response = $e->response;
            }

            if ($this->isRateLimited($response) && $attempt < self::MAX_RETRIES) {
                Sleep::for(2 * $attempt)->seconds();

                continue;
            }

            if ($response->successful() || in_array($response->status(), $acceptedStatuses, true)) {
                return $response;
            }

            if (in_array($response->status(), [401, 403], true)) {
                throw new RuntimeException("{$this->platform()} rejected Finovo's access (HTTP {$response->status()}). Check the store's permissions or connect it again.");
            }

            throw new RuntimeException("{$this->platform()} returned HTTP {$response->status()}".$this->errorDetail($response).'.');
        }
    }

    protected function isRateLimited(Response $response): bool
    {
        return $response->status() === 429;
    }

    private function errorDetail(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error.message') ?? $response->json('errors.0.message') ?? $response->json('title');

        return is_string($message) && $message !== '' ? ': '.$message : '';
    }

    /**
     * HTML descriptions from store platforms as plain text.
     */
    protected static function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : $text;
    }
}
