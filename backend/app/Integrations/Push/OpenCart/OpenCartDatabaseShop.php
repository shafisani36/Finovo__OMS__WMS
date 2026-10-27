<?php

namespace App\Integrations\Push\OpenCart;

use App\Integrations\Push\Concerns\WritesShopDatabase;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * OpenCart 3 changes made straight in the shop database: the same rows OpenCart's checkout and order history write.
 * Stock is not subtracted when an order is placed here; Finovo sets the shop's stock itself.
 */
class OpenCartDatabaseShop implements OpenCartShop
{
    use WritesShopDatabase;

    private ?int $languageId = null;

    public function __construct(
        private readonly Connection $db,
        private readonly string $prefix,
        /** The shop's website (not stored in the OpenCart database), shown as the order's store address. */
        private readonly ?string $siteUrl = null,
    ) {}

    public function orderStatuses(): array
    {
        return $this->run(fn () => $this->db->table($this->prefix.'order_status')
            ->where('language_id', $this->languageId())
            ->orderBy('order_status_id')
            ->pluck('name', 'order_status_id')
            ->map(fn ($name) => html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5))
            ->all());
    }

    public function orderStatusId(int $orderId): int
    {
        return (int) ($this->run(fn () => $this->db->table($this->prefix.'order')->where('order_id', $orderId)->value('order_status_id'))
            ?? throw new RuntimeException("OpenCart has no order {$orderId} any more."));
    }

    public function addOrderHistory(int $orderId, int $statusId, string $comment, bool $notify): void
    {
        $this->run(fn () => $this->db->transaction(function () use ($orderId, $statusId, $comment) {
            $this->db->table($this->prefix.'order')->where('order_id', $orderId)->update(['order_status_id' => $statusId, 'date_modified' => now()->format('Y-m-d H:i:s')]);
            $this->insertRow($this->db, $this->prefix.'order_history', [
                'order_id' => $orderId,
                'order_status_id' => $statusId,
                // Emails are sent by OpenCart itself; a change made in its database cannot send one.
                'notify' => 0,
                'comment' => $comment,
                'date_added' => now()->format('Y-m-d H:i:s'),
            ]);
        }));
    }

    public function setStock(int $productId, ?int $optionValueId, int $quantity): void
    {
        $this->run(fn () => $optionValueId
            ? $this->db->table($this->prefix.'product_option_value')->where('product_option_value_id', $optionValueId)->update(['quantity' => $quantity])
            : $this->db->table($this->prefix.'product')->where('product_id', $productId)->update(['quantity' => $quantity]));
    }

    public function createOrder(array $order, int $statusId, string $comment): int
    {
        return $this->run(fn () => $this->db->transaction(function () use ($order, $statusId, $comment) {
            $currency = $this->currency((string) $order['currency_code']);
            $rate = (float) $currency->value ?: 1.0;
            $base = fn ($amount) => round((float) $amount / $rate, 4);
            $now = now()->format('Y-m-d H:i:s');

            $orderId = $this->insertRow($this->db, $this->prefix.'order', [
                'invoice_no' => 0,
                'invoice_prefix' => (string) ($this->setting('config_invoice_prefix') ?? 'INV-'),
                'store_id' => 0,
                'store_name' => (string) ($this->setting('config_name') ?? ''),
                'store_url' => $this->siteUrl ? rtrim($this->siteUrl, '/').'/' : '',
                'customer_id' => 0,
                'customer_group_id' => (int) ($this->setting('config_customer_group_id') ?? 1),
                'firstname' => $order['customer']['firstname'],
                'lastname' => $order['customer']['lastname'],
                'email' => $order['customer']['email'],
                'telephone' => $order['customer']['telephone'],
                ...$this->addressFields('payment', $order['payment']),
                'payment_method' => $order['payment_method'],
                'payment_code' => 'finovo',
                ...$this->addressFields('shipping', $order['shipping']),
                'shipping_method' => 'Shipping',
                'shipping_code' => 'finovo.finovo',
                'comment' => $order['comment'],
                'total' => $base($order['total']),
                'order_status_id' => 0,
                'language_id' => $this->languageId(),
                'currency_id' => (int) $currency->currency_id,
                'currency_code' => (string) $currency->code,
                'currency_value' => $rate,
                'user_agent' => 'Finovo',
                'date_added' => $now,
                'date_modified' => $now,
            ]);

            foreach ($order['products'] as $line) {
                $orderProductId = $this->insertRow($this->db, $this->prefix.'order_product', [
                    'order_id' => $orderId,
                    'product_id' => (int) $line['product_id'],
                    'name' => $line['name'],
                    'model' => $line['model'],
                    'quantity' => (int) $line['quantity'],
                    'price' => $base($line['price']),
                    'total' => $base($line['total']),
                    'tax' => $base($line['tax']),
                    'reward' => 0,
                ]);

                if ($line['product_option_value_id'] && ($option = $this->option((int) $line['product_option_value_id']))) {
                    $this->insertRow($this->db, $this->prefix.'order_option', ['order_id' => $orderId, 'order_product_id' => $orderProductId, ...$option]);
                }
            }

            foreach ($order['totals'] as $total) {
                $this->insertRow($this->db, $this->prefix.'order_total', [...$total, 'order_id' => $orderId, 'value' => $base($total['value'])]);
            }

            $this->addOrderHistory($orderId, $statusId, $comment, false);

            return $orderId;
        }));
    }

    public function saveProduct(array $product): array
    {
        return $this->run(fn () => $this->db->transaction(function () use ($product) {
            $now = now()->format('Y-m-d H:i:s');
            $fields = [
                'model' => (string) $product['model'],
                'sku' => (string) $product['sku'],
                'price' => (float) $product['price'],
                'status' => (int) $product['status'],
                'date_modified' => $now,
            ];
            $productId = (int) ($product['product_id'] ?? 0);

            if ($productId && $this->db->table($this->prefix.'product')->where('product_id', $productId)->exists()) {
                $this->db->table($this->prefix.'product')->where('product_id', $productId)->update($fields);
                $this->db->table($this->prefix.'product_description')->where('product_id', $productId)->update([
                    'name' => $product['name'],
                    'description' => (string) $product['description'],
                    'meta_title' => $product['name'],
                ]);
            } else {
                $productId = $this->insertRow($this->db, $this->prefix.'product', [
                    ...$fields,
                    'quantity' => 0,
                    'stock_status_id' => (int) ($this->setting('config_stock_status_id') ?? 5),
                    'shipping' => 1,
                    'subtract' => 1,
                    'minimum' => 1,
                    'date_available' => now()->format('Y-m-d'),
                    'date_added' => $now,
                ]);

                foreach ($this->languageIds() as $languageId) {
                    $this->insertRow($this->db, $this->prefix.'product_description', [
                        'product_id' => $productId,
                        'language_id' => $languageId,
                        'name' => $product['name'],
                        'description' => (string) $product['description'],
                        'meta_title' => $product['name'],
                    ]);
                }

                $this->insertRow($this->db, $this->prefix.'product_to_store', ['product_id' => $productId, 'store_id' => 0]);
            }

            return ['product_id' => $productId, 'option_values' => empty($product['values']) ? [] : $this->saveOptionValues($productId, (string) $product['option_name'], $product['values'])];
        }));
    }

    public function deleteProduct(int $productId): void
    {
        $this->run(fn () => $this->db->transaction(function () use ($productId) {
            foreach (['product', 'product_description', 'product_to_store', 'product_to_category', 'product_option', 'product_option_value', 'product_image', 'product_discount', 'product_special'] as $table) {
                if ($this->db->getSchemaBuilder()->hasTable($this->prefix.$table)) {
                    $this->db->table($this->prefix.$table)->where('product_id', $productId)->delete();
                }
            }
        }));
    }

    /**
     * The product's one select option ("Size") and its values; values no longer sold are removed.
     *
     * @param  list<array{key: string, name: string, price_diff: float, product_option_value_id: int|null}>  $values
     * @return array<string, int> product_option_value_id by value key
     */
    private function saveOptionValues(int $productId, string $optionName, array $values): array
    {
        $optionId = $this->namedId('option', 'option_id', $optionName, fn () => $this->insertRow($this->db, $this->prefix.'option', ['type' => 'select', 'sort_order' => 0]));
        $productOptionId = (int) ($this->db->table($this->prefix.'product_option')->where('product_id', $productId)->where('option_id', $optionId)->value('product_option_id')
            ?? $this->insertRow($this->db, $this->prefix.'product_option', ['product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 1]));
        $ids = [];

        foreach ($values as $value) {
            $diff = (float) $value['price_diff'];
            $fields = ['price' => abs($diff), 'price_prefix' => $diff < 0 ? '-' : '+'];
            $existing = $value['product_option_value_id']
                ? $this->db->table($this->prefix.'product_option_value')->where('product_option_value_id', $value['product_option_value_id'])->where('product_id', $productId)->exists()
                : false;

            if ($existing) {
                $this->db->table($this->prefix.'product_option_value')->where('product_option_value_id', $value['product_option_value_id'])->update($fields);
                $ids[$value['key']] = (int) $value['product_option_value_id'];

                continue;
            }

            $optionValueId = $this->namedId('option_value', 'option_value_id', $value['name'], fn () => $this->insertRow($this->db, $this->prefix.'option_value', ['option_id' => $optionId, 'image' => '', 'sort_order' => 0]), $optionId);
            $ids[$value['key']] = $this->insertRow($this->db, $this->prefix.'product_option_value', [
                ...$fields,
                'product_option_id' => $productOptionId,
                'product_id' => $productId,
                'option_id' => $optionId,
                'option_value_id' => $optionValueId,
                'quantity' => 0,
                'subtract' => 1,
                'points' => 0,
                'points_prefix' => '+',
                'weight' => 0,
                'weight_prefix' => '+',
            ]);
        }

        $this->db->table($this->prefix.'product_option_value')->where('product_option_id', $productOptionId)->whereNotIn('product_option_value_id', array_values($ids))->delete();

        return $ids;
    }

    /**
     * The ID of an option (or an option's value) by its name in the default language, created with a name in every
     * language when the shop has none by that name.
     *
     * @param  callable(): int  $create  inserts the row and returns its ID
     */
    private function namedId(string $table, string $key, string $name, callable $create, ?int $optionId = null): int
    {
        $found = $this->db->table($this->prefix.$table.'_description')
            ->where('language_id', $this->languageId())
            ->where('name', $name)
            ->when($optionId, fn ($query) => $query->where('option_id', $optionId))
            ->value($key);

        if ($found) {
            return (int) $found;
        }

        $id = $create();
        foreach ($this->languageIds() as $languageId) {
            $this->insertRow($this->db, $this->prefix.$table.'_description', array_filter([$key => $id, 'language_id' => $languageId, 'option_id' => $optionId, 'name' => $name], fn ($value) => $value !== null));
        }

        return $id;
    }

    /**
     * @return list<int>
     */
    private function languageIds(): array
    {
        return $this->db->table($this->prefix.'language')->pluck('language_id')->map(fn ($id) => (int) $id)->all() ?: [$this->languageId()];
    }

    /**
     * payment_* or shipping_* columns of an order, with the country and zone the shop knows.
     *
     * @param  array<string, string|null>  $address
     * @return array<string, mixed>
     */
    private function addressFields(string $type, array $address): array
    {
        $country = filled($address['country_code'] ?? null)
            ? $this->db->table($this->prefix.'country')->where('iso_code_2', strtoupper((string) $address['country_code']))->first()
            : null;
        $zone = $country && filled($address['zone'] ?? null)
            ? $this->db->table($this->prefix.'zone')->where('country_id', $country->country_id)
                ->where(fn ($query) => $query->where('name', $address['zone'])->orWhere('code', $address['zone']))
                ->first()
            : null;

        return [
            "{$type}_firstname" => (string) ($address['firstname'] ?? ''),
            "{$type}_lastname" => (string) ($address['lastname'] ?? ''),
            "{$type}_company" => (string) ($address['company'] ?? ''),
            "{$type}_address_1" => (string) ($address['address_1'] ?? ''),
            "{$type}_address_2" => (string) ($address['address_2'] ?? ''),
            "{$type}_city" => (string) ($address['city'] ?? ''),
            "{$type}_postcode" => (string) ($address['postcode'] ?? ''),
            "{$type}_country" => (string) ($country->name ?? ($address['country_code'] ?? '')),
            "{$type}_country_id" => (int) ($country->country_id ?? 0),
            "{$type}_zone" => (string) ($zone->name ?? ($address['zone'] ?? '')),
            "{$type}_zone_id" => (int) ($zone->zone_id ?? 0),
            "{$type}_address_format" => (string) ($country->address_format ?? ''),
            "{$type}_custom_field" => '[]',
        ];
    }

    /**
     * The order_option row for a chosen option value (e.g. Size: Large).
     *
     * @return array<string, mixed>|null
     */
    private function option(int $optionValueId): ?array
    {
        $row = $this->db->table($this->prefix.'product_option_value as pov')
            ->join($this->prefix.'option as o', 'o.option_id', '=', 'pov.option_id')
            ->join($this->prefix.'option_description as od', fn ($join) => $join->on('od.option_id', '=', 'pov.option_id')->where('od.language_id', $this->languageId()))
            ->join($this->prefix.'option_value_description as ovd', fn ($join) => $join->on('ovd.option_value_id', '=', 'pov.option_value_id')->where('ovd.language_id', $this->languageId()))
            ->where('pov.product_option_value_id', $optionValueId)
            ->first(['pov.product_option_id', 'pov.product_option_value_id', 'od.name', 'ovd.name as value', 'o.type']);

        return $row ? (array) $row : null;
    }

    private function currency(string $code): object
    {
        return $this->db->table($this->prefix.'currency')->where('code', $code)->first()
            ?? $this->db->table($this->prefix.'currency')->where('code', $this->setting('config_currency'))->first()
            ?? throw new RuntimeException("OpenCart has no {$code} currency.");
    }

    private function setting(string $key): ?string
    {
        return $this->db->table($this->prefix.'setting')->where('store_id', 0)->where('key', $key)->value('value');
    }

    private function languageId(): int
    {
        return $this->languageId ??= (int) ($this->db->table($this->prefix.'language')->where('code', $this->setting('config_language'))->value('language_id')
            ?? $this->db->table($this->prefix.'language')->min('language_id')
            ?? 1);
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

            throw new RuntimeException('Could not update the OpenCart database. Check the database details on the store and that its user may write to it.');
        }
    }
}
