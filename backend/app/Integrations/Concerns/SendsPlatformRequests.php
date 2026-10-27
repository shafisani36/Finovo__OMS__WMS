<?php

namespace App\Integrations\Concerns;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Request handling shared by everything that talks to a store platform over HTTP (reading and writing):
 * waits and retries while the platform rate-limits Finovo, and turns failures into messages a merchant understands.
 */
trait SendsPlatformRequests
{
    private const MAX_RETRIES = 5;

    /** Platform name used in error messages, e.g. "WooCommerce". */
    abstract protected function platform(): string;

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
        $message = $response->json('message') ?? $response->json('error.message') ?? $response->json('errors.0.message') ?? $response->json('title') ?? $response->json('0.message');

        return is_string($message) && $message !== '' ? ': '.$message : '';
    }
}
