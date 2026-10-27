<?php

namespace App\Integrations\Push;

use App\Integrations\ShopifyClient;
use App\Integrations\Sync\Data\OrderData;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes products and orders to a Shopify shop through the GraphQL Admin API.
 */
class ShopifyWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    /** Shopify notifications Finovo listens to. */
    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'PRODUCTS_CREATE', 'PRODUCTS_UPDATE', 'PRODUCTS_DELETE', 'APP_UNINSTALLED'];

    public function __construct(
        protected ShopifyClient $client
    ) {}

    protected function platform(): string
    {
        return 'Shopify';
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $optionNames = $options['names'] ?: ['Title'];

        $input = [
            'title' => $product->name,
            'descriptionHtml' => $product->description ? nl2br(e($product->description)) : '',
            'productType' => (string) $product->category,
            'status' => $product->is_active ? 'ACTIVE' : 'DRAFT',
            'productOptions' => array_map(fn (string $name, int $index) => [
                'name' => $name,
                'values' => collect($options['values'])
                    ->map(fn (array $values) => ['name' => $values[$index] ?? 'Default Title'])
                    ->unique('name')
                    ->values()
                    ->all(),
            ], $optionNames, array_keys($optionNames)),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => array_filter([
                'id' => ($id = $this->variantExternalId($store, $variant->id)) ? ShopifyClient::gid('ProductVariant', $id) : null,
                'optionValues' => array_map(fn (string $name, int $index) => [
                    'optionName' => $name,
                    'name' => $options['values'][$variant->id][$index] ?? 'Default Title',
                ], $optionNames, array_keys($optionNames)),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $this->amount($variant->price),
                'inventoryItem' => array_filter([
                    'tracked' => true,
                    'cost' => (float) $variant->cost_price > 0 ? $this->amount($variant->cost_price) : null,
                ], fn ($value) => $value !== null),
            ], fn ($value) => $value !== null))->values()->all(),
        ];

        // A photo is added when the product is created; later edits leave the shop's photos alone.
        if (! $externalId && ($image = $this->publicImageUrl($product))) {
            $input['files'] = [['originalSource' => $image, 'contentType' => 'IMAGE']];
        }

        $result = $this->mutate($store, 'mutation($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
            productSet(input: $input, identifier: $identifier, synchronous: true) {
                product { legacyResourceId variants(first: 250) { nodes { legacyResourceId sku } } }
                userErrors { field message }
            }
        }', ['input' => $input, 'identifier' => $externalId ? ['id' => ShopifyClient::gid('Product', $externalId)] : null], 'productSet');

        $shopVariants = collect($result['product']['variants']['nodes'] ?? [])->keyBy('sku');

        return new PushedProduct(
            externalId: (string) $result['product']['legacyResourceId'],
            variantIds: $product->variants
                ->filter(fn (ProductVariant $variant) => $shopVariants->has($variant->sku))
                ->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => (string) $shopVariants[$variant->sku]['legacyResourceId']])
                ->all(),
        );
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->mutate($store, 'mutation($input: ProductDeleteInput!) {
            productDelete(input: $input) { deletedProductId userErrors { field message } }
        }', ['input' => ['id' => ShopifyClient::gid('Product', $externalId)]], 'productDelete');
    }

    public function createOrder(Store $store, Order $order): string
    {
        $order->loadMissing('items.variant');
        $money = fn ($amount) => ['shopMoney' => ['amount' => $this->amount($amount), 'currencyCode' => $order->currency]];
        [$firstName, $lastName] = $this->splitName($order->customer_name);

        $input = array_filter([
            'currency' => $order->currency,
            'email' => $order->customer_email,
            'phone' => $order->customer_phone,
            'note' => $order->notes,
            'tags' => ['Finovo'],
            'financialStatus' => $order->payment_status === 'paid' ? 'PAID' : 'PENDING',
            'customAttributes' => [['key' => OrderData::FINOVO_REFERENCE_KEY, 'value' => (string) $order->id]],
            'customer' => $order->customer_email ? ['toUpsert' => array_filter(['email' => $order->customer_email, 'firstName' => $firstName ?: null, 'lastName' => $lastName ?: null])] : null,
            'shippingAddress' => $this->mailingAddress($this->deliveryAddress($order)),
            'billingAddress' => $order->billing_address ? $this->mailingAddress($order->billing_address) : null,
            'shippingLines' => (float) $order->shipping_amount > 0 ? [['title' => 'Shipping', 'priceSet' => $money($order->shipping_amount)]] : null,
            'lineItems' => $order->items->map(function (OrderItem $item) use ($store, $money) {
                $variantId = $this->variantExternalId($store, $item->product_variant_id);

                // A product not on this shop is sent as a custom line with its own name and price.
                return $variantId
                    ? ['variantId' => ShopifyClient::gid('ProductVariant', $variantId), 'quantity' => $item->quantity, 'priceSet' => $money($item->unit_price)]
                    : array_filter(['title' => $item->product_name, 'sku' => $item->external_sku ?: null, 'quantity' => $item->quantity, 'priceSet' => $money($item->unit_price)]);
            })->values()->all(),
        ], fn ($value) => $value !== null && $value !== []);

        $result = $this->mutate($store, 'mutation($order: OrderCreateOrderInput!, $options: OrderCreateOptionsInput) {
            orderCreate(order: $order, options: $options) { order { legacyResourceId name } userErrors { field message } }
        }', ['order' => $input, 'options' => ['sendReceipt' => false, 'inventoryBehaviour' => 'BYPASS']], 'orderCreate');

        return (string) $result['order']['legacyResourceId'];
    }

    public function updateOrder(Store $store, Order $order): void
    {
        $orderId = ShopifyClient::gid('Order', (string) $order->external_order_id);

        $this->mutate($store, 'mutation($input: OrderInput!) {
            orderUpdate(input: $input) { order { id } userErrors { field message } }
        }', ['input' => array_filter([
            'id' => $orderId,
            'email' => $order->customer_email,
            'note' => (string) $order->notes,
            'shippingAddress' => $this->mailingAddress($this->deliveryAddress($order)),
        ], fn ($value) => $value !== null)], 'orderUpdate');

        // Paid in Finovo (e.g. cash on delivery collected): the shop shows it as paid too.
        if ($order->payment_status === 'paid' && ! in_array($this->orderState($store, $orderId)['displayFinancialStatus'] ?? null, ['PAID', 'PARTIALLY_REFUNDED', 'REFUNDED'], true)) {
            $this->mutate($store, 'mutation($input: OrderMarkAsPaidInput!) {
                orderMarkAsPaid(input: $input) { order { id } userErrors { field message } }
            }', ['input' => ['id' => $orderId]], 'orderMarkAsPaid');
        }
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        // Shopify refuses to cancel an order twice.
        if ($this->orderState($store, ShopifyClient::gid('Order', (string) $order->external_order_id))['cancelledAt'] ?? null) {
            return;
        }

        $this->mutate($store, 'mutation($orderId: ID!, $reason: OrderCancelReason!, $restock: Boolean!, $staffNote: String) {
            orderCancel(orderId: $orderId, reason: $reason, restock: $restock, notifyCustomer: false, staffNote: $staffNote) {
                orderCancelUserErrors { field message }
            }
        }', [
            'orderId' => ShopifyClient::gid('Order', (string) $order->external_order_id),
            'reason' => 'OTHER',
            // Finovo keeps the stock; the shop's own inventory is not changed.
            'restock' => false,
            'staffNote' => 'Cancelled in Finovo.',
        ], 'orderCancel', 'orderCancelUserErrors');
    }

    /**
     * Shopify has no warehouse steps of its own, so the step is kept as a tag on the order ("Finovo: Packed").
     */
    public function updateStatus(Store $store, Order $order): void
    {
        $orderId = ShopifyClient::gid('Order', (string) $order->external_order_id);
        $current = $this->statusTag($order->order_status);
        $previous = array_values(array_diff(array_map(fn (string $status) => $this->statusTag($status), Order::PROCESSING_STATUSES), [$current]));

        $this->mutate($store, 'mutation($id: ID!, $tags: [String!]!) { tagsRemove(id: $id, tags: $tags) { node { id } userErrors { field message } } }', [
            'id' => $orderId,
            'tags' => $previous,
        ], 'tagsRemove');
        $this->mutate($store, 'mutation($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { node { id } userErrors { field message } } }', [
            'id' => $orderId,
            'tags' => [$current],
        ], 'tagsAdd');
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $variantId = $variant->externalId;
        $locationId = $this->locationId($store);
        $item = $this->graphql($store, 'query($id: ID!, $location: ID!) {
            productVariant(id: $id) { inventoryItem { id tracked inventoryLevel(locationId: $location) { id } } }
        }', ['id' => ShopifyClient::gid('ProductVariant', $variantId), 'location' => $locationId])['productVariant']['inventoryItem'] ?? null;

        if (! $item) {
            throw new RuntimeException("Shopify has no variant {$variantId} any more; sync the store to refresh its products.");
        }

        // Shopify only keeps counts for tracked items stocked at the location.
        if (! $item['tracked']) {
            $this->mutate($store, 'mutation($id: ID!) { inventoryItemUpdate(id: $id, input: { tracked: true }) { inventoryItem { id } userErrors { field message } } }', [
                'id' => $item['id'],
            ], 'inventoryItemUpdate');
        }
        if (! $item['inventoryLevel']) {
            $this->mutate($store, 'mutation($item: ID!, $location: ID!) { inventoryActivate(inventoryItemId: $item, locationId: $location) { inventoryLevel { id } userErrors { field message } } }', [
                'item' => $item['id'],
                'location' => $locationId,
            ], 'inventoryActivate');
        }

        // Finovo is the stock authority, so the count is set without comparing it with Shopify's (changeFromQuantity: null).
        $this->mutate($store, 'mutation($input: InventorySetQuantitiesInput!, $key: String!) {
            inventorySetQuantities(input: $input) @idempotent(key: $key) { inventoryAdjustmentGroup { reason } userErrors { field message } }
        }', [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'quantities' => [['inventoryItemId' => $item['id'], 'locationId' => $locationId, 'quantity' => $quantity, 'changeFromQuantity' => null]],
            ],
            'key' => (string) Str::uuid(),
        ], 'inventorySetQuantities');
    }

    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $openOrders = collect($this->graphql($store, 'query($id: ID!) { order(id: $id) { fulfillmentOrders(first: 20) { nodes { id status } } } }', [
            'id' => ShopifyClient::gid('Order', (string) $order->external_order_id),
        ])['order']['fulfillmentOrders']['nodes'] ?? [])->whereIn('status', ['OPEN', 'IN_PROGRESS']);

        // Already fulfilled on Shopify (for example by hand): nothing left to send.
        if ($openOrders->isEmpty()) {
            return null;
        }

        $result = $this->mutate($store, 'mutation($fulfillment: FulfillmentInput!) {
            fulfillmentCreate(fulfillment: $fulfillment) { fulfillment { legacyResourceId } userErrors { field message } }
        }', ['fulfillment' => [
            'lineItemsByFulfillmentOrder' => $openOrders->map(fn (array $fulfillmentOrder) => ['fulfillmentOrderId' => $fulfillmentOrder['id']])->values()->all(),
            'notifyCustomer' => true,
            'trackingInfo' => array_filter([
                'company' => $shipment->carrier,
                'number' => $shipment->tracking_number,
                'url' => $shipment->trackingLink(),
            ]),
        ]], 'fulfillmentCreate');

        return isset($result['fulfillment']['legacyResourceId']) ? (string) $result['fulfillment']['legacyResourceId'] : null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        if (! $shipment->store_fulfillment_id) {
            return;
        }

        $this->mutate($store, 'mutation($event: FulfillmentEventInput!) {
            fulfillmentEventCreate(fulfillmentEvent: $event) { fulfillmentEvent { id } userErrors { field message } }
        }', ['event' => ['fulfillmentId' => ShopifyClient::gid('Fulfillment', $shipment->store_fulfillment_id), 'status' => 'DELIVERED']], 'fulfillmentEventCreate');
    }

    private function statusTag(string $status): string
    {
        return 'Finovo: '.$this->statusLabel($status);
    }

    /**
     * The shop's payment and cancellation state of an order.
     *
     * @return array{displayFinancialStatus?: string, cancelledAt?: string|null}
     */
    private function orderState(Store $store, string $orderId): array
    {
        $order = $this->graphql($store, 'query($id: ID!) { order(id: $id) { displayFinancialStatus cancelledAt } }', ['id' => $orderId])['order'] ?? null;

        if (! $order) {
            throw new RuntimeException('Shopify has no such order any more; it may have been deleted on the shop.');
        }

        return $order;
    }

    /**
     * The return is added to the order's note (Shopify's own returns and refunds move money, which Finovo leaves to the shop).
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $orderId = ShopifyClient::gid('Order', (string) $order->external_order_id);
        $note = $this->graphql($store, 'query($id: ID!) { order(id: $id) { note } }', ['id' => $orderId])['order']['note'] ?? '';

        $this->mutate($store, 'mutation($input: OrderInput!) { orderUpdate(input: $input) { order { id } userErrors { field message } } }', ['input' => [
            'id' => $orderId,
            'note' => trim($note."\n".$this->returnNote($return)),
        ]], 'orderUpdate');
    }

    /**
     * The shop location Finovo's stock is published to: the first active location, remembered on the store.
     */
    private function locationId(Store $store): string
    {
        if ($id = $store->settings['sync']['shopify_location_id'] ?? null) {
            return $id;
        }

        $location = collect($this->graphql($store, 'query { locations(first: 20) { nodes { id name isActive } } }')['locations']['nodes'] ?? [])
            ->firstWhere('isActive', true);

        if (! $location) {
            throw new RuntimeException('Shopify has no active location to keep stock at. Add one in Shopify → Settings → Locations.');
        }

        $settings = $store->settings ?? [];
        $settings['sync'] = [...($settings['sync'] ?? []), 'shopify_location_id' => $location['id'], 'shopify_location_name' => $location['name']];
        $store->forceFill(['settings' => $settings])->save();

        return $location['id'];
    }

    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        $existing = $this->subscriptions($store)->where('uri', $callbackUrl)->pluck('topic')->all();

        foreach (array_diff(self::WEBHOOK_TOPICS, $existing) as $topic) {
            $this->mutate($store, 'mutation($topic: WebhookSubscriptionTopic!, $subscription: WebhookSubscriptionInput!) {
                webhookSubscriptionCreate(topic: $topic, webhookSubscription: $subscription) { webhookSubscription { id } userErrors { field message } }
            }', ['topic' => $topic, 'subscription' => ['uri' => $callbackUrl, 'format' => 'JSON']], 'webhookSubscriptionCreate');
        }
    }

    public function removeWebhooks(Store $store, string $callbackUrl): void
    {
        foreach ($this->subscriptions($store)->where('uri', $callbackUrl) as $subscription) {
            $this->mutate($store, 'mutation($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }', [
                'id' => $subscription['id'],
            ], 'webhookSubscriptionDelete');
        }
    }

    /**
     * @return Collection<int, array{id: string, topic: string, uri: string}>
     */
    private function subscriptions(Store $store): Collection
    {
        $data = $this->graphql($store, 'query { webhookSubscriptions(first: 100) { nodes { id topic uri } } }');

        return collect($data['webhookSubscriptions']['nodes'] ?? []);
    }

    /**
     * A Finovo address as a Shopify MailingAddressInput.
     *
     * @param  array<string, string>  $address
     * @return array<string, string>|null
     */
    private function mailingAddress(array $address): ?array
    {
        if (blank($address['address1'] ?? null)) {
            return null;
        }

        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'],
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'provinceCode' => isset($address['province']) && strlen($address['province']) <= 3 ? strtoupper($address['province']) : null,
            'zip' => $address['zip'] ?? null,
            'countryCode' => isset($address['country_code']) ? strtoupper($address['country_code']) : null,
            'phone' => $address['phone'] ?? null,
        ], fn ($value) => filled($value));
    }

    /**
     * Run a mutation and return its payload; the shop's validation messages become one readable error.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function mutate(Store $store, string $mutation, array $variables, string $field, string $errorsField = 'userErrors'): array
    {
        $payload = $this->graphql($store, $mutation, $variables)[$field] ?? null;

        if ($payload === null) {
            throw new RuntimeException("Shopify did not answer the {$field} request.");
        }

        $errors = array_filter(array_column($payload[$errorsField] ?? [], 'message'));
        if ($errors) {
            throw new RuntimeException('Shopify said: '.implode(' ', $errors));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function graphql(Store $store, string $query, array $variables = []): array
    {
        $response = $this->send(fn () => $this->client->graphql($store, $query, $variables));
        $errors = $response->json('errors');

        if (! empty($errors)) {
            $message = is_array($errors) ? (string) ($errors[0]['message'] ?? json_encode($errors)) : (string) $errors;

            throw new RuntimeException(str_contains($message, 'access') || str_contains($message, 'scope')
                ? "Shopify refused: {$message} Connect the store again so Finovo gets write access."
                : "Shopify said: {$message}");
        }

        return (array) $response->json('data');
    }

    protected function isRateLimited(Response $response): bool
    {
        return ShopifyClient::isThrottled($response);
    }
}
