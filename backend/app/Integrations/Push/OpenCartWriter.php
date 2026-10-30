<?php

namespace App\Integrations\Push;

use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Push\Concerns\WritesShopDatabase;
use App\Integrations\Push\OpenCart\OpenCartDatabaseShop;
use App\Integrations\Push\OpenCart\OpenCartModuleShop;
use App\Integrations\Push\OpenCart\OpenCartShop;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use Closure;
use RuntimeException;

/**
 * Writes orders, statuses and stock to an OpenCart 3 shop, through the Finovo module or, for stores added with
 * database details, in the shop database. OpenCart shows an order's progress as its status with a comment in
 * the order history, so every Finovo step is one history line.
 *
 * Products: a product with variants gets one select option ("Size") whose values are the variants, as
 * OpenCart keeps stock per option value. The Finovo module also notifies Finovo of new orders and product
 * changes at once.
 */
class OpenCartWriter extends PlatformWriter implements ProductWriter, WebhookSubscriber
{
    use WritesShopDatabase;

    public function __construct(
        protected ExternalDatabaseConnector $database
    ) {}

    protected function platform(): string
    {
        return 'OpenCart';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $shop = $this->shop($store);
        $delivery = $this->deliveryAddress($order);
        [$firstName, $lastName] = $this->splitName($order->customer_name);
        $subtotal = 0.0;

        $products = array_map(function (array $line) use (&$subtotal) {
            [$productId, $optionValueId] = $this->productAndOption($line['variant']);
            $item = $line['item'];
            $total = round($item->quantity * (float) $item->unit_price, 2);
            $subtotal += $total;

            return [
                'product_id' => $productId,
                'product_option_value_id' => $optionValueId,
                'name' => $item->product_name,
                'model' => (string) ($item->external_sku ?: ''),
                'quantity' => $item->quantity,
                'price' => (float) $item->unit_price,
                'total' => $total,
                'tax' => 0,
            ];
        }, $this->storeLines($store, $order));

        $orderId = $shop->createOrder([
            'customer' => ['firstname' => $firstName ?: $lastName, 'lastname' => $lastName, 'email' => (string) $order->customer_email, 'telephone' => (string) ($order->customer_phone ?? '')],
            'payment' => $this->address($order->billing_address ?: $delivery),
            'shipping' => $this->address($delivery),
            'payment_method' => $order->payment_method ?: 'Finovo',
            'comment' => (string) $order->notes,
            'currency_code' => $order->currency,
            'products' => $products,
            'totals' => array_values(array_filter([
                ['code' => 'sub_total', 'title' => 'Sub-Total', 'value' => $subtotal, 'sort_order' => 1],
                (float) $order->shipping_amount > 0 ? ['code' => 'shipping', 'title' => 'Shipping', 'value' => (float) $order->shipping_amount, 'sort_order' => 3] : null,
                (float) $order->discount_amount > 0 ? ['code' => 'coupon', 'title' => 'Discount', 'value' => -(float) $order->discount_amount, 'sort_order' => 4] : null,
                (float) $order->tax_amount > 0 ? ['code' => 'tax', 'title' => 'Tax', 'value' => (float) $order->tax_amount, 'sort_order' => 5] : null,
                ['code' => 'total', 'title' => 'Total', 'value' => (float) $order->total_amount, 'sort_order' => 9],
            ])),
            'total' => (float) $order->total_amount,
        ], $this->status($shop, $order->payment_status === 'paid' ? 'paid' : 'unpaid'), "Created in Finovo as order {$order->order_number}.");

        return (string) $orderId;
    }

    /**
     * A payment recorded in Finovo moves a pending order on to the shop's processing status.
     */
    public function updateOrder(Store $store, Order $order): void
    {
        $shop = $this->shop($store);
        $pending = $this->statusFor($shop->orderStatuses(), 'unpaid');

        if ($order->payment_status === 'paid' && $shop->orderStatusId((int) $order->external_order_id) === $pending) {
            $shop->addOrderHistory((int) $order->external_order_id, $this->status($shop, 'paid'), 'Payment received (recorded in Finovo).', false);
        }
    }

    public function updateStatus(Store $store, Order $order): void
    {
        $shop = $this->shop($store);

        $shop->addOrderHistory((int) $order->external_order_id, $this->status($shop, 'processing'), 'Finovo: '.$this->statusLabel($order->order_status).'.', false);
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        $shop = $this->shop($store);
        $cancelled = $this->status($shop, 'cancelled');

        if ($shop->orderStatusId((int) $order->external_order_id) !== $cancelled) {
            $shop->addOrderHistory((int) $order->external_order_id, $cancelled, 'Cancelled in Finovo.', false);
        }
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        [$productId, $optionValueId] = $this->productAndOption($variant->externalId);

        $this->shop($store)->setStock($productId, $optionValueId, $quantity);
    }

