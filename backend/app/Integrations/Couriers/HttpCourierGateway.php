<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP handling for the courier gateways: the live or test address (or one set on the account),
 * timeouts, and turning failures into CourierException with the courier's own message.
 */
abstract class HttpCourierGateway implements CourierGateway
{
    /** Live API address. */
    abstract protected function liveUrl(): string;

    /** Test (sandbox) API address; null when the courier has none. */
    abstract protected function sandboxUrl(): ?string;

    /** The courier's name in messages. */
    abstract protected function name(): string;

    protected function baseUrl(CourierAccount $account): string
    {
        $url = $account->setting('api_url') ?: ($account->sandbox ? ($this->sandboxUrl() ?? $this->liveUrl()) : $this->liveUrl());

        return rtrim((string) $url, '/');
    }

    protected function http(CourierAccount $account): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($account))->acceptJson()->timeout(25)->connectTimeout(10);
    }

    /**
     * Send a request and return the response, or throw with the courier's message.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(CourierAccount $account, callable $call): Response
    {
        try {
            $response = $call($this->http($account));
        } catch (ConnectionException $e) {
            throw new CourierException("{$this->name()} could not be reached. Try again in a moment.", previous: $e);
        }

        if ($response->serverError()) {
            throw new CourierException("{$this->name()} is not responding properly (error {$response->status()}). Try again later.");
        }

        return $response;
    }

    protected function fail(string $message): never
    {
        throw new CourierException("{$this->name()}: {$message}");
    }
}
