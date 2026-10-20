<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reads the catalog (v3 API) and orders (v2 API) of a BigCommerce store.
 */
class BigCommerceDataSource extends HttpDataSource
{
    private const PRODUCTS_PER_PAGE = 50;

    private const ORDERS_PER_PAGE = 50;

    /** BigCommerce order status IDs, see https://developer.bigcommerce.com/docs/store-operations/orders/order-statuses */
    private const PAID_STATUSES = [2, 3, 8, 9, 10, 11];

    private const REFUNDED_STATUSES = [4, 14];

    private const DECLINED_STATUS = 6;

    private const CANCELLED_STATUS = 5;

    private const SHIPPED_STATUSES = [2, 10];

    private const PARTIALLY_SHIPPED_STATUS = 3;

    protected function platform(): string
    {
        return 'BigCommerce';
    }

    public function products(Store $store): iterable
    {
        $page = 1;

        do {
            $response = $this->send(fn () => $this->api($store)->get('v3/catalog/products', [
                'include' => 'variants,images',
                'limit' => self::PRODUCTS_PER_PAGE,
                'page' => $page,
            ]));

            foreach ((array) $response->json('data') as $product) {
                yield $this->toProduct($product);
            }

            $totalPages = (int) $response->json('meta.pagination.total_pages', 1);
            $page++;
        } while ($page <= $totalPages);
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $query = ['limit' => self::ORDERS_PER_PAGE, 'sort' => 'date_modified:asc'];

        if ($updatedSince) {
            $query['min_date_modified'] = CarbonImmutable::instance($updatedSince)->utc()->toRfc2822String();
        }

        for ($page = 1; ; $page++) {
            // v2 answers 204 No Content when there are no more orders.
            $orders = (array) $this->send(fn () => $this->api($store)->get('v2/orders', $query + ['page' => $page]))->json();

            if ($orders === []) {
                return;
            }

            foreach ($orders as $order) {
                $lines = (array) $this->send(fn () => $this->api($store)->get("v2/orders/{$order['id']}/products", ['limit' => 250]))->json();
                $shipping = (array) $this->send(fn () => $this->api($store)->get("v2/orders/{$order['id']}/shipping_addresses"))->json();

                yield $this->toOrder($order, $lines, $shipping[0] ?? null);
            }

            if (count($orders) < self::ORDERS_PER_PAGE) {
                return;
            }
        }
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->baseUrl("https://api.bigcommerce.com/stores/{$store->external_reference}/")
            ->withHeaders(['X-Auth-Token' => (string) $store->credential('access_token')]);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function toProduct(array $product): ProductData
    {
        $images = $product['images'] ?? [];
        $thumbnail = collect($images)->firstWhere('is_thumbnail', true) ?? ($images[0] ?? null);
        $tracking = $product['inventory_tracking'] ?? 'none';
        $variants = $product['variants'] ?? [];

        return new ProductData(
            externalId: (string) $product['id'],
            name: (string) $product['name'],
            handle: isset($product['custom_url']['url']) ? trim($product['custom_url']['url'], '/') : null,
            description: self::plainText($product['description'] ?? null),
            category: null,
            imageUrl: $thumbnail['url_standard'] ?? null,
            isActive: (bool) ($product['is_visible'] ?? true),
            variants: array_map(fn (array $variant) => new VariantData(
                externalId: (string) $variant['id'],
                sku: filled($variant['sku'] ?? null) ? $variant['sku'] : null,
                barcode: ($variant['upc'] ?? null) ?: (($variant['gtin'] ?? null) ?: null),
                optionName: ($variant['option_values'] ?? []) ? implode(' / ', array_column($variant['option_values'], 'option_display_name')) : null,
                optionValue: ($variant['option_values'] ?? []) ? implode(' / ', array_column($variant['option_values'], 'label')) : null,
                price: (float) ($variant['price'] ?? $variant['calculated_price'] ?? $product['price'] ?? 0),
                costPrice: isset($variant['cost_price']) && (float) $variant['cost_price'] > 0 ? (float) $variant['cost_price'] : null,
                stockQuantity: match ($tracking) {
                    'variant' => (int) ($variant['inventory_level'] ?? 0),
                    'product' => count($variants) === 1 ? (int) ($product['inventory_level'] ?? 0) : null,
                    default => null,
                },
            ), $variants),
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>|null  $shipping
     */
    private function toOrder(array $order, array $lines, ?array $shipping): OrderData
    {
        $billing = $order['billing_address'] ?? [];
        $address = $shipping ?: $billing;
        $status = (int) ($order['status_id'] ?? 0);

        return new OrderData(
            externalId: (string) $order['id'],
            number: '#'.$order['id'],
            customerName: trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: null,
            customerEmail: ($billing['email'] ?? null) ?: null,
            customerPhone: ($billing['phone'] ?? null) ?: (($address['phone'] ?? null) ?: null),
            shippingAddress: $address ? array_filter([
                'name' => trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')),
                'company' => $address['company'] ?? null,
                'address1' => $address['street_1'] ?? null,
                'address2' => $address['street_2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['state'] ?? null,
                'zip' => $address['zip'] ?? null,
                'country' => $address['country'] ?? null,
                'country_code' => $address['country_iso2'] ?? null,
                'phone' => $address['phone'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match (true) {
                in_array($status, self::PAID_STATUSES, true) => OrderData::PAYMENT_PAID,
                in_array($status, self::REFUNDED_STATUSES, true) => OrderData::PAYMENT_REFUNDED,
                $status === self::DECLINED_STATUS => OrderData::PAYMENT_FAILED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: match (true) {
                in_array($status, self::SHIPPED_STATUSES, true) => OrderData::FULFILLMENT_FULFILLED,
                $status === self::PARTIALLY_SHIPPED_STATUS => OrderData::FULFILLMENT_PARTIAL,
                default => OrderData::FULFILLMENT_UNFULFILLED,
            },
            cancelled: $status === self::CANCELLED_STATUS,
            total: (float) ($order['total_inc_tax'] ?? 0),
            currency: (string) ($order['currency_code'] ?? 'USD'),
            note: filled($order['customer_message'] ?? null) ? $order['customer_message'] : null,
            placedAt: isset($order['date_created']) ? CarbonImmutable::parse($order['date_created']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                sku: filled($line['sku'] ?? null) ? $line['sku'] : null,
                name: (string) $line['name'],
                quantity: (int) $line['quantity'],
                unitPrice: (float) ($line['price_ex_tax'] ?? $line['base_price'] ?? 0),
                taxAmount: (float) ($line['total_tax'] ?? 0),
                totalAmount: (float) ($line['total_inc_tax'] ?? 0),
            ), $lines),
        );
    }
}
