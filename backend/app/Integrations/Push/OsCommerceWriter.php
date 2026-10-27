<?php

namespace App\Integrations\Push;

use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Push\Concerns\WritesShopDatabase;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Writes orders, statuses and stock to an osCommerce shop database (osCommerce 2.x and 4.x share these tables).
 * osCommerce shows an order's progress as its status with a comment in the status history, so every Finovo
 * step is one history line. Stock is not subtracted when an order is placed here; Finovo sets it itself.
 *
 * Products: every Finovo variant is its own osCommerce product ("Classic Tee - M"), as Finovo imports them.
 * osCommerce is read from its database, so new orders arrive with the regular (every minute) import.
 */
class OsCommerceWriter extends PlatformWriter implements ProductWriter
{
    use WritesShopDatabase;

    /** @var array<int, Connection> per store, for the life of the writer */
    private array $connections = [];

    public function __construct(
        protected ExternalDatabaseConnector $database
    ) {}

    protected function platform(): string
    {
        return 'osCommerce';
    }

    public function createOrder(Store $store, Order $order): string
    {
        $db = $this->db($store);
        $table = $this->tables($store);
        $delivery = $this->addressColumns('delivery', $this->deliveryAddress($order));
        $billing = $this->addressColumns('billing', $order->billing_address ?: $this->deliveryAddress($order));
        $customer = $this->addressColumns('customers', $this->deliveryAddress($order));
        $status = $this->status($store, $order->payment_status === 'paid' ? 'paid' : 'unpaid');
        $now = now()->format('Y-m-d H:i:s');

        return (string) $this->run(fn () => $db->transaction(function () use ($store, $order, $db, $table, $delivery, $billing, $customer, $status, $now) {
            $orderId = $this->insertRow($db, $table['orders'], [
                ...$customer,
                ...$delivery,
                ...$billing,
                'customers_id' => 0,
                'customers_name' => $order->customer_name,
                'customers_email_address' => (string) $order->customer_email,
                'customers_telephone' => (string) ($order->customer_phone ?? ''),
                'payment_method' => $order->payment_method ?: 'Finovo',
                'date_purchased' => $now,
                'last_modified' => $now,
                'orders_status' => $status,
                'currency' => $order->currency,
                'currency_value' => 1,
            ]);
            $subtotal = 0.0;

            foreach ($this->storeLines($store, $order) as $line) {
                $item = $line['item'];
                $subtotal += $item->quantity * (float) $item->unit_price;

                $this->insertRow($db, $table['orders_products'], [
                    'orders_id' => $orderId,
                    'products_id' => (int) $line['variant'],
                    'products_model' => (string) ($item->external_sku ?: ''),
                    'products_name' => $item->product_name,
                    'products_price' => (float) $item->unit_price,
                    'final_price' => (float) $item->unit_price,
                    'products_tax' => 0,
                    'products_quantity' => $item->quantity,
                ]);
            }

            $totals = array_filter([
                ['ot_subtotal', 'Sub-Total:', $subtotal, 1],
                (float) $order->shipping_amount > 0 ? ['ot_shipping', 'Shipping:', (float) $order->shipping_amount, 2] : null,
                (float) $order->tax_amount > 0 ? ['ot_tax', 'Tax:', (float) $order->tax_amount, 3] : null,
                ['ot_total', 'Total:', (float) $order->total_amount, 4],
            ]);

            foreach ($totals as [$class, $title, $value, $sortOrder]) {
                $this->insertRow($db, $table['orders_total'], [
                    'orders_id' => $orderId,
                    'title' => $title,
                    'text' => $order->currency.' '.$this->amount($value),
                    'value' => $value,
                    'class' => $class,
                    'sort_order' => $sortOrder,
                ]);
            }

            $this->history($store, $orderId, $status, "Created in Finovo as order {$order->order_number}.");

            return $orderId;
        }));
    }

