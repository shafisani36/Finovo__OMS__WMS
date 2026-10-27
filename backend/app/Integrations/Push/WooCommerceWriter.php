<?php

namespace App\Integrations\Push;

use App\Integrations\Sync\Data\OrderData;
use App\Integrations\WooCommerceClient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Writes products and orders to a WooCommerce store through its REST API (wc/v3).
 */
class WooCommerceWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    /** WooCommerce notifications Finovo listens to. */
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'order.deleted', 'product.created', 'product.updated', 'product.deleted'];

    public function __construct(
        protected WooCommerceClient $client
    ) {}

    protected function platform(): string
    {
        return 'WooCommerce';
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $variable = $options['names'] !== [];
        /** @var ProductVariant $first */
        $first = $product->variants->first();

        $body = array_filter([
            'name' => $product->name,
            'type' => $variable ? 'variable' : 'simple',
            'status' => $product->is_active ? 'publish' : 'draft',
            'description' => (string) $product->description,
            'categories' => $product->category ? [['id' => $this->categoryId($store, $product->category)]] : null,
            // A simple product carries the single variant's price and SKU itself.
            'sku' => $variable ? null : $first->sku,
            'regular_price' => $variable ? null : $this->amount($first->price),
            'global_unique_id' => $variable ? null : $first->barcode,
            'attributes' => $variable ? array_map(fn (string $name, int $index) => [
                'name' => $name,
                'options' => collect($options['values'])->pluck($index)->unique()->values()->all(),
                'visible' => true,
                'variation' => true,
            ], $options['names'], array_keys($options['names'])) : null,
            'images' => ! $externalId && ($image = $this->publicImageUrl($product)) ? [['src' => $image]] : null,
        ], fn ($value) => $value !== null);

        $saved = $externalId
            ? $this->call(fn () => $this->client->put($store, "products/{$externalId}", $body))
            : $this->call(fn () => $this->client->post($store, 'products', $body));
        $productId = (string) $saved['id'];

        if (! $variable) {
            return new PushedProduct($productId, [$first->id => $productId]);
        }

        return new PushedProduct($productId, $this->saveVariations($store, $product, $productId, $options));
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->send(fn () => $this->client->delete($store, "products/{$externalId}", ['force' => 'true']), [404]);
    }

    public function createOrder(Store $store, Order $order): string
    {
        $order->loadMissing('items.variant');
        $lines = [];
        $fees = [];

        foreach ($order->items as $item) {
            $line = $this->lineItem($store, $item);

            // A product not on this store is added as a fee line, so the order total stays right.
            if ($line) {
                $lines[] = $line;
            } else {
                $fees[] = ['name' => $item->product_name.' × '.$item->quantity, 'total' => $this->amount($item->quantity * (float) $item->unit_price)];
            }
        }

        $saved = $this->call(fn () => $this->client->post($store, 'orders', array_filter([
            'status' => $order->payment_status === 'paid' ? 'processing' : 'pending',
            'set_paid' => $order->payment_status === 'paid',
            'currency' => $order->currency,
            'customer_note' => (string) $order->notes,
            'billing' => $this->address($order->billing_address ?: $this->deliveryAddress($order), $order->customer_email),
            'shipping' => $this->address($this->deliveryAddress($order)),
            'line_items' => $lines,
            'fee_lines' => $fees ?: null,
            'shipping_lines' => (float) $order->shipping_amount > 0
                ? [['method_id' => 'flat_rate', 'method_title' => 'Shipping', 'total' => $this->amount($order->shipping_amount)]]
                : null,
            'meta_data' => [['key' => OrderData::FINOVO_REFERENCE_KEY, 'value' => (string) $order->id]],
        ], fn ($value) => $value !== null)));

        return (string) $saved['id'];
    }

    public function updateOrder(Store $store, Order $order): void
    {
        $this->call(fn () => $this->client->put($store, "orders/{$order->external_order_id}", array_filter([
            'customer_note' => (string) $order->notes,
            'billing' => $this->address($order->billing_address ?: $this->deliveryAddress($order), $order->customer_email),
            'shipping' => $this->address($this->deliveryAddress($order)),
            // Records the payment only on an unpaid order (WooCommerce moves it to "processing").
            'set_paid' => $order->payment_status === 'paid' ? true : null,
        ], fn ($value) => $value !== null)));
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        $this->call(fn () => $this->client->put($store, "orders/{$order->external_order_id}", ['status' => 'cancelled']));
    }

    /**
     * WooCommerce has one status for an order being prepared ("Processing"); the warehouse step is added as a
     * private order note.
     */
    public function updateStatus(Store $store, Order $order): void
    {
        $this->call(fn () => $this->client->put($store, "orders/{$order->external_order_id}", ['status' => 'processing']));
        $this->call(fn () => $this->client->post($store, "orders/{$order->external_order_id}/notes", [
            'note' => 'Finovo: '.$this->statusLabel($order->order_status).'.',
            'customer_note' => false,
        ]));
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $stock = ['manage_stock' => true, 'stock_quantity' => $quantity];
        $productId = $variant->productExternalId;

        // A simple product is its own variant; a variation is updated under its parent product.
        $path = $productId && $productId !== $variant->externalId ? "products/{$productId}/variations/{$variant->externalId}" : "products/{$variant->externalId}";
        $this->call(fn () => $this->client->put($store, $path, $stock));
    }

    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $tracking = $this->trackingNote($shipment);

        $this->call(fn () => $this->client->put($store, "orders/{$order->external_order_id}", ['status' => 'completed']));
        $this->call(fn () => $this->client->post($store, "orders/{$order->external_order_id}/notes", ['note' => $tracking, 'customer_note' => true]));

        return null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        // WooCommerce has no delivered state beyond "completed", which the order got when it shipped.
    }

    /**
     * A private order note; a refund (which moves money) is made in WooCommerce itself.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $this->call(fn () => $this->client->post($store, "orders/{$order->external_order_id}/notes", ['note' => $this->returnNote($return), 'customer_note' => false]));
    }

    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        $secret = $this->webhookSecret($store);
        $existing = collect($this->call(fn () => $this->client->get($store, 'webhooks', ['per_page' => 100])))
            ->where('delivery_url', $callbackUrl)
            ->where('status', 'active')
            ->pluck('topic')
            ->all();

        foreach (array_diff(self::WEBHOOK_TOPICS, $existing) as $topic) {
            $this->call(fn () => $this->client->post($store, 'webhooks', [
                'name' => "Finovo: {$topic}",
                'topic' => $topic,
                'delivery_url' => $callbackUrl,
                'secret' => $secret,
                'status' => 'active',
            ]));
        }
    }

    public function removeWebhooks(Store $store, string $callbackUrl): void
    {
        $ours = collect($this->call(fn () => $this->client->get($store, 'webhooks', ['per_page' => 100])))->where('delivery_url', $callbackUrl);

        foreach ($ours as $webhook) {
            $this->send(fn () => $this->client->delete($store, "webhooks/{$webhook['id']}", ['force' => 'true']), [404]);
        }
    }

    /**
     * Create, update and remove variations so the store matches the Finovo variants.
     *
     * @param  array{names: list<string>, values: array<int, list<string>>}  $options
     * @return array<int, string> the store's variation ID by Finovo variant ID
     */
    private function saveVariations(Store $store, Product $product, string $productId, array $options): array
    {
        $existing = collect($this->call(fn () => $this->client->get($store, "products/{$productId}/variations", ['per_page' => 100])))
            ->keyBy(fn (array $variation) => (string) $variation['id']);
        $create = [];
        $update = [];
        $kept = [];

        foreach ($product->variants as $variant) {
            $fields = array_filter([
                'sku' => $variant->sku,
                'regular_price' => $this->amount($variant->price),
                'global_unique_id' => $variant->barcode,
                'status' => $variant->is_active ? 'publish' : 'private',
                'attributes' => array_map(fn (string $name, int $index) => [
                    'name' => $name,
                    'option' => $options['values'][$variant->id][$index] ?? '',
                ], $options['names'], array_keys($options['names'])),
            ], fn ($value) => $value !== null);
            $id = $this->variantExternalId($store, $variant->id);

            if ($id && $existing->has($id)) {
                $update[] = ['id' => (int) $id, ...$fields];
                $kept[] = $id;
            } else {
                $create[] = $fields;
            }
        }

        $result = $this->call(fn () => $this->client->post($store, "products/{$productId}/variations/batch", array_filter([
            'create' => $create,
            'update' => $update,
            'delete' => $existing->keys()->diff($kept)->map(fn (string $id) => (int) $id)->values()->all(),
        ])));

        $saved = collect([...($result['create'] ?? []), ...($result['update'] ?? [])]);
        $errors = $saved->pluck('error.message')->filter()->unique();
        if ($errors->isNotEmpty()) {
            throw new RuntimeException('WooCommerce said: '.$errors->implode(' '));
        }

        $bySku = $saved->filter(fn (array $variation) => filled($variation['sku'] ?? null))->keyBy('sku');

        return $product->variants
            ->filter(fn (ProductVariant $variant) => $bySku->has($variant->sku))
            ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => (string) $bySku[$variant->sku]['id']])
            ->all();
    }

    /**
     * An order line for a product that is on this store, or null when it is not.
     *
     * @return array<string, mixed>|null
     */
    private function lineItem(Store $store, OrderItem $item): ?array
    {
        $variantId = $this->variantExternalId($store, $item->product_variant_id);
        if (! $variantId) {
            return null;
        }

        $productId = $this->productExternalId($store, $item->variant?->product_id);
        $total = $this->amount($item->quantity * (float) $item->unit_price);

        // A variation is sent with its parent product; a simple product is its own single variant.
        return array_filter([
            'product_id' => (int) ($productId ?? $variantId),
            'variation_id' => $productId && $productId !== $variantId ? (int) $variantId : null,
            'quantity' => $item->quantity,
            'subtotal' => $total,
            'total' => $total,
        ], fn ($value) => $value !== null);
    }

    /**
     * A Finovo address as a WooCommerce billing / shipping address.
     *
     * @param  array<string, string>  $address
     * @return array<string, string>
     */
    private function address(array $address, ?string $email = null): array
    {
        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $address['company'] ?? null,
            'address_1' => $address['address1'] ?? null,
            'address_2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['province'] ?? null,
            'postcode' => $address['zip'] ?? null,
            'country' => $address['country_code'] ?? null,
            'phone' => $address['phone'] ?? null,
            'email' => $email,
        ], fn ($value) => filled($value));
    }

    /**
     * The store's ID for a category name, creating the category when the store has none by that name.
     */
    private function categoryId(Store $store, string $name): int
    {
        $match = collect($this->call(fn () => $this->client->get($store, 'products/categories', ['search' => $name, 'per_page' => 100])))
            ->first(fn (array $category) => strcasecmp(html_entity_decode((string) $category['name']), $name) === 0);

        return (int) ($match['id'] ?? $this->call(fn () => $this->client->post($store, 'products/categories', ['name' => $name]))['id']);
    }

    /**
     * Send a request and return its JSON body.
     *
     * @param  \Closure(): Response  $request
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private function call(\Closure $request): array
    {
        return (array) $this->send($request)->json();
    }
}
