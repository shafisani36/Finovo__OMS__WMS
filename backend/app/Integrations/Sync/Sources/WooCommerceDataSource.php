<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Sync\Contracts\FetchesSingleRecords;
use App\Integrations\Sync\Data\OrderData;
use App\Integrations\Sync\Data\OrderLineData;
use App\Integrations\Sync\Data\ProductData;
use App\Integrations\Sync\Data\VariantData;
use App\Integrations\WooCommerceClient;
use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\Response;

/**
 * Reads products, variations, stock and orders through the WooCommerce REST API (wc/v3).
 */
class WooCommerceDataSource extends HttpDataSource implements FetchesSingleRecords
{
    private const PER_PAGE = 50;

    public function __construct(
        protected WooCommerceClient $client
    ) {}

    protected function platform(): string
    {
        return 'WooCommerce';
    }

    public function products(Store $store): iterable
    {
        foreach ($this->pages($store, 'products', ['status' => 'any']) as $product) {
            if ($this->isStockedProduct($product)) {
                yield $this->toProduct($store, $product);
            }
        }
    }

    public function product(Store $store, string $externalId): ?ProductData
    {
        $response = $this->send(fn () => $this->client->get($store, "products/{$externalId}"), [404]);
        $product = $response->status() === 404 ? null : (array) $response->json();

        return $product && ($product['status'] ?? null) !== 'trash' && $this->isStockedProduct($product)
            ? $this->toProduct($store, $product)
            : null;
    }

    public function order(Store $store, string $externalId): ?OrderData
    {
        $response = $this->send(fn () => $this->client->get($store, "orders/{$externalId}"), [404]);

        return $response->status() === 404 ? null : $this->toOrder((array) $response->json());
    }

    /**
     * A grouped product is a list of others and an external one is sold elsewhere; neither is stock.
     *
     * @param  array<string, mixed>  $product
     */
    private function isStockedProduct(array $product): bool
    {
        return ! in_array($product['type'] ?? 'simple', ['grouped', 'external'], true);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function toProduct(Store $store, array $product): ProductData
    {
        $variations = ($product['type'] ?? null) === 'variable'
            ? iterator_to_array($this->pages($store, "products/{$product['id']}/variations"), false)
            : [];
        $variants = $variations
            ? array_map(fn (array $variation) => $this->toVariant($variation), $variations)
            : [$this->toVariant($product)];

        return new ProductData(
            externalId: (string) $product['id'],
            name: html_entity_decode((string) $product['name'], ENT_QUOTES | ENT_HTML5),
            handle: $product['slug'] ?? null,
            description: self::plainText($product['short_description'] ?: ($product['description'] ?? null)),
            category: $product['categories'][0]['name'] ?? null,
            imageUrl: $product['images'][0]['src'] ?? null,
            isActive: ($product['status'] ?? 'publish') === 'publish',
            variants: $variants,
            images: array_values(array_filter(array_column($product['images'] ?? [], 'src'))),
            details: array_filter([
                'Categories' => array_column($product['categories'] ?? [], 'name'),
                'Tags' => array_column($product['tags'] ?? [], 'name'),
                'Product type' => isset($product['type']) ? ucfirst((string) $product['type']) : null,
                'Highlights' => self::plainText($product['short_description'] ?? null),
                'Weight' => filled($product['weight'] ?? null) ? $product['weight'] : null,
                'Dimensions' => implode(' × ', array_filter([$product['dimensions']['length'] ?? null, $product['dimensions']['width'] ?? null, $product['dimensions']['height'] ?? null])) ?: null,
                'Stock status' => $product['stock_status'] ?? null,
                'Store page' => $product['permalink'] ?? null,
                'Created on WooCommerce' => $product['date_created_gmt'] ?? null,
                'Updated on WooCommerce' => $product['date_modified_gmt'] ?? null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            raw: [...$product, 'variations' => $variations],
        );
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $query = ['orderby' => 'date', 'order' => 'asc'];

        if ($updatedSince) {
            $query['modified_after'] = CarbonImmutable::instance($updatedSince)->utc()->format('Y-m-d\TH:i:s');
            $query['dates_are_gmt'] = 'true';
        }

        foreach ($this->pages($store, 'orders', $query) as $order) {
            if (($order['status'] ?? null) === 'checkout-draft') {
                continue; // Cart not checked out yet
            }

            yield $this->toOrder($order);
        }
    }

    /**
     * Every item of a paged list endpoint (WooCommerce reports the page count in X-WP-TotalPages).
     *
     * @param  array<string, scalar>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(Store $store, string $path, array $query = []): \Generator
    {
        $page = 1;

        do {
            /** @var Response $response */
            $response = $this->send(fn () => $this->client->get($store, $path, $query + ['per_page' => self::PER_PAGE, 'page' => $page]));

            yield from (array) $response->json();

            $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);
            $page++;
        } while ($page <= $totalPages);
    }

