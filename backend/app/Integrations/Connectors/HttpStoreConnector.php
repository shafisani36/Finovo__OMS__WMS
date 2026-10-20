<?php

namespace App\Integrations\Connectors;

use App\Integrations\ConnectionResult;
use App\Integrations\Contracts\StoreConnector;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared request handling for connectors that talk to a platform over HTTP.
 */
abstract class HttpStoreConnector implements StoreConnector
{
    protected const TIMEOUT_SECONDS = 15;

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(static::TIMEOUT_SECONDS);
    }

    /**
     * @param  Closure(): Response  $send
     * @param  Closure(Response): string  $describe  success message built from the platform response
     * @param  string|null  $credentialsHint  extra advice shown when the platform rejects the credentials
     */
    protected function attempt(string $platform, Closure $send, Closure $describe, ?string $credentialsHint = null): ConnectionResult
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            return ConnectionResult::failure("Could not reach {$platform}. Check the store address and that the site is online.");
        } catch (RequestException $e) {
            // Raised by the transport itself (e.g. a proxy refusing the tunnel) before a platform response exists.
            return ConnectionResult::fromFailedResponse($e->response, $platform, $credentialsHint);
        }

        if (! $response->successful()) {
            return ConnectionResult::fromFailedResponse($response, $platform, $credentialsHint);
        }

        return ConnectionResult::success($describe($response));
    }
}
