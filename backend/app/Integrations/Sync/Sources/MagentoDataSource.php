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
 * Reads products, configurable children, stock and orders through the Magento 2 REST API.
 */
class MagentoDataSource extends HttpDataSource
{
    private const PER_PAGE = 50;

    /** Simple products only sold as part of a configurable product (Visibility: Not Visible Individually). */
    private const NOT_VISIBLE_INDIVIDUALLY = 1;

    private const ENABLED = 1;

    protected function platform(): string
    {
        return 'Magento';
    }

    public function products(Store $store): iterable
    {
        foreach ($this->pages($store, 'products') as $product) {
            $type = $product['type_id'] ?? 'simple';

            if ($type === 'simple' && (int) $this->attribute($product, 'visibility', 4) === self::NOT_VISIBLE_INDIVIDUALLY) {
                continue; // Imported as a variant of its configurable parent
            }

            if (! in_array($type, ['simple', 'configurable', 'virtual'], true)) {
                continue;
            }

            $children = $type === 'configurable'
                ? (array) $this->send(fn () => $this->api($store)->get('configurable-products/'.rawurlencode($product['sku']).'/children'))->json()
                : [$product];

            yield new ProductData(
                externalId: (string) $product['id'],
                name: (string) $product['name'],
                handle: $this->attribute($product, 'url_key'),
                description: self::plainText($this->attribute($product, 'short_description') ?? $this->attribute($product, 'description')),
                category: null,
                imageUrl: ($image = $this->attribute($product, 'image')) && $image !== 'no_selection'
                    ? rtrim((string) $store->external_reference, '/').'/media/catalog/product'.$image
                    : null,
                isActive: (int) ($product['status'] ?? self::ENABLED) === self::ENABLED,
                variants: array_map(fn (array $child) => new VariantData(
                    externalId: (string) $child['id'],
                    sku: (string) $child['sku'],
                    barcode: null,
                    optionName: $type === 'configurable' ? 'Variant' : null,
                    optionValue: $type === 'configurable' ? trim(str_replace((string) $product['name'], '', (string) $child['name']), ' -') ?: (string) $child['sku'] : null,
                    price: (float) ($child['price'] ?? $product['price'] ?? 0),
                    costPrice: ($cost = $this->attribute($child, 'cost')) !== null ? (float) $cost : null,
                    stockQuantity: $this->stock($store, (string) $child['sku']),
                ), $children),
            );
        }
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $filter = $updatedSince ? [
            'searchCriteria[filter_groups][0][filters][0][field]' => 'updated_at',
            'searchCriteria[filter_groups][0][filters][0][value]' => CarbonImmutable::instance($updatedSince)->utc()->format('Y-m-d H:i:s'),
            'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'gt',
        ] : [];

        foreach ($this->pages($store, 'orders', $filter) as $order) {
            yield $this->toOrder($order);
        }
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) $store->external_reference, '/').'/rest/V1/')
            ->withToken((string) $store->credential('access_token'));
    }

    /**
     * Magento repeats the last page for page numbers past the end, so paging stops at total_count.
     *
     * @param  array<string, scalar>  $filter
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(Store $store, string $path, array $filter = []): \Generator
    {
        for ($page = 1; ; $page++) {
            $response = $this->send(fn () => $this->api($store)->get($path, $filter + [
                'searchCriteria[pageSize]' => self::PER_PAGE,
                'searchCriteria[currentPage]' => $page,
            ]));

            yield from (array) $response->json('items');

            if ($page * self::PER_PAGE >= (int) $response->json('total_count', 0)) {
                return;
            }
        }
    }

    private function stock(Store $store, string $sku): ?int
    {
        $response = $this->send(fn () => $this->api($store)->get('stockItems/'.rawurlencode($sku)), [404]);

        return $response->successful() && ($response->json('manage_stock') ?? true) ? (int) $response->json('qty', 0) : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function attribute(array $product, string $code, mixed $default = null): mixed
    {
        foreach ($product['custom_attributes'] ?? [] as $attribute) {
            if ($attribute['attribute_code'] === $code) {
                return $attribute['value'];
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function toOrder(array $order): OrderData
    {
        $address = $order['extension_attributes']['shipping_assignments'][0]['shipping']['address'] ?? $order['billing_address'] ?? null;
        $state = $order['state'] ?? 'new';

        return new OrderData(
            externalId: (string) $order['entity_id'],
            number: '#'.($order['increment_id'] ?? $order['entity_id']),
            customerName: trim(($order['customer_firstname'] ?? '').' '.($order['customer_lastname'] ?? '')) ?: ($address ? trim(($address['firstname'] ?? '').' '.($address['lastname'] ?? '')) : null) ?: null,
            customerEmail: $order['customer_email'] ?? null,
            customerPhone: $address['telephone'] ?? ($order['billing_address']['telephone'] ?? null),
            shippingAddress: $address ? array_filter([
                'name' => trim(($address['firstname'] ?? '').' '.($address['lastname'] ?? '')),
                'company' => $address['company'] ?? null,
                'address1' => $address['street'][0] ?? null,
                'address2' => $address['street'][1] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['region'] ?? null,
                'zip' => $address['postcode'] ?? null,
                'country_code' => $address['country_id'] ?? null,
                'phone' => $address['telephone'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match ($state) {
                'processing', 'complete' => OrderData::PAYMENT_PAID,
                'closed' => OrderData::PAYMENT_REFUNDED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: $state === 'complete' ? OrderData::FULFILLMENT_FULFILLED : OrderData::FULFILLMENT_UNFULFILLED,
            cancelled: $state === 'canceled',
            total: (float) ($order['grand_total'] ?? 0),
            currency: (string) ($order['order_currency_code'] ?? 'USD'),
            note: null,
            placedAt: isset($order['created_at']) ? CarbonImmutable::parse($order['created_at'], 'UTC') : null,
            // Configurable lines come as a parent row (with the chosen child's SKU) plus a child row; only parents are kept.
            lines: array_values(array_map(fn (array $item) => new OrderLineData(
                externalVariantId: ($item['product_type'] ?? 'simple') === 'configurable' ? null : (string) $item['product_id'],
                sku: $item['sku'] ?? null,
                name: (string) $item['name'],
                quantity: (int) ($item['qty_ordered'] ?? 0),
                unitPrice: (float) ($item['price'] ?? 0),
                taxAmount: (float) ($item['tax_amount'] ?? 0),
                totalAmount: (float) ($item['row_total_incl_tax'] ?? 0),
                discountAmount: (float) ($item['discount_amount'] ?? 0),
                raw: $item,
            ), array_filter($order['items'] ?? [], fn (array $item) => empty($item['parent_item_id'])))),
            subtotal: isset($order['subtotal']) ? (float) $order['subtotal'] : null,
            shippingTotal: isset($order['shipping_amount']) ? (float) $order['shipping_amount'] : null,
            taxTotal: isset($order['tax_amount']) ? (float) $order['tax_amount'] : null,
            discountTotal: isset($order['discount_amount']) ? abs((float) $order['discount_amount']) : null,
            paymentMethod: $order['payment']['additional_information'][0] ?? $order['payment']['method'] ?? null,
            details: array_filter([
                'Status on Magento' => $order['status'] ?? null,
                'Shipping method' => $order['shipping_description'] ?? null,
                'Updated on Magento' => $order['updated_at'] ?? null,
            ]),
            raw: $order,
        );
    }
}
