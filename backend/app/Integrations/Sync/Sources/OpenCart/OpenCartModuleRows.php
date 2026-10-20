<?php

namespace App\Integrations\Sync\Sources\OpenCart;

use App\Integrations\BridgeSignature;
use App\Models\Store;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenCart rows read through the Finovo OpenCart module (signed POST requests to its catalog API).
 */
class OpenCartModuleRows implements OpenCartRows
{
    private ?string $imageBaseUrl = null;

    /**
     * @param  Closure(Closure(): Response): Response  $send  retries and error handling of the data source
     */
    public function __construct(
        private readonly Store $store,
        private readonly Closure $send,
    ) {}

    public function products(int $offset, int $limit): array
    {
        $response = $this->call('products', ['offset' => $offset, 'limit' => $limit]);
        $this->imageBaseUrl = $response->json('image_base_url');

        return (array) $response->json('rows');
    }

    public function optionValues(array $productIds): array
    {
        return $productIds === [] ? [] : (array) $this->call('option_values', ['product_ids' => $productIds])->json('rows');
    }

    public function orders(?string $modifiedSince, int $offset, int $limit): array
    {
        return (array) $this->call('orders', ['modified_since' => $modifiedSince, 'offset' => $offset, 'limit' => $limit])->json('rows');
    }

    public function orderProducts(array $orderIds): array
    {
        return $orderIds === [] ? [] : (array) $this->call('order_products', ['order_ids' => $orderIds])->json('rows');
    }

    public function imageBaseUrl(): ?string
    {
        return $this->imageBaseUrl;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $action, array $payload): Response
    {
        $body = (string) json_encode($payload);
        $url = rtrim((string) $this->store->external_reference, '/').'/index.php?route=extension/finovo/api/'.$action;

        return ($this->send)(fn () => Http::acceptJson()
            ->timeout(60)
            ->withHeaders(BridgeSignature::headers((string) $this->store->credential('api_key'), (string) $this->store->credential('api_secret'), $body))
            ->withBody($body, 'application/json')
            ->post($url));
    }
}