    /**
     * The shop's "Shipped" status with the courier and tracking in the comment; the customer is emailed.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $shop = $this->shop($store);

        $shop->addOrderHistory((int) $order->external_order_id, $this->status($shop, 'shipped'), $this->trackingNote($shipment), true);

        return null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        $shop = $this->shop($store);

        $shop->addOrderHistory((int) $order->external_order_id, $this->status($shop, 'delivered'), 'Delivered to the customer.', true);
    }

    /**
     * A history line with the order's current status; a refund (which moves money) is made in OpenCart itself.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $shop = $this->shop($store);

        $shop->addOrderHistory((int) $order->external_order_id, $shop->orderStatusId((int) $order->external_order_id), $this->returnNote($return), false);
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $options = $this->options($product);
        $variable = $options['names'] !== [];
        /** @var ProductVariant $first */
        $first = $product->variants->first();

        $saved = $this->shop($store)->saveProduct([
            'product_id' => $externalId ? (int) $externalId : null,
            'name' => $product->name,
            'description' => (string) $product->description,
            'model' => (string) ($variable ? ($product->sku ?: $first->sku) : $first->sku),
            'sku' => (string) ($variable ? ($product->sku ?: '') : $first->sku),
            'price' => (float) $first->price,
            'status' => $product->is_active ? 1 : 0,
            'option_name' => $variable ? implode(' / ', $options['names']) : null,
            'values' => $variable ? $product->variants->map(fn (ProductVariant $variant) => [
                'key' => (string) $variant->id,
                'name' => implode(' / ', $options['values'][$variant->id] ?? [$variant->sku]),
                'price_diff' => round((float) $variant->price - (float) $first->price, 4),
                'product_option_value_id' => $this->productAndOption($this->variantExternalId($store, $variant->id))[1],
            ])->values()->all() : [],
        ]);
        $productId = (string) $saved['product_id'];

        return new PushedProduct($productId, $variable
            ? collect($saved['option_values'])->mapWithKeys(fn (int $optionValueId, int|string $variantId) => [(int) $variantId => "{$productId}-{$optionValueId}"])->all()
            : [$first->id => $productId]);
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $this->shop($store)->deleteProduct((int) $externalId);
    }

    /**
     * The Finovo module sends notifications to the address Finovo gave it when the shop connected.
     */
    public function registerWebhooks(Store $store, string $callbackUrl): void
    {
        if (blank($store->credential('api_secret'))) {
            throw new AutomaticImportUnavailable('This OpenCart store is connected with database details, so Finovo reads its new orders every minute. Connect it with the Finovo module for instant import.');
        }

        if (($store->settings['module_webhook_url'] ?? null) !== $callbackUrl) {
            throw new AutomaticImportUnavailable('Install the latest Finovo module in OpenCart (Stores → Module), then open Extensions → Modules → Finovo and click Connect again, so the shop notifies Finovo of new orders at once.');
        }
    }

    /**
     * The module stops notifying Finovo when it is uninstalled from the shop.
     */
    public function removeWebhooks(Store $store, string $callbackUrl): void {}

    private function shop(Store $store): OpenCartShop
    {
        return filled($store->credential('api_secret'))
            ? new OpenCartModuleShop($store, fn (Closure $request, array $accepted = []) => $this->send($request, $accepted))
            : new OpenCartDatabaseShop($this->database->connection($store), $store->credential('table_prefix') ?? 'oc_', $store->credential('site_url'));
    }

    /**
     * @throws RuntimeException when the shop has no status for the role
     */
    private function status(OpenCartShop $shop, string $role): int
    {
        return $this->statusFor($shop->orderStatuses(), $role)
            ?? throw new RuntimeException('OpenCart has no order status for "'.str_replace('_', ' ', $role).'". Add one in System → Localisation → Order Statuses (e.g. Processing, Shipped, Complete, Canceled).');
    }

    /**
     * "12" (a product) or "12-34" (product 12, option value 34), as Finovo stores OpenCart variant IDs.
     *
     * @return array{0: int, 1: int|null}
     */
    private function productAndOption(?string $externalId): array
    {
        [$productId, $optionValueId] = array_pad(explode('-', (string) $externalId, 2), 2, null);

        return [(int) $productId, $optionValueId ? (int) $optionValueId : null];
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, string|null>
     */
    private function address(array $address): array
    {
        [$firstName, $lastName] = $this->splitName($address['name'] ?? null);

        return [
            'firstname' => $firstName ?: $lastName,
            'lastname' => $lastName,
            'company' => $address['company'] ?? null,
            'address_1' => $address['address1'] ?? null,
            'address_2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'postcode' => $address['zip'] ?? null,
            'zone' => $address['province'] ?? null,
            'country_code' => $address['country_code'] ?? null,
        ];
    }
}
