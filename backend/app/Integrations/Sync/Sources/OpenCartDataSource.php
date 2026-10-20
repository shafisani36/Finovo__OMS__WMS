<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Integrations\Sync\Sources\Concerns\InterpretsStatusNames;
use App\Integrations\Sync\Sources\OpenCart\OpenCartDatabaseRows;
use App\Integrations\Sync\Sources\OpenCart\OpenCartModuleRows;
use App\Integrations\Sync\Sources\OpenCart\OpenCartRows;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads OpenCart 3 products, option values (as variants), stock and orders,
 * through the Finovo module or, for stores added with database details, from the shop database.
 *
 * OpenCart keeps stock per option value rather than per combination, so each option value becomes a variant.
 */
class OpenCartDataSource extends HttpDataSource
{
    use InterpretsStatusNames;

    private const PAGE_SIZE = 100;

    private ?OpenCartRows $rows = null;

    public function __construct(
        protected ExternalDatabaseConnector $database
    ) {}

    protected function platform(): string
    {
        return 'OpenCart';
    }

    public function products(Store $store): iterable
    {
        $rows = $this->rows($store);

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $products = $this->read(fn () => $rows->products($offset, self::PAGE_SIZE));
            $options = collect($this->read(fn () => $rows->optionValues(array_map('intval', array_column($products, 'product_id')))))
                ->groupBy('product_id');

            foreach ($products as $product) {
                yield $this->toProduct($product, $options->get($product['product_id'], collect())->all(), $rows->imageBaseUrl());
            }

            if (count($products) < self::PAGE_SIZE) {
                break;
            }
        }

        if ($rows instanceof OpenCartDatabaseRows) {
            $this->warnings[] = 'Product images are imported only for OpenCart stores connected with the Finovo module.';
        }
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $rows = $this->rows($store);
        // OpenCart stores dates in the shop's local time; reading a little extra is harmless (orders are matched by ID).
        $since = $updatedSince ? CarbonImmutable::instance($updatedSince)->subDay()->format('Y-m-d H:i:s') : null;

        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            $orders = $this->read(fn () => $rows->orders($since, $offset, self::PAGE_SIZE));
            $lines = collect($this->read(fn () => $rows->orderProducts(array_map('intval', array_column($orders, 'order_id')))))
                ->groupBy('order_id');

            foreach ($orders as $order) {
                yield $this->toOrder($order, $lines->get($order['order_id'], collect())->all());
            }

            if (count($orders) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    private function rows(Store $store): OpenCartRows
    {
        return $this->rows ??= filled($store->credential('api_secret'))
            ? new OpenCartModuleRows($store, fn ($request) => $this->send($request))
            : new OpenCartDatabaseRows($this->database->connection($store), $store->credential('table_prefix') ?? 'oc_');
    }

    /**
     * Database errors become a readable sync failure.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new RuntimeException('Could not read the OpenCart database. Check the database details on the store.');
        }
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<array<string, mixed>>  $optionValues
     */
    private function toProduct(array $product, array $optionValues, ?string $imageBaseUrl): ProductData
    {
        $id = (string) $product['product_id'];
        $sku = filled($product['sku'] ?? null) ? $product['sku'] : (filled($product['model'] ?? null) ? $product['model'] : null);
        $price = (float) $product['price'];

        $variants = $optionValues === []
            ? [new VariantData(
                externalId: $id,
                sku: $sku,
                barcode: ($product['ean'] ?? null) ?: (($product['upc'] ?? null) ?: null),
                optionName: null,
                optionValue: null,
                price: $price,
                costPrice: null,
                stockQuantity: (int) $product['quantity'],
            )]
            : array_map(fn (array $value) => new VariantData(
                externalId: $id.'-'.$value['product_option_value_id'],
                sku: $sku ? $sku.'-'.Str::upper(Str::slug((string) $value['value_name'])) : null,
                barcode: null,
                optionName: (string) $value['option_name'],
                optionValue: (string) $value['value_name'],
                price: $value['price_prefix'] === '-' ? $price - (float) $value['price'] : $price + (float) $value['price'],
                costPrice: null,
                stockQuantity: (int) $value['quantity'],
            ), $optionValues);

        return new ProductData(
            externalId: $id,
            name: html_entity_decode((string) ($product['name'] ?? 'Product '.$id), ENT_QUOTES | ENT_HTML5),
            handle: $sku,
            description: self::plainText(html_entity_decode((string) ($product['description'] ?? ''), ENT_QUOTES | ENT_HTML5)),
            category: filled($product['category'] ?? null) ? html_entity_decode($product['category'], ENT_QUOTES | ENT_HTML5) : null,
            imageUrl: $imageBaseUrl && filled($product['image'] ?? null) ? rtrim($imageBaseUrl, '/').'/'.$product['image'] : null,
            isActive: (bool) (int) $product['status'],
            variants: $variants,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $lines
     */
    private function toOrder(array $order, array $lines): OrderData
    {
        $status = self::statusFromName($order['status_name'] ?? null);
        $rate = (float) ($order['currency_value'] ?? 1) ?: 1.0;

        return new OrderData(
            externalId: (string) $order['order_id'],
            number: ($order['invoice_prefix'] ?? '').$order['order_id'],
            customerName: trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')) ?: null,
            customerEmail: ($order['email'] ?? null) ?: null,
            customerPhone: ($order['telephone'] ?? null) ?: null,
            shippingAddress: filled($order['shipping_address_1'] ?? null) ? array_filter([
                'name' => trim(($order['shipping_firstname'] ?? '').' '.($order['shipping_lastname'] ?? '')),
                'company' => $order['shipping_company'] ?? null,
                'address1' => $order['shipping_address_1'] ?? null,
                'address2' => $order['shipping_address_2'] ?? null,
                'city' => $order['shipping_city'] ?? null,
                'province' => $order['shipping_zone'] ?? null,
                'zip' => $order['shipping_postcode'] ?? null,
                'country' => $order['shipping_country'] ?? null,
                'phone' => $order['telephone'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: $status['payment'],
            fulfillmentStatus: $status['fulfillment'],
            cancelled: $status['cancelled'],
            // OpenCart stores amounts in the default currency; currency_value converts to the order's currency.
            total: round((float) $order['total'] * $rate, 2),
            currency: (string) ($order['currency_code'] ?? 'USD'),
            note: filled($order['comment'] ?? null) ? $order['comment'] : null,
            placedAt: isset($order['date_added']) ? CarbonImmutable::parse($order['date_added']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: (int) ($line['product_option_value_id'] ?? 0) > 0
                    ? $line['product_id'].'-'.$line['product_option_value_id']
                    : (string) $line['product_id'],
                sku: filled($line['model'] ?? null) ? $line['model'] : null,
                name: html_entity_decode((string) $line['name'], ENT_QUOTES | ENT_HTML5),
                quantity: (int) $line['quantity'],
                unitPrice: round((float) $line['price'] * $rate, 2),
                taxAmount: round((float) $line['tax'] * (int) $line['quantity'] * $rate, 2),
                totalAmount: round(((float) $line['total'] + (float) $line['tax'] * (int) $line['quantity']) * $rate, 2),
            ), $lines),
        );
    }
}
