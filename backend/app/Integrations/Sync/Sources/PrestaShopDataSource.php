<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads products, combinations, stock and orders through the PrestaShop webservice (JSON output).
 */
class PrestaShopDataSource extends HttpDataSource
{
    private const PRODUCTS_PER_PAGE = 50;

    private const ORDERS_PER_PAGE = 50;

    /** Small lookup tables (option values, categories, order states...) read once per sync. */
    private array $cache = [];

    protected function platform(): string
    {
        return 'PrestaShop';
    }

    public function products(Store $store): iterable
    {
        for ($offset = 0; ; $offset += self::PRODUCTS_PER_PAGE) {
            $products = $this->list($store, 'products', ['limit' => $offset.','.self::PRODUCTS_PER_PAGE, 'sort' => '[id_ASC]']);

            foreach ($products as $product) {
                yield $this->toProduct($store, $product);
            }

            if (count($products) < self::PRODUCTS_PER_PAGE) {
                return;
            }
        }
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $filter = $updatedSince
            ? ['filter[date_upd]' => '['.CarbonImmutable::instance($updatedSince)->format('Y-m-d H:i:s').','.now()->addDay()->format('Y-m-d H:i:s').']', 'date' => 1]
            : [];

        for ($offset = 0; ; $offset += self::ORDERS_PER_PAGE) {
            $orders = $this->list($store, 'orders', $filter + ['limit' => $offset.','.self::ORDERS_PER_PAGE, 'sort' => '[id_ASC]']);

            foreach ($orders as $order) {
                yield $this->toOrder($store, $order);
            }

            if (count($orders) < self::ORDERS_PER_PAGE) {
                return;
            }
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function list(Store $store, string $resource, array $query = []): array
    {
        $response = $this->send(fn () => $this->request($store, $resource, $query + ['display' => 'full']), [401]);

        if ($response->status() === 401) {
            throw new RuntimeException("The PrestaShop webservice key cannot read \"{$resource}\". Allow GET for it in PrestaShop admin → Advanced Parameters → Webservice → Finovo's key, or reconnect with the Finovo module.");
        }

        return array_values((array) ($response->json($resource) ?? []));
    }

    /**
     * One record by ID, or null when the shop does not have it (or Finovo's key may not read that resource).
     *
     * @return array<string, mixed>|null
     */
    private function find(Store $store, string $resource, string $singular, int|string $id): ?array
    {
        if ((int) $id <= 0) {
            return null;
        }

        return $this->cache[$resource][$id] ??= (function () use ($store, $resource, $singular, $id) {
            $response = $this->send(fn () => $this->request($store, "{$resource}/{$id}"), [401, 404]);

            return $response->successful() ? $response->json($singular) : null;
        })();
    }

    /**
     * A whole lookup table keyed by ID; empty (with a warning) when the key may not read it.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function table(Store $store, string $resource): array
    {
        return $this->cache["table:{$resource}"] ??= (function () use ($store, $resource) {
            $response = $this->send(fn () => $this->request($store, $resource, ['display' => 'full']), [401]);

            if (! $response->successful()) {
                $this->warnings[] = "The PrestaShop webservice key cannot read \"{$resource}\". In Finovo's PrestaShop module click \"Connect again\" to update its permissions.";

                return [];
            }

            return collect((array) ($response->json($resource) ?? []))->keyBy('id')->all();
        })();
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function request(Store $store, string $path, array $query = []): Response
    {
        return Http::acceptJson()->timeout(30)->get(rtrim((string) $store->external_reference, '/').'/api/'.$path, $query + [
            'ws_key' => (string) $store->credential('webservice_key'),
            'output_format' => 'JSON',
        ]);
    }

    /**
     * Multi-language fields come as [{id, value}, ...]; Finovo uses the shop's first language.
     */
    private static function text(mixed $field): ?string
    {
        $value = is_array($field) ? ($field[0]['value'] ?? null) : $field;

        return filled($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function toProduct(Store $store, array $product): ProductData
    {
        $productId = (int) $product['id'];
        $stock = collect($this->list($store, 'stock_availables', ['filter[id_product]' => "[{$productId}]"]))
            ->mapWithKeys(fn (array $row) => [(int) $row['id_product_attribute'] => (int) $row['quantity']]);
        $basePrice = (float) ($product['price'] ?? 0);
        $combinationIds = array_column($product['associations']['combinations'] ?? [], 'id');

        $variants = $combinationIds === []
            ? [new VariantData(
                externalId: (string) $productId,
                sku: self::text($product['reference'] ?? null),
                barcode: self::text($product['ean13'] ?? null),
                optionName: null,
                optionValue: null,
                price: $basePrice,
                costPrice: (float) ($product['wholesale_price'] ?? 0) > 0 ? (float) $product['wholesale_price'] : null,
                stockQuantity: $stock->get(0),
            )]
            : array_map(
                fn (array $combination) => $this->toVariant($store, $productId, $combination, $basePrice, $stock->get((int) $combination['id'])),
                $this->list($store, 'combinations', ['filter[id_product]' => "[{$productId}]"]),
            );

        $category = $this->find($store, 'categories', 'category', $product['id_category_default'] ?? 0);

        return new ProductData(
            externalId: (string) $productId,
            name: (string) self::text($product['name'] ?? null),
            handle: self::text($product['link_rewrite'] ?? null),
            description: self::plainText(self::text($product['description_short'] ?? null) ?? self::text($product['description'] ?? null)),
            category: $category ? self::text($category['name'] ?? null) : null,
            imageUrl: $this->imageUrl($store, (int) ($product['id_default_image'] ?? 0)),
            isActive: (bool) (int) ($product['active'] ?? 1),
            variants: $variants,
            images: array_values(array_filter(array_map(fn (array $image) => $this->imageUrl($store, (int) ($image['id'] ?? 0)), $product['associations']['images'] ?? []))),
            details: array_filter([
                'Reference' => self::text($product['reference'] ?? null),
                'Weight' => (float) ($product['weight'] ?? 0) > 0 ? $product['weight'] : null,
                'Condition' => $product['condition'] ?? null,
                'Created on PrestaShop' => $product['date_add'] ?? null,
                'Updated on PrestaShop' => $product['date_upd'] ?? null,
            ]),
            raw: $product,
        );
    }

    /**
     * @param  array<string, mixed>  $combination
     */
    private function toVariant(Store $store, int $productId, array $combination, float $basePrice, ?int $stock): VariantData
    {
        $optionValues = $this->table($store, 'product_option_values');
        $options = $this->table($store, 'product_options');
        $choices = [];

        foreach ($combination['associations']['product_option_values'] ?? [] as $link) {
            $value = $optionValues[$link['id']] ?? null;

            if ($value) {
                $group = $options[$value['id_attribute_group']] ?? null;
                $choices[self::text($group['public_name'] ?? $group['name'] ?? null) ?? 'Option'] = self::text($value['name'] ?? null);
            }
        }

        return new VariantData(
            externalId: $productId.'-'.$combination['id'],
            sku: self::text($combination['reference'] ?? null),
            barcode: self::text($combination['ean13'] ?? null),
            optionName: $choices ? implode(' / ', array_keys($choices)) : null,
            optionValue: $choices ? implode(' / ', $choices) : 'Combination '.$combination['id'],
            price: $basePrice + (float) ($combination['price'] ?? 0),
            costPrice: (float) ($combination['wholesale_price'] ?? 0) > 0 ? (float) $combination['wholesale_price'] : null,
            stockQuantity: $stock,
        );
    }

    /**
     * Public product image path: image 123 is stored at /img/p/1/2/3/123.jpg.
     */
    private function imageUrl(Store $store, int $imageId): ?string
    {
        return $imageId > 0
            ? rtrim((string) $store->external_reference, '/').'/img/p/'.implode('/', str_split((string) $imageId)).'/'.$imageId.'.jpg'
            : null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function toOrder(Store $store, array $order): OrderData
    {
        $customer = $this->find($store, 'customers', 'customer', $order['id_customer'] ?? 0);
        $address = $this->find($store, 'addresses', 'address', $order['id_address_delivery'] ?? 0);
        $country = $address ? $this->find($store, 'countries', 'country', $address['id_country'] ?? 0) : null;
        $currency = $this->find($store, 'currencies', 'currency', $order['id_currency'] ?? 0);
        $state = $this->table($store, 'order_states')[$order['current_state'] ?? 0] ?? null;
        $stateName = strtolower((string) self::text($state['name'] ?? null));

        return new OrderData(
            externalId: (string) $order['id'],
            number: (string) ($order['reference'] ?? $order['id']),
            customerName: $customer ? trim(($customer['firstname'] ?? '').' '.($customer['lastname'] ?? '')) ?: null : null,
            customerEmail: $customer['email'] ?? null,
            customerPhone: $address ? ((($address['phone_mobile'] ?? '') ?: ($address['phone'] ?? '')) ?: null) : null,
            shippingAddress: $address ? array_filter([
                'name' => trim(($address['firstname'] ?? '').' '.($address['lastname'] ?? '')),
                'company' => $address['company'] ?? null,
                'address1' => $address['address1'] ?? null,
                'address2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'zip' => $address['postcode'] ?? null,
                'country' => $country ? self::text($country['name'] ?? null) : null,
                'country_code' => $country['iso_code'] ?? null,
                'phone' => ($address['phone_mobile'] ?? null) ?: ($address['phone'] ?? null),
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match (true) {
                str_contains($stateName, 'refund') => OrderData::PAYMENT_REFUNDED,
                str_contains($stateName, 'error') => OrderData::PAYMENT_FAILED,
                (bool) (int) ($state['paid'] ?? 0) => OrderData::PAYMENT_PAID,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: (bool) (int) ($state['shipped'] ?? 0) || (bool) (int) ($state['delivered'] ?? 0)
                ? OrderData::FULFILLMENT_FULFILLED
                : OrderData::FULFILLMENT_UNFULFILLED,
            cancelled: str_contains($stateName, 'cancel'),
            total: (float) ($order['total_paid_tax_incl'] ?? $order['total_paid'] ?? 0),
            currency: (string) ($currency['iso_code'] ?? 'USD'),
            note: null,
            placedAt: isset($order['date_add']) ? CarbonImmutable::parse($order['date_add']) : null,
            lines: array_map(function (array $row) {
                $quantity = (int) $row['product_quantity'];
                $unitWithTax = (float) ($row['unit_price_tax_incl'] ?? 0);
                $unitWithoutTax = (float) ($row['unit_price_tax_excl'] ?? $unitWithTax);

                return new OrderLineData(
                    externalVariantId: (int) ($row['product_attribute_id'] ?? 0) > 0
                        ? $row['product_id'].'-'.$row['product_attribute_id']
                        : (string) $row['product_id'],
                    sku: filled($row['product_reference'] ?? null) ? $row['product_reference'] : null,
                    name: (string) $row['product_name'],
                    quantity: $quantity,
                    unitPrice: $unitWithoutTax,
                    taxAmount: round(($unitWithTax - $unitWithoutTax) * $quantity, 2),
                    totalAmount: round($unitWithTax * $quantity, 2),
                    raw: $row,
                );
            }, $order['associations']['order_rows'] ?? []),
            subtotal: isset($order['total_products']) ? (float) $order['total_products'] : null,
            shippingTotal: isset($order['total_shipping']) ? (float) $order['total_shipping'] : null,
            discountTotal: isset($order['total_discounts']) ? (float) $order['total_discounts'] : null,
            paymentMethod: filled($order['payment'] ?? null) ? (string) $order['payment'] : null,
            details: array_filter(['Updated on PrestaShop' => $order['date_upd'] ?? null]),
            raw: $order,
        );
    }
}
