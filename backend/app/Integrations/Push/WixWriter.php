<?php

namespace App\Integrations\Push;

use App\Integrations\Authorization\WixAuthorization;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes products (Stores catalog V3), orders (eCommerce Orders API) and stock (catalog V3 inventory) to a Wix site.
 * New orders and product changes reach Finovo through the Finovo app's notifications (see StoreWebhookService).
 */
class WixWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    private const API = 'https://www.wixapis.com/';

    /** The Wix Stores app, owner of the catalog that order lines refer to. */
    private const STORES_APP_ID = '215238eb-22a5-4c36-9e7b-e7c08025e04e';

    public function __construct(
        protected WixAuthorization $authorization
    ) {}

    protected function platform(): string
    {
        return 'Wix';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $email = $this->requireEmail($order);
        $amount = fn ($value) => ['amount' => $this->amount($value)];
        $delivery = $this->deliveryAddress($order);
        $subtotal = $order->items()->get()->sum(fn ($item) => $item->quantity * (float) $item->unit_price);

        $created = $this->call(fn () => $this->api($store)->post('ecom/v1/orders', ['order' => array_filter([
            'channelInfo' => ['type' => 'OTHER_PLATFORM', 'externalOrderId' => $order->order_number],
            'status' => 'APPROVED',
            'paymentStatus' => $order->payment_status === 'paid' ? 'PAID' : 'NOT_PAID',
            'currency' => $order->currency,
            'taxIncludedInPrices' => false,
            'buyerInfo' => ['email' => $email],
            'buyerNote' => $order->notes ?: null,
            'billingInfo' => $this->addressBlock($order->billing_address ?: $delivery),
            'shippingInfo' => [
                'title' => 'Shipping',
                'logistics' => ['shippingDestination' => $this->addressBlock($delivery)],
                'cost' => ['price' => $amount($order->shipping_amount)],
            ],
            'lineItems' => array_map(fn (array $line) => $this->lineItem($line), $this->storeLines($store, $order)),
            'priceSummary' => [
                'subtotal' => $amount($subtotal),
                'shipping' => $amount($order->shipping_amount),
                'tax' => $amount($order->tax_amount),
                'discount' => $amount($order->discount_amount),
                'total' => $amount($order->total_amount),
            ],
        ], fn ($value) => $value !== null)]));

        return (string) ($created['order']['id'] ?? throw new RuntimeException('Wix did not return the new order.'));
    }

    /**
     * Wix keeps the buyer's details and payment of an order as placed; they are changed in the Wix dashboard.
     */
    public function updateOrder(Store $store, Order $order): void {}

    /**
     * Wix orders have no warehouse steps between approved and fulfilled, so there is nothing to show yet.
     */
    public function updateStatus(Store $store, Order $order): void {}

    public function cancelOrder(Store $store, Order $order): void
    {
        if (($this->order($store, $order)['status'] ?? null) === 'CANCELED') {
            return;
        }

        $this->call(fn () => $this->api($store)->post("ecom/v1/orders/{$order->external_order_id}/cancel", [
            'sendOrderCanceledEmail' => false,
            // Finovo sets the site's stock itself.
            'restockAllItems' => false,
        ]));
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $item = $this->call(fn () => $this->api($store)->post('stores/v3/inventory-items/search', ['search' => [
            'filter' => ['variantId' => ['$eq' => $variant->externalId]],
            'cursorPaging' => ['limit' => 1],
        ]]))['inventoryItems'][0] ?? throw new RuntimeException('Wix has no inventory for this variant any more; sync the store to refresh its products.');

        $this->call(fn () => $this->api($store)->patch("stores/v3/inventory-items/{$item['id']}", [
            'inventoryItem' => ['id' => $item['id'], 'revision' => (string) $item['revision'], 'quantity' => $quantity],
            'reason' => 'MANUAL',
        ]));
    }

    /**
     * A fulfillment of the order's unfulfilled lines with the tracking; Wix emails the buyer.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $current = $this->order($store, $order);

        if (($current['fulfillmentStatus'] ?? null) === 'FULFILLED') {
            return null;
        }

        $created = $this->call(fn () => $this->api($store)->post("ecom/v1/fulfillments/orders/{$order->external_order_id}/create-fulfillment", ['fulfillment' => [
            'lineItems' => array_map(fn (array $line) => ['id' => $line['id']], $current['lineItems'] ?? []),
            'trackingInfo' => array_filter([
                'trackingNumber' => $shipment->tracking_number,
                'shippingProvider' => $shipment->carrier ?: 'Courier',
                'trackingLink' => $shipment->trackingLink(),
            ]),
        ]]));

        return $created['fulfillmentId'] ?? null;
    }

    /**
     * Wix marks an order fulfilled when it ships; it has no separate delivered state.
     */
    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void {}

    /**
     * Wix offers no merchant note on an order through its API, so a return is recorded in Finovo only.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void {}

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $current = $externalId
            ? (array) ($this->call(fn () => $this->api($store)->get("stores/v3/products/{$externalId}", ['fields' => 'VARIANT_OPTION_CHOICE_NAMES']))['product'] ?? [])
            : [];
        $existing = collect($current['variantsInfo']['variants'] ?? [])->keyBy('id');
        $productOptions = $this->productOptions($options, $current['options'] ?? []);
        $renderTypes = array_column($productOptions, 'optionRenderType', 'name');

        $fields = [
            'name' => $product->name,
            'visible' => (bool) $product->is_active,
            'plainDescription' => filled($product->description) ? '<p>'.e((string) $product->description).'</p>' : null,
            'options' => $productOptions,
            'variantsInfo' => ['variants' => $product->variants->map(function (ProductVariant $variant) use ($store, $options, $existing, $renderTypes) {
                $id = $this->variantExternalId($store, $variant->id);

                return array_filter([
                    // A variant the site already has keeps its ID, so its stock and orders stay linked.
                    'id' => $id && $existing->has($id) ? $id : null,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'visible' => (bool) $variant->is_active,
                    'price' => ['actualPrice' => ['amount' => $this->amount($variant->price)]],
                    'revenueDetails' => (float) $variant->cost_price > 0 ? ['cost' => ['amount' => $this->amount($variant->cost_price)]] : null,
                    'choices' => array_map(fn (string $name, int $index) => ['optionChoiceNames' => [
                        'optionName' => $name,
                        'choiceName' => $options['values'][$variant->id][$index] ?? '',
                        'renderType' => $renderTypes[$name] ?? 'TEXT_CHOICES',
                    ]], $options['names'], array_keys($options['names'])),
                ], fn ($value) => $value !== null);
            })->values()->all()],
        ];

        if ($externalId) {
            $saved = $this->call(fn () => $this->api($store)->patch("stores/v3/products/{$externalId}", ['product' => array_filter([
                'id' => $externalId,
                'revision' => (string) ($current['revision'] ?? ''),
                ...$fields,
            ], fn ($value) => $value !== null)]))['product'];
        } else {
            $image = $this->publicImageUrl($product);
            $saved = $this->call(fn () => $this->api($store)->post('stores/v3/products', ['product' => array_filter([
                ...$fields,
                'productType' => 'PHYSICAL',
                'physicalProperties' => (object) [],
                'media' => $image ? ['main' => ['url' => $image]] : null,
            ], fn ($value) => $value !== null)]))['product'];
        }

        // Variants come back in the order they were sent.
        $variantIds = array_column($saved['variantsInfo']['variants'] ?? [], 'id');

        return new PushedProduct((string) $saved['id'], $product->variants->values()
            ->mapWithKeys(fn (ProductVariant $variant, int $index) => isset($variantIds[$index]) ? [$variant->id => (string) $variantIds[$index]] : [])
            ->all());
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->send(fn () => $this->api($store)->delete("stores/v3/products/{$externalId}"), [404]);
    }

    /**
     * Wix sends notifications to the Finovo app (subscribed once in the Wix app dashboard), not per site; Finovo
     * only needs the app's public key to check them.
     */
    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        if (blank($store->credential('instance_id'))) {
            throw new AutomaticImportUnavailable('This Wix site is connected with an API key. Connect it through the Finovo app for instant import; until then Finovo reads new orders every minute.');
        }

        if (blank(config('services.wix.webhook_public_key'))) {
            throw new RuntimeException('Set WIX_WEBHOOK_PUBLIC_KEY in Finovo and subscribe the Finovo app to the order and product webhooks in the Wix app dashboard with the address '.rtrim((string) config('services.webhooks.base_url'), '/').route('webhooks.wix', [], false).'.');
        }
    }

    /**
     * The app's subscriptions belong to the app, not to one site.
     */
    public function removeWebhooks(Store $store, string $callbackUrl): void {}

    /**
     * The product's options with every choice its variants use. Options and choices the site already has keep
     * their IDs (Wix links variants to them); new ones are added.
     *
     * @param  array{names: list<string>, values: array<int, list<string>>}  $options
     * @param  list<array<string, mixed>>  $current
     * @return list<array<string, mixed>>
     */
    private function productOptions(array $options, array $current): array
    {
        $current = collect($current)->keyBy(fn (array $option) => strtolower((string) $option['name']));

        return array_map(function (string $name, int $index) use ($options, $current) {
            $existing = $current->get(strtolower($name));
            $choices = collect($existing['choicesSettings']['choices'] ?? [])
                ->map(fn (array $choice) => array_filter([
                    'choiceId' => $choice['choiceId'] ?? null,
                    'name' => $choice['name'],
                    'choiceType' => $choice['choiceType'] ?? 'CHOICE_TEXT',
                ]))
                ->keyBy(fn (array $choice) => strtolower((string) $choice['name']));

            foreach (array_unique(array_column($options['values'], $index)) as $value) {
                $choices->put(strtolower((string) $value), $choices->get(strtolower((string) $value)) ?? ['name' => (string) $value, 'choiceType' => 'CHOICE_TEXT']);
            }

            return array_filter([
                'id' => $existing['id'] ?? null,
                'name' => $name,
                'optionRenderType' => $existing['optionRenderType'] ?? 'TEXT_CHOICES',
                'choicesSettings' => ['choices' => $choices->values()->all()],
            ]);
        }, $options['names'], array_keys($options['names']));
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(30)->baseUrl(self::API)->withHeaders($this->authorization->headersFor($store));
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Store $store, Order $order): array
    {
        return (array) ($this->call(fn () => $this->api($store)->get("ecom/v1/orders/{$order->external_order_id}"))['order'] ?? []);
    }

    /**
     * An order line: the site's own product when it is linked, otherwise a custom item with its name and price.
     *
     * @param  array{item: OrderItem, variant: string|null, product: string|null}  $line
     * @return array<string, mixed>
     */
    private function lineItem(array $line): array
    {
        $item = $line['item'];
        $total = $this->amount($item->quantity * (float) $item->unit_price);

        return array_filter([
            'quantity' => $item->quantity,
            'productName' => ['original' => $item->product_name],
            'catalogReference' => $line['variant'] && $line['product']
                ? ['catalogItemId' => $line['product'], 'appId' => self::STORES_APP_ID, 'options' => ['variantId' => $line['variant']]]
                : null,
            'itemType' => ['preset' => 'PHYSICAL'],
            'physicalProperties' => array_filter(['sku' => $item->external_sku ?: null, 'shippable' => true]),
            'price' => ['amount' => $this->amount($item->unit_price)],
            'totalPriceBeforeTax' => ['amount' => $total],
            'totalPriceAfterTax' => ['amount' => $total],
            'taxDetails' => ['taxRate' => '0', 'totalTax' => ['amount' => '0.00']],
        ], fn ($value) => $value !== null);
    }

    /**
     * A Finovo address as a Wix address with contact details.
     *
     * @param  array<string, string>  $address
     * @return array{address: array<string, string>, contactDetails: array<string, string>}
     */
    private function addressBlock(array $address): array
    {
        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return [
            'address' => array_filter([
                'country' => $address['country_code'] ?? null,
                'city' => $address['city'] ?? null,
                'addressLine1' => $address['address1'] ?? null,
                'addressLine2' => $address['address2'] ?? null,
                'postalCode' => $address['zip'] ?? null,
                'subdivision' => $address['province'] ?? null,
            ], fn ($value) => filled($value)),
            'contactDetails' => array_filter([
                'firstName' => $firstName ?: $lastName,
                'lastName' => $lastName,
                'phone' => $address['phone'] ?? null,
                'company' => $address['company'] ?? null,
            ], fn ($value) => filled($value)),
        ];
    }

    /**
     * @param  Closure(): Response  $request
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private function call(Closure $request): array
    {
        return (array) $this->send($request)->json();
    }
}
