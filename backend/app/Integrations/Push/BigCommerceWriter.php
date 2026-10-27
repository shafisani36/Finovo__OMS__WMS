<?php

namespace App\Integrations\Push;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes products, orders and stock to a BigCommerce store (orders: v2 API, catalog and webhooks: v3 API), and
 * subscribes Finovo to its order and product notifications.
 * Order statuses: https://developer.bigcommerce.com/docs/store-operations/orders/order-statuses
 */
class BigCommerceWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    /** BigCommerce notifications Finovo listens to. */
    public const WEBHOOK_SCOPES = [
        'store/order/created', 'store/order/updated', 'store/order/statusUpdated',
        'store/product/created', 'store/product/updated', 'store/product/deleted',
    ];

    /** Header carrying the store's secret on every notification (BigCommerce sends the headers set on the hook). */
    public const SECRET_HEADER = 'X-Finovo-Webhook-Secret';

    private const STATUS_PENDING = 1;

    private const STATUS_SHIPPED = 2;

    private const STATUS_CANCELLED = 5;

    private const STATUS_AWAITING_PAYMENT = 7;

    private const STATUS_AWAITING_SHIPMENT = 9;

    private const STATUS_COMPLETED = 10;

    private const STATUS_AWAITING_FULFILLMENT = 11;

    /** Statuses of an order that has not been paid or prepared yet (0 = incomplete). */
    private const OPEN_STATUSES = [0, self::STATUS_PENDING, self::STATUS_AWAITING_PAYMENT];

    /** Statuses Finovo's warehouse steps may still change. */
    private const PREPARING_STATUSES = [...self::OPEN_STATUSES, self::STATUS_AWAITING_FULFILLMENT, self::STATUS_AWAITING_SHIPMENT];

    protected function platform(): string
    {
        return 'BigCommerce';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $email = $this->requireEmail($order);
        $address = $this->address($this->deliveryAddress($order), $email);

        $created = $this->call(fn () => $this->api($store)->post('v2/orders', array_filter([
            'customer_id' => 0,
            'status_id' => $order->payment_status === 'paid' ? self::STATUS_AWAITING_FULFILLMENT : self::STATUS_AWAITING_PAYMENT,
            'billing_address' => $order->billing_address ? $this->address($order->billing_address, $email) : $address,
            'shipping_addresses' => [$address],
            'products' => array_map(fn (array $line) => $this->orderProduct($line), $this->storeLines($store, $order)),
            'shipping_cost_inc_tax' => $this->amount($order->shipping_amount),
            'shipping_cost_ex_tax' => $this->amount($order->shipping_amount),
            'discount_amount' => (float) $order->discount_amount > 0 ? $this->amount($order->discount_amount) : null,
            'customer_message' => $order->notes,
            'payment_method' => $order->payment_method ?: null,
            'external_source' => 'Finovo',
            'external_order_id' => $order->order_number,
            'staff_notes' => "Created in Finovo as order {$order->order_number}.",
        ], fn ($value) => $value !== null)));

        return (string) $created['id'];
    }

    public function updateOrder(Store $store, Order $order): void
    {
        $current = $this->order($store, $order);
        $paidNow = $order->payment_status === 'paid' && in_array((int) $current['status_id'], self::OPEN_STATUSES, true);

        $this->call(fn () => $this->api($store)->put("v2/orders/{$order->external_order_id}", array_filter([
            'billing_address' => array_filter($this->address($order->billing_address ?: $this->deliveryAddress($order), $order->customer_email)),
            'customer_message' => $order->notes,
            'status_id' => $paidNow ? self::STATUS_AWAITING_FULFILLMENT : null,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Allocated and being picked: "Awaiting Fulfillment"; packed: "Awaiting Shipment".
     */
    public function updateStatus(Store $store, Order $order): void
    {
        $status = $order->order_status === 'packed' ? self::STATUS_AWAITING_SHIPMENT : self::STATUS_AWAITING_FULFILLMENT;
        $current = (int) $this->order($store, $order)['status_id'];

        if ($current !== $status && in_array($current, self::PREPARING_STATUSES, true)) {
            $this->setStatus($store, $order, $status);
        }
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        if ((int) $this->order($store, $order)['status_id'] !== self::STATUS_CANCELLED) {
            $this->setStatus($store, $order, self::STATUS_CANCELLED);
        }
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $productId = $variant->productExternalId ?? throw new RuntimeException('The product is not linked to BigCommerce; sync the store to refresh its products.');
        $tracking = $this->call(fn () => $this->api($store)->get("v3/catalog/products/{$productId}", ['include_fields' => 'inventory_tracking']))['data']['inventory_tracking'] ?? 'none';

        // Stock kept on the product itself (one count for all its variants).
        if ($tracking === 'product') {
            $this->call(fn () => $this->api($store)->put("v3/catalog/products/{$productId}", ['inventory_level' => $quantity]));

            return;
        }

        // BigCommerce only keeps variant counts when the product tracks stock by variant.
        if ($tracking === 'none') {
            $this->call(fn () => $this->api($store)->put("v3/catalog/products/{$productId}", ['inventory_tracking' => 'variant']));
        }

        $this->call(fn () => $this->api($store)->put("v3/catalog/products/{$productId}/variants/{$variant->externalId}", ['inventory_level' => $quantity]));
    }

    /**
     * A shipment with the tracking for every line; BigCommerce then marks the order "Shipped" and emails the customer.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        if ((int) $this->order($store, $order)['status_id'] === self::STATUS_SHIPPED) {
            return null;
        }

        $addresses = $this->call(fn () => $this->api($store)->get("v2/orders/{$order->external_order_id}/shipping_addresses"));
        $products = $this->call(fn () => $this->api($store)->get("v2/orders/{$order->external_order_id}/products", ['limit' => 250]));

        $created = $this->call(fn () => $this->api($store)->post("v2/orders/{$order->external_order_id}/shipments", array_filter([
            'order_address_id' => $addresses[0]['id'] ?? throw new RuntimeException('The BigCommerce order has no shipping address to ship to.'),
            'tracking_number' => $shipment->tracking_number,
            'shipping_method' => $shipment->carrier,
            'tracking_link' => $shipment->trackingLink(),
            'items' => array_map(fn (array $product) => ['order_product_id' => $product['id'], 'quantity' => (int) $product['quantity']], $products),
        ])));

        return isset($created['id']) ? (string) $created['id'] : null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        $this->setStatus($store, $order, self::STATUS_COMPLETED);
    }

    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $notes = (string) ($this->order($store, $order)['staff_notes'] ?? '');

        $this->call(fn () => $this->api($store)->put("v2/orders/{$order->external_order_id}", ['staff_notes' => trim($notes."\n".$this->returnNote($return))]));
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $variable = $options['names'] !== [];
        /** @var ProductVariant $first */
        $first = $product->variants->first();
        $fields = array_filter([
            'name' => $product->name,
            'type' => 'physical',
            'weight' => 0,
            'price' => (float) $first->price,
            'cost_price' => ! $variable && (float) $first->cost_price > 0 ? (float) $first->cost_price : null,
            'sku' => $variable ? null : $first->sku,
            'upc' => $variable ? null : $first->barcode,
            'description' => (string) $product->description,
            'is_visible' => (bool) $product->is_active,
            'inventory_tracking' => $variable ? 'variant' : 'product',
        ], fn ($value) => $value !== null);

        if ($externalId) {
            $this->call(fn () => $this->api($store)->put("v3/catalog/products/{$externalId}", $fields));
            $productId = $externalId;

            if ($variable) {
                $this->saveVariants($store, $product, $productId, $options);
            }
        } else {
            $image = $this->publicImageUrl($product);
            $productId = (string) $this->call(fn () => $this->api($store)->post('v3/catalog/products', array_filter([
                ...$fields,
                // BigCommerce creates the options from the variants' option names and values.
                'variants' => $variable ? $product->variants->map(fn (ProductVariant $variant) => $this->variantFields($variant) + [
                    'option_values' => array_map(fn (string $name, int $index) => ['option_display_name' => $name, 'label' => $options['values'][$variant->id][$index] ?? ''], $options['names'], array_keys($options['names'])),
                ])->values()->all() : null,
                'images' => $image ? [['image_url' => $image, 'is_thumbnail' => true]] : null,
            ])))['data']['id'];
        }

        $storeVariants = collect($this->call(fn () => $this->api($store)->get("v3/catalog/products/{$productId}/variants", ['limit' => 250]))['data'] ?? []);

        // A product without options has one variant: the product itself.
        if (! $variable) {
            return new PushedProduct($productId, [$first->id => (string) $storeVariants->first()['id']]);
        }

        $bySku = $storeVariants->keyBy('sku');

        return new PushedProduct($productId, $product->variants
            ->filter(fn (ProductVariant $variant) => $bySku->has($variant->sku))
            ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => (string) $bySku[$variant->sku]['id']])
            ->all());
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->send(fn () => $this->api($store)->delete("v3/catalog/products/{$externalId}"), [404]);
    }

    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        $secret = $this->webhookSecret($store);
        $ours = collect($this->call(fn () => $this->api($store)->get('v3/hooks'))['data'] ?? [])->where('destination', $callbackUrl)->keyBy('scope');

        foreach (self::WEBHOOK_SCOPES as $scope) {
            $hook = $ours->get($scope);

            if (! $hook) {
                $this->call(fn () => $this->api($store)->post('v3/hooks', [
                    'scope' => $scope,
                    'destination' => $callbackUrl,
                    'is_active' => true,
                    'headers' => [self::SECRET_HEADER => $secret],
                ]));
            } elseif (empty($hook['is_active'])) {
                $this->call(fn () => $this->api($store)->put("v3/hooks/{$hook['id']}", ['is_active' => true, 'headers' => [self::SECRET_HEADER => $secret]]));
            }
        }
    }

    public function removeWebhooks(Store $store, string $callbackUrl): void
    {
        $ours = collect($this->call(fn () => $this->api($store)->get('v3/hooks'))['data'] ?? [])->where('destination', $callbackUrl);

        foreach ($ours as $hook) {
            $this->send(fn () => $this->api($store)->delete("v3/hooks/{$hook['id']}"), [404]);
        }
    }

    /**
     * Update the variants the store has, add new ones (creating any option or value it lacks) and remove the rest.
     *
     * @param  array{names: list<string>, values: array<int, list<string>>}  $options
     */
    private function saveVariants(Store $store, Product $product, string $productId, array $options): void
    {
        $existing = collect($this->call(fn () => $this->api($store)->get("v3/catalog/products/{$productId}/variants", ['limit' => 250]))['data'] ?? [])->keyBy(fn (array $variant) => (string) $variant['id']);
        $kept = [];

        foreach ($product->variants as $variant) {
            $id = $this->variantExternalId($store, $variant->id);

            if ($id && $existing->has($id)) {
                $this->call(fn () => $this->api($store)->put("v3/catalog/products/{$productId}/variants/{$id}", $this->variantFields($variant)));
                $kept[] = $id;

                continue;
            }

            $values = [];
            foreach ($options['names'] as $index => $name) {
                $values[] = $this->optionValue($store, $productId, $name, $options['values'][$variant->id][$index] ?? '');
            }

            $created = $this->call(fn () => $this->api($store)->post("v3/catalog/products/{$productId}/variants", $this->variantFields($variant) + ['option_values' => $values]));
            $kept[] = (string) $created['data']['id'];
        }

        foreach ($existing->keys()->diff($kept) as $id) {
            $this->send(fn () => $this->api($store)->delete("v3/catalog/products/{$productId}/variants/{$id}"), [404]);
        }
    }

    /**
     * The product option and value for a variant choice, created when the product does not have them yet.
     *
     * @return array{option_id: int, id: int}
     */
    private function optionValue(Store $store, string $productId, string $name, string $label): array
    {
        $option = collect($this->call(fn () => $this->api($store)->get("v3/catalog/products/{$productId}/options"))['data'] ?? [])
            ->first(fn (array $option) => strcasecmp((string) $option['display_name'], $name) === 0);

        if (! $option) {
            $option = $this->call(fn () => $this->api($store)->post("v3/catalog/products/{$productId}/options", [
                'display_name' => $name,
                'type' => 'rectangles',
                'option_values' => [['label' => $label, 'sort_order' => 0]],
            ]))['data'];
        }

        $value = collect($option['option_values'] ?? [])->first(fn (array $value) => strcasecmp((string) $value['label'], $label) === 0)
            ?? $this->call(fn () => $this->api($store)->post("v3/catalog/products/{$productId}/options/{$option['id']}/values", ['label' => $label]))['data'];

        return ['option_id' => (int) $option['id'], 'id' => (int) $value['id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantFields(ProductVariant $variant): array
    {
        return array_filter([
            'sku' => $variant->sku,
            'price' => (float) $variant->price,
            'cost_price' => (float) $variant->cost_price > 0 ? (float) $variant->cost_price : null,
            'upc' => $variant->barcode,
            'purchasing_disabled' => ! $variant->is_active,
        ], fn ($value) => $value !== null);
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->baseUrl("https://api.bigcommerce.com/stores/{$store->external_reference}/")
            ->withHeaders(['X-Auth-Token' => (string) $store->credential('access_token')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Store $store, Order $order): array
    {
        return $this->call(fn () => $this->api($store)->get("v2/orders/{$order->external_order_id}"));
    }

    private function setStatus(Store $store, Order $order, int $status): void
    {
        $this->call(fn () => $this->api($store)->put("v2/orders/{$order->external_order_id}", ['status_id' => $status]));
    }

    /**
     * A line linked to the store is sent as the store's own product; any other line as a custom product, so
     * the order total stays right.
     *
     * @param  array{item: OrderItem, variant: string|null, product: string|null}  $line
     * @return array<string, mixed>
     */
    private function orderProduct(array $line): array
    {
        $item = $line['item'];
        $price = ['quantity' => $item->quantity, 'price_inc_tax' => $this->amount($item->unit_price), 'price_ex_tax' => $this->amount($item->unit_price)];

        return $line['variant'] && $line['product']
            ? ['product_id' => (int) $line['product'], 'variant_id' => (int) $line['variant'], ...$price]
            : array_filter(['name' => $item->product_name, 'sku' => $item->external_sku ?: null, ...$price]);
    }

    /**
     * A Finovo address as a BigCommerce address.
     *
     * @param  array<string, string>  $address
     * @return array<string, string>
     */
    private function address(array $address, ?string $email): array
    {
        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return array_filter([
            'first_name' => $firstName ?: $lastName,
            'last_name' => $lastName,
            'company' => $address['company'] ?? null,
            'street_1' => $address['address1'] ?? null,
            'street_2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['province'] ?? null,
            'zip' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
            'country_iso2' => $address['country_code'] ?? null,
            'phone' => $address['phone'] ?? null,
            'email' => $email,
        ], fn ($value) => filled($value));
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
