<?php

namespace App\Integrations\Push;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes products, orders and stock to a Magento 2 store through its REST API (integration access token).
 * Orders are placed the way Magento's own checkout does it: a cart with the products, the customer's address,
 * a shipping method and an offline payment method.
 *
 * Products: every Finovo variant is a simple Magento product ("Classic Tee - M"); a configurable product would
 * need attribute sets and options set up by the merchant first.
 */
class MagentoWriter extends PlatformWriter implements ProductWriter
{
    /** Magento's "Default" attribute set and catalog visibility "Catalog, Search". */
    private const DEFAULT_ATTRIBUTE_SET = 4;

    private const VISIBLE_EVERYWHERE = 4;

    /** Offline payment methods Finovo places orders with, in order of preference. */
    private const PAYMENT_METHODS = ['cashondelivery', 'checkmo', 'banktransfer', 'purchaseorder', 'free'];

    /** States of an order that has not been invoiced (paid) yet. */
    private const UNPAID_STATES = ['new', 'pending_payment', 'payment_review'];

    protected function platform(): string
    {
        return 'Magento';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $email = $this->requireEmail($order);
        $lines = $this->requireStoreLines($store, $order);
        $address = $this->address($this->deliveryAddress($order), $email);
        $cartId = (string) $this->call(fn () => $this->api($store)->post('guest-carts'));

        foreach ($lines as $line) {
            $this->call(fn () => $this->api($store)->post("guest-carts/{$cartId}/items", ['cartItem' => [
                'sku' => $this->skuOf($store, $line['item']),
                'qty' => $line['item']->quantity,
                'quote_id' => $cartId,
            ]]));
        }

        $method = collect($this->call(fn () => $this->api($store)->post("guest-carts/{$cartId}/estimate-shipping-methods", ['address' => $address])))
            ->first(fn (array $method) => ($method['available'] ?? true) && filled($method['method_code'] ?? null))
            ?? throw new RuntimeException('Magento offers no shipping method for this address, so the order cannot be placed there.');

        $payment = $this->call(fn () => $this->api($store)->post("guest-carts/{$cartId}/shipping-information", ['addressInformation' => [
            'shipping_address' => $address,
            'billing_address' => $order->billing_address ? $this->address($order->billing_address, $email) : $address,
            'shipping_carrier_code' => $method['carrier_code'],
            'shipping_method_code' => $method['method_code'],
        ]]));
        $available = array_column($payment['payment_methods'] ?? [], 'code');
        $paymentMethod = collect(self::PAYMENT_METHODS)->first(fn (string $code) => in_array($code, $available, true)) ?? ($available[0] ?? null)
            ?? throw new RuntimeException('Magento has no payment method enabled for this order. Enable an offline method such as Cash On Delivery.');

        $orderId = (string) $this->call(fn () => $this->api($store)->put("guest-carts/{$cartId}/order", ['paymentMethod' => ['method' => $paymentMethod]]));

        $this->comment($store, $orderId, "Created in Finovo as order {$order->order_number}.", visibleToCustomer: false);

        if ($order->payment_status === 'paid') {
            $this->invoice($store, $orderId);
        }

        return $orderId;
    }

    /**
     * Magento keeps the address of a placed order; Finovo records the payment (an invoice) and the note.
     */
    public function updateOrder(Store $store, Order $order): void
    {
        $current = $this->order($store, $order);

        if ($order->payment_status === 'paid' && in_array($current['state'] ?? null, self::UNPAID_STATES, true)) {
            $this->invoice($store, (string) $order->external_order_id);
        }

        if (filled($order->notes)) {
            $this->comment($store, (string) $order->external_order_id, "Note from Finovo: {$order->notes}", visibleToCustomer: false, status: $current['status'] ?? null);
        }
    }

    /**
     * Magento has no warehouse steps of its own; each step is added to the order's history as a comment.
     */
    public function updateStatus(Store $store, Order $order): void
    {
        $status = $this->order($store, $order)['status'] ?? null;

        $this->comment($store, (string) $order->external_order_id, 'Finovo: '.$this->statusLabel($order->order_status).'.', visibleToCustomer: false, status: $status);
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        if (($this->order($store, $order)['state'] ?? null) === 'canceled') {
            return;
        }

        if ($this->call(fn () => $this->api($store)->post("orders/{$order->external_order_id}/cancel")) !== true) {
            throw new RuntimeException('Magento refused to cancel the order. An invoiced or shipped order has to be refunded with a credit memo in Magento.');
        }
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $sku = $variant->sku ?? throw new RuntimeException('The Magento product has no SKU, so its stock cannot be set.');
        $item = $this->call(fn () => $this->api($store)->get('stockItems/'.rawurlencode($sku)));

        $this->call(fn () => $this->api($store)->put('products/'.rawurlencode($sku).'/stockItems/'.$item['item_id'], ['stockItem' => [
            'qty' => $quantity,
            'is_in_stock' => $quantity > 0,
            'manage_stock' => true,
        ]]));
    }

