<?php

namespace App\Integrations\Push\OpenCart;

use App\Integrations\BridgeSignature;
use App\Models\Store;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenCart changes made through the Finovo OpenCart module (signed POST requests to its catalog API), which uses
 * OpenCart's own order code, so the shop's emails and stock rules apply.
 */
class OpenCartModuleShop implements OpenCartShop
{
    /**
     * @param  Closure(Closure(): Response, list<int>): Response  $send  retries and error handling of the writer
     */
    public function __construct(
        private readonly Store $store,
        private readonly Closure $send,
    ) {}

    public function orderStatuses(): array
    {
        return collect((array) $this->call('order_statuses')->json('rows'))
            ->mapWithKeys(fn (array $row) => [(int) $row['order_status_id'] => html_entity_decode((string) $row['name'], ENT_QUOTES | ENT_HTML5)])
            ->all();
    }

    public function orderStatusId(int $orderId): int
    {
        return (int) $this->call('order_status', ['order_id' => $orderId])->json('order_status_id');
    }

    public function addOrderHistory(int $orderId, int $statusId, string $comment, bool $notify): void
    {
        $this->call('add_order_history', ['order_id' => $orderId, 'order_status_id' => $statusId, 'comment' => $comment, 'notify' => $notify]);
    }

    public function setStock(int $productId, ?int $optionValueId, int $quantity): void
    {
        $this->call('set_stock', ['product_id' => $productId, 'product_option_value_id' => $optionValueId, 'quantity' => $quantity]);
    }

    public function createOrder(array $order, int $statusId, string $comment): int
    {
        return (int) $this->call('create_order', ['order' => $order, 'order_status_id' => $statusId, 'comment' => $comment])->json('order_id');
    }

    public function saveProduct(array $product): array
    {
        $saved = $this->call('save_product', ['product' => $product]);

        return [
            'product_id' => (int) $saved->json('product_id'),
            'option_values' => array_map('intval', (array) $saved->json('option_values')),
        ];
    }

    public function deleteProduct(int $productId): void
    {
        $this->call('delete_product', ['product_id' => $productId]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $action, array $payload = []): Response
    {
        $body = (string) json_encode($payload);
        $url = rtrim((string) $this->store->external_reference, '/').'/index.php?route=extension/finovo/api/'.$action;

        $response = ($this->send)(fn () => Http::acceptJson()
            ->timeout(60)
            ->withHeaders(BridgeSignature::headers((string) $this->store->credential('api_key'), (string) $this->store->credential('api_secret'), $body))
            ->withBody($body, 'application/json')
            ->post($url), [404, 422]);

        // Modules installed before Finovo could update OpenCart only answer the read actions.
        if ($response->status() === 404) {
            throw new RuntimeException('The Finovo module on this OpenCart shop is an older version that can only read. Download the module again from Finovo (Stores → Connect) and install it over the old one.');
        }

        if ($response->status() === 422) {
            throw new RuntimeException('OpenCart said: '.($response->json('message') ?? 'the change was refused.'));
        }

        return $response;
    }
}
