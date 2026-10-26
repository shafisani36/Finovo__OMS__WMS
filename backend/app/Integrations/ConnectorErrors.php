<?php

namespace App\Integrations;

use App\Models\IntegrationError;
use App\Models\Store;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * One place that handles every failure while Finovo talks to a store's platform (connect, authorize, test, sync),
 * for all platforms. The failure is kept against the store (integration_errors and the store's connection error),
 * unexpected ones are also sent to the log, and the caller gets a message the merchant can act on, so a platform
 * problem never crashes the portal.
 */
class ConnectorErrors
{
    /**
     * Run a call to the store's platform.
     *
     * @template T
     *
     * @param  string  $action  what was being done, e.g. "sync" or "authorize"
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws ValidationException with the readable reason when the call fails
     */
    public function run(Store $store, string $action, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['store' => $this->record($store, $action, $e)]);
        }
    }

    /**
     * Keep the failure against the store and return the reason to show the merchant.
     */
    public function record(Store $store, string $action, Throwable $e): string
    {
        $message = $this->reason($e, $store->platform->label());

        // A store that is still being created has nothing to attach the error to yet.
        if (! $store->exists) {
            return $message;
        }

        // Saving the error must never become a second failure.
        rescue(function () use ($store, $action, $e, $message) {
            IntegrationError::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'error_code' => Str::limit("{$action}.".class_basename($e), 250, ''),
                'summary' => Str::limit($message, 250),
                'technical_details' => Str::limit($e->getMessage(), 2000)."\n".$e->getFile().':'.$e->getLine(),
                'is_retryable' => $e instanceof ConnectionException || $e instanceof RequestException || $this->isExpected($e),
            ]);

            $store->forceFill(['sync_status' => 'sync_issues', 'connection_error' => Str::limit($message, 1000)])->save();
        }, report: true);

        return $message;
    }

    /**
     * The reason to show the merchant; unexpected failures are also sent to the log.
     */
    public function reason(Throwable $e, string $platform): string
    {
        if (! $this->isExpected($e)) {
            report($e);
        }

        return $this->describe($e, $platform);
    }

    /**
     * The reason in words the merchant understands, without server details.
     */
    private function describe(Throwable $e, string $platform): string
    {
        return match (true) {
            $e instanceof ConnectionException => str_contains(strtolower($e->getMessage()), 'timed out')
                ? "{$platform} took too long to answer. Try again in a few minutes."
                : "Could not reach {$platform}. Check the store address and that the store is online.",
            $e instanceof RequestException => ConnectionResult::fromFailedResponse($e->response, $platform)->message,
            $e instanceof QueryException => "Finovo could not save the data from {$platform}. The error was logged; try again in a few minutes.",
            $this->isExpected($e) => $e->getMessage(),
            default => "Something went wrong while working with {$platform}. The error was logged; try again in a few minutes.",
        };
    }

    /**
     * Failures Finovo raises itself with a readable message (platform refused access, link expired...).
     */
    private function isExpected(Throwable $e): bool
    {
        return $e::class === RuntimeException::class;
    }
}