    /**
     * A Magento shipment with the tracking; Magento emails the customer and completes an invoiced order.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $current = $this->order($store, $order);
        $unshipped = array_filter($current['items'] ?? [], fn (array $item) => empty($item['parent_item_id']) && (float) ($item['qty_ordered'] ?? 0) > (float) ($item['qty_shipped'] ?? 0));

        if ($unshipped === []) {
            return null;
        }

        $shipmentId = $this->call(fn () => $this->api($store)->post("order/{$order->external_order_id}/ship", [
            'notify' => true,
            'tracks' => [array_filter([
                'track_number' => $shipment->tracking_number,
                'title' => $shipment->carrier ?: 'Courier',
                'carrier_code' => 'custom',
            ])],
        ]));

        return is_scalar($shipmentId) ? (string) $shipmentId : null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        $status = $this->order($store, $order)['status'] ?? null;

        $this->comment($store, (string) $order->external_order_id, 'Delivered to the customer.', visibleToCustomer: true, status: $status);
    }

    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $status = $this->order($store, $order)['status'] ?? null;

        $this->comment($store, (string) $order->external_order_id, $this->returnNote($return).' Refund the customer with a credit memo in Magento if needed.', visibleToCustomer: false, status: $status);
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $single = $product->variants->count() === 1;
        $ids = [];

        foreach ($product->variants as $variant) {
            $currentSku = SkuMapping::where('store_id', $store->id)->where('product_variant_id', $variant->id)->value('external_sku');
            $body = ['product' => [
                'sku' => $variant->sku,
                'name' => $single ? $product->name : trim($product->name.' - '.($variant->option_value ?: $variant->sku)),
                'attribute_set_id' => self::DEFAULT_ATTRIBUTE_SET,
                'price' => (float) $variant->price,
                'status' => $product->is_active && $variant->is_active ? 1 : 2,
                'visibility' => self::VISIBLE_EVERYWHERE,
                'type_id' => 'simple',
                'weight' => 1,
                'custom_attributes' => array_values(array_filter([
                    filled($product->description) ? ['attribute_code' => 'description', 'value' => (string) $product->description] : null,
                    (float) $variant->cost_price > 0 ? ['attribute_code' => 'cost', 'value' => (string) $variant->cost_price] : null,
                ])),
            ]];

            $saved = $currentSku
                ? $this->call(fn () => $this->api($store)->put('products/'.rawurlencode($currentSku), $body))
                : $this->call(fn () => $this->api($store)->post('products', $body));
            $ids[$variant->id] = (string) $saved['id'];
        }

        return new PushedProduct($ids[$product->variants->first()->id], $ids);
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $productId = StoreProductLink::where('store_id', $store->id)->where('external_id', $externalId)->value('product_id');
        $skus = SkuMapping::where('store_id', $store->id)
            ->whereIn('product_variant_id', ProductVariant::where('product_id', $productId)->pluck('id'))
            ->pluck('external_sku');

        foreach ($skus as $sku) {
            $this->send(fn () => $this->api($store)->delete('products/'.rawurlencode((string) $sku)), [404]);
        }
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) $store->external_reference, '/').'/rest/V1/')
            ->withToken((string) $store->credential('access_token'));
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Store $store, Order $order): array
    {
        return (array) $this->call(fn () => $this->api($store)->get("orders/{$order->external_order_id}"));
    }

    /**
     * Record the payment: an invoice without capturing money (the payment was taken outside Magento).
     */
    private function invoice(Store $store, string $orderId): void
    {
        $this->call(fn () => $this->api($store)->post("order/{$orderId}/invoice", ['capture' => false, 'notify' => false]));
    }

    /**
     * Add a line to the order's history; the order keeps its status (Magento needs it with every comment).
     */
    private function comment(Store $store, string $orderId, string $comment, bool $visibleToCustomer, ?string $status = null): void
    {
        $this->call(fn () => $this->api($store)->post("orders/{$orderId}/comments", ['statusHistory' => array_filter([
            'comment' => $comment,
            'is_customer_notified' => 0,
            'is_visible_on_front' => $visibleToCustomer ? 1 : 0,
            'parent_id' => (int) $orderId,
            'status' => $status,
        ], fn ($value) => $value !== null)]));
    }

    /**
     * The SKU Magento knows the line's product by.
     */
    private function skuOf(Store $store, OrderItem $item): string
    {
        $sku = SkuMapping::where('store_id', $store->id)->where('product_variant_id', $item->product_variant_id)->value('external_sku');

        return (string) ($sku ?: ($item->variant?->sku ?: $item->external_sku));
    }

    /**
     * A Finovo address as a Magento address.
     *
     * @param  array<string, string>  $address
     * @return array<string, mixed>
     */
    private function address(array $address, string $email): array
    {
        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return array_filter([
            'firstname' => $firstName ?: $lastName,
            'lastname' => $lastName,
            'company' => $address['company'] ?? null,
            'street' => array_values(array_filter([$address['address1'] ?? null, $address['address2'] ?? null])),
            'city' => $address['city'] ?? null,
            'region' => $address['province'] ?? null,
            'postcode' => $address['zip'] ?? null,
            'country_id' => $address['country_code'] ?? null,
            'telephone' => $address['phone'] ?? null,
            'email' => $email,
        ], fn ($value) => filled($value));
    }

    /**
     * Send a request and return its JSON body (Magento answers some calls with a bare value: an ID or true).
     *
     * @param  Closure(): Response  $request
     *
     * @throws RuntimeException
     */
    private function call(Closure $request): mixed
    {
        return $this->send($request)->json();
    }
}