    /**
     * A variation, or a simple product acting as its own single variant.
     *
     * @param  array<string, mixed>  $item
     */
    private function toVariant(array $item): VariantData
    {
        $options = array_values(array_filter($item['attributes'] ?? [], fn (array $attribute) => isset($attribute['option'])));

        return new VariantData(
            externalId: (string) $item['id'],
            sku: filled($item['sku'] ?? null) ? trim($item['sku']) : null,
            barcode: filled($item['global_unique_id'] ?? null) ? $item['global_unique_id'] : null,
            optionName: $options ? implode(' / ', array_column($options, 'name')) : null,
            optionValue: $options ? implode(' / ', array_column($options, 'option')) : null,
            price: (float) (($item['price'] ?? '') !== '' ? $item['price'] : ($item['regular_price'] ?? 0)),
            costPrice: null,
            stockQuantity: ! empty($item['manage_stock']) && isset($item['stock_quantity']) ? (int) $item['stock_quantity'] : null,
            imageUrl: $item['image']['src'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function toOrder(array $order): OrderData
    {
        $billing = $order['billing'] ?? [];
        $shipping = array_filter($order['shipping'] ?? [], fn ($value) => filled($value));
        $address = filled($shipping['address_1'] ?? null) ? $shipping : $billing;
        $status = $order['status'] ?? 'pending';

        return new OrderData(
            externalId: (string) $order['id'],
            number: '#'.($order['number'] ?? $order['id']),
            customerName: trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: null,
            customerEmail: filled($billing['email'] ?? null) ? $billing['email'] : null,
            customerPhone: filled($billing['phone'] ?? null) ? $billing['phone'] : ($shipping['phone'] ?? null),
            shippingAddress: $this->toAddress($address),
            paymentStatus: match ($status) {
                'processing', 'completed' => OrderData::PAYMENT_PAID,
                'refunded' => OrderData::PAYMENT_REFUNDED,
                'failed' => OrderData::PAYMENT_FAILED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: $status === 'completed' ? OrderData::FULFILLMENT_FULFILLED : OrderData::FULFILLMENT_UNFULFILLED,
            cancelled: $status === 'cancelled',
            total: (float) ($order['total'] ?? 0),
            currency: (string) ($order['currency'] ?? 'USD'),
            note: filled($order['customer_note'] ?? null) ? $order['customer_note'] : null,
            placedAt: isset($order['date_created_gmt']) ? CarbonImmutable::parse($order['date_created_gmt'], 'UTC') : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: (string) (($line['variation_id'] ?? 0) ?: $line['product_id']),
                sku: filled($line['sku'] ?? null) ? $line['sku'] : null,
                name: (string) $line['name'],
                quantity: (int) $line['quantity'],
                unitPrice: (float) ($line['price'] ?? 0),
                taxAmount: (float) ($line['total_tax'] ?? 0),
                totalAmount: (float) ($line['total'] ?? 0) + (float) ($line['total_tax'] ?? 0),
                discountAmount: max(0, (float) ($line['subtotal'] ?? 0) - (float) ($line['total'] ?? 0)),
                imageUrl: $line['image']['src'] ?? null,
                raw: $line,
            ), $order['line_items'] ?? []),
            billingAddress: $this->toAddress($billing),
            subtotal: array_sum(array_map(fn (array $line) => (float) ($line['subtotal'] ?? 0), $order['line_items'] ?? [])),
            shippingTotal: (float) ($order['shipping_total'] ?? 0),
            taxTotal: (float) ($order['total_tax'] ?? 0),
            discountTotal: (float) ($order['discount_total'] ?? 0),
            paymentMethod: filled($order['payment_method_title'] ?? null) ? $order['payment_method_title'] : null,
            details: array_filter([
                'Status on WooCommerce' => ucfirst((string) $status),
                'Created via' => $order['created_via'] ?? null,
                'Transaction ID' => $order['transaction_id'] ?? null,
                'Shipping method' => implode(', ', array_column($order['shipping_lines'] ?? [], 'method_title')) ?: null,
                'Coupons' => array_column($order['coupon_lines'] ?? [], 'code'),
                'Paid on WooCommerce' => $order['date_paid_gmt'] ?? null,
                'Updated on WooCommerce' => $order['date_modified_gmt'] ?? null,
            ], fn ($value) => $value !== null && $value !== '' && $value !== []),
            raw: $order,
            finovoOrderId: collect($order['meta_data'] ?? [])->firstWhere('key', OrderData::FINOVO_REFERENCE_KEY)['value'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, string>|null
     */
    private function toAddress(array $address): ?array
    {
        if (blank($address['address_1'] ?? null)) {
            return null;
        }

        return array_filter([
            'name' => trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')),
            'company' => $address['company'] ?? null,
            'address1' => $address['address_1'] ?? null,
            'address2' => $address['address_2'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['state'] ?? null,
            'zip' => $address['postcode'] ?? null,
            'country_code' => $address['country'] ?? null,
            'phone' => $address['phone'] ?? null,
        ], fn ($value) => filled($value)) ?: null;
    }
}