    /**
     * A payment recorded in Finovo moves a pending order on to the shop's processing status.
     */
    public function updateOrder(Store $store, Order $order): void
    {
        $pending = $this->statusFor($this->statuses($store), 'unpaid');

        if ($order->payment_status === 'paid' && $this->currentStatus($store, $order) === $pending) {
            $this->history($store, (int) $order->external_order_id, $this->status($store, 'paid'), 'Payment received (recorded in Finovo).');
        }
    }

    public function updateStatus(Store $store, Order $order): void
    {
        $this->history($store, (int) $order->external_order_id, $this->status($store, 'processing'), 'Finovo: '.$this->statusLabel($order->order_status).'.');
    }

    public function cancelOrder(Store $store, Order $order): void
    {
        $cancelled = $this->status($store, 'cancelled');

        if ($this->currentStatus($store, $order) !== $cancelled) {
            $this->history($store, (int) $order->external_order_id, $cancelled, 'Cancelled in Finovo.');
        }
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $this->run(fn () => $this->db($store)->table($this->tables($store)['products'])->where('products_id', (int) $variant->externalId)->update(['products_quantity' => $quantity]));
    }

    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $this->history($store, (int) $order->external_order_id, $this->status($store, 'shipped'), $this->trackingNote($shipment));

        return null;
    }

    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void
    {
        $this->history($store, (int) $order->external_order_id, $this->status($store, 'delivered'), 'Delivered to the customer.');
    }

    /**
     * A history line with the order's current status; a refund (which moves money) is made in osCommerce itself.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void
    {
        $this->history($store, (int) $order->external_order_id, $this->currentStatus($store, $order), $this->returnNote($return));
    }

    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $db = $this->db($store);
        $prefix = (string) ($store->credential('table_prefix') ?? '');
        $single = $product->variants->count() === 1;
        $now = now()->format('Y-m-d H:i:s');
        $ids = $this->run(fn () => $db->transaction(function () use ($store, $product, $db, $prefix, $single, $now) {
            $ids = [];
            $languages = $db->table($prefix.'languages')->pluck('languages_id')->all() ?: [1];

            foreach ($product->variants as $variant) {
                $name = $single ? $product->name : trim($product->name.' - '.($variant->option_value ?: $variant->sku));
                $fields = [
                    'products_model' => (string) $variant->sku,
                    'products_price' => (float) $variant->price,
                    'products_status' => $product->is_active && $variant->is_active ? 1 : 0,
                    'products_last_modified' => $now,
                ];
                $id = (int) $this->variantExternalId($store, $variant->id);

                if ($id && $db->table($prefix.'products')->where('products_id', $id)->exists()) {
                    $db->table($prefix.'products')->where('products_id', $id)->update($fields);
                    $db->table($prefix.'products_description')->where('products_id', $id)->update(['products_name' => $name, 'products_description' => (string) $product->description]);
                } else {
                    $id = $this->insertRow($db, $prefix.'products', [...$fields, 'products_quantity' => 0, 'products_date_added' => $now]);

                    foreach ($languages as $languageId) {
                        $this->insertRow($db, $prefix.'products_description', [
                            'products_id' => $id,
                            'language_id' => (int) $languageId,
                            'products_name' => $name,
                            'products_description' => (string) $product->description,
                        ]);
                    }
                }

                $ids[$variant->id] = (string) $id;
            }

            return $ids;
        }));

        return new PushedProduct($ids[$product->variants->first()->id], $ids);
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $productId = StoreProductLink::where('store_id', $store->id)->where('external_id', $externalId)->value('product_id');
        $ids = SkuMapping::where('store_id', $store->id)
            ->whereIn('product_variant_id', ProductVariant::where('product_id', $productId)->pluck('id'))
            ->pluck('external_id')
            ->push($externalId)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();
        $db = $this->db($store);
        $prefix = (string) ($store->credential('table_prefix') ?? '');

        $this->run(fn () => $db->transaction(function () use ($db, $prefix, $ids) {
            foreach (['products', 'products_description', 'products_to_categories', 'products_attributes'] as $table) {
                if ($db->getSchemaBuilder()->hasTable($prefix.$table)) {
                    $db->table($prefix.$table)->whereIn('products_id', $ids)->delete();
                }
            }
        }));
    }

    /**
     * Move the order to a status with a comment in its status history.
     */
    private function history(Store $store, int $orderId, int $statusId, string $comment): void
    {
        $db = $this->db($store);
        $table = $this->tables($store);
        $now = now()->format('Y-m-d H:i:s');

        $this->run(fn () => $db->transaction(function () use ($db, $table, $orderId, $statusId, $comment, $now) {
            $db->table($table['orders'])->where('orders_id', $orderId)->update(['orders_status' => $statusId, 'last_modified' => $now]);
            $this->insertRow($db, $table['orders_status_history'], [
                'orders_id' => $orderId,
                'orders_status_id' => $statusId,
                'date_added' => $now,
                // Emails are sent by osCommerce itself; a change made in its database cannot send one.
                'customer_notified' => 0,
                'comments' => $comment,
            ]);
        }));
    }

    private function currentStatus(Store $store, Order $order): int
    {
        return (int) $this->run(fn () => $this->db($store)->table($this->tables($store)['orders'])->where('orders_id', (int) $order->external_order_id)->value('orders_status'));
    }

    /**
     * @throws RuntimeException when the shop has no status for the role
     */
    private function status(Store $store, string $role): int
    {
        return $this->statusFor($this->statuses($store), $role)
            ?? throw new RuntimeException('osCommerce has no order status for "'.$role.'". Add one under Localization → Orders Status (e.g. Processing, Delivered, Cancelled).');
    }

    /**
     * @return array<int, string> status name by ID, in the shop's default language
     */
    private function statuses(Store $store): array
    {
        $db = $this->db($store);
        $prefix = (string) ($store->credential('table_prefix') ?? '');

        return $this->run(function () use ($db, $prefix) {
            $language = $db->table($prefix.'languages')
                ->where('code', $db->table($prefix.'configuration')->where('configuration_key', 'DEFAULT_LANGUAGE')->value('configuration_value'))
                ->value('languages_id') ?? $db->table($prefix.'languages')->min('languages_id') ?? 1;

            return $db->table($prefix.'orders_status')->where('language_id', $language)->orderBy('orders_status_id')->pluck('orders_status_name', 'orders_status_id')->all();
        });
    }

    /**
     * customers_*, delivery_* or billing_* address columns of an osCommerce order.
     *
     * @param  array<string, string>  $address
     * @return array<string, string|int>
     */
    private function addressColumns(string $type, array $address): array
    {
        return [
            ...($type === 'customers' ? [] : ["{$type}_name" => (string) ($address['name'] ?? '')]),
            "{$type}_company" => (string) ($address['company'] ?? ''),
            "{$type}_street_address" => (string) ($address['address1'] ?? ''),
            "{$type}_suburb" => (string) ($address['address2'] ?? ''),
            "{$type}_city" => (string) ($address['city'] ?? ''),
            "{$type}_postcode" => (string) ($address['zip'] ?? ''),
            "{$type}_state" => (string) ($address['province'] ?? ''),
            "{$type}_country" => (string) ($address['country'] ?? ($address['country_code'] ?? '')),
            "{$type}_address_format_id" => 1,
        ];
    }

    /**
     * @return array<string, string> table names with the store's prefix
     */
    private function tables(Store $store): array
    {
        $prefix = (string) ($store->credential('table_prefix') ?? '');

        return collect(['orders', 'orders_products', 'orders_total', 'orders_status_history', 'products'])
            ->mapWithKeys(fn (string $table) => [$table => $prefix.$table])
            ->all();
    }

    private function db(Store $store): Connection
    {
        return $this->connections[$store->id] ??= $this->database->connection($store);
    }

    /**
     * Database errors become a readable failure.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function run(callable $write): mixed
    {
        try {
            return $write();
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('Could not update the osCommerce database. Check the database details on the store and that its user may write to it.');
        }
    }
}
