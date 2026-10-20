<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Authorization\WixAuthorization;
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
 * Reads products (Stores catalog V3), inventory items and eCommerce orders of a Wix site.
 */
class WixDataSource extends HttpDataSource
{
    private const API = 'https://www.wixapis.com/';

    private const PAGE_SIZE = 100;

    public function __construct(
        protected WixAuthorization $authorization
    ) {}

    protected function platform(): string
    {
        return 'Wix';
    }

    public function products(Store $store): iterable
    {
        $paging = ['limit' => self::PAGE_SIZE];

        while (true) {
            $response = $this->send(fn () => $this->api($store)->post('stores/v3/products/search', ['search' => ['cursorPaging' => $paging]]));
            $summaries = (array) $response->json('products');
            $stock = $this->stockByVariant($store, array_column($summaries, 'id'));

            foreach ($summaries as $summary) {
                // Search results leave out variants, so each product is read in full.
                $product = $this->send(fn () => $this->api($store)->get('stores/v3/products/'.$summary['id'].'?fields=PLAIN_DESCRIPTION&fields=VARIANT_OPTION_CHOICE_NAMES'))->json('product');

                yield $this->toProduct($product, $stock);
            }

            $next = $response->json('pagingMetadata.cursors.next');

            if (! $next || ! $response->json('pagingMetadata.hasNext', true)) {
                return;
            }

            $paging = ['limit' => self::PAGE_SIZE, 'cursor' => $next];
        }
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $search = [
            'sort' => [['fieldName' => 'updatedDate', 'order' => 'ASC']],
            'cursorPaging' => ['limit' => self::PAGE_SIZE],
        ];

        if ($updatedSince) {
            $search['filter'] = ['updatedDate' => ['$gt' => CarbonImmutable::instance($updatedSince)->utc()->format('Y-m-d\TH:i:s.v\Z')]];
        }

        while (true) {
            $response = $this->send(fn () => $this->api($store)->post('ecom/v1/orders/search', ['search' => $search]));

            foreach ((array) $response->json('orders') as $order) {
                yield $this->toOrder($order);
            }

            $next = $response->json('metadata.cursors.next');

            if (! $next || ! $response->json('metadata.hasNext', true)) {
                return;
            }

            // With a cursor Wix only accepts the cursor itself; filter and sort are carried inside it.
            $search = ['cursorPaging' => ['limit' => self::PAGE_SIZE, 'cursor' => $next]];
        }
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->baseUrl(self::API)->withHeaders($this->authorization->headersFor($store));
    }

    /**
     * Stock of each variant, summed over the site's locations (variants tracked by "in stock" only are skipped).
     *
     * @param  list<string>  $productIds
     * @return array<string, int>
     */
    private function stockByVariant(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $items = (array) $this->send(fn () => $this->api($store)->post('stores/v3/inventory-items/search', ['search' => [
            'filter' => ['productId' => ['$in' => $productIds]],
            'cursorPaging' => ['limit' => 1000],
        ]]))->json('inventoryItems');

        $stock = [];

        foreach ($items as $item) {
            if (! empty($item['trackQuantity'])) {
                $stock[$item['variantId']] = ($stock[$item['variantId']] ?? 0) + (int) ($item['quantity'] ?? 0);
            }
        }

        return $stock;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, int>  $stock
     */
    private function toProduct(array $product, array $stock): ProductData
    {
        return new ProductData(
            externalId: (string) $product['id'],
            name: (string) $product['name'],
            handle: $product['slug'] ?? null,
            description: self::plainText($product['plainDescription'] ?? null),
            category: null,
            imageUrl: $product['media']['main']['image']['url'] ?? null,
            isActive: (bool) ($product['visible'] ?? true),
            variants: array_map(function (array $variant) use ($stock) {
                $choices = array_filter(array_column($variant['choices'] ?? [], 'optionChoiceNames'));

                return new VariantData(
                    externalId: (string) $variant['id'],
                    sku: filled($variant['sku'] ?? null) ? $variant['sku'] : null,
                    barcode: filled($variant['barcode'] ?? null) ? $variant['barcode'] : null,
                    optionName: $choices ? implode(' / ', array_column($choices, 'optionName')) : null,
                    optionValue: $choices ? implode(' / ', array_column($choices, 'choiceName')) : null,
                    price: (float) ($variant['price']['actualPrice']['amount'] ?? 0),
                    costPrice: isset($variant['revenueDetails']['cost']['amount']) ? (float) $variant['revenueDetails']['cost']['amount'] : null,
                    stockQuantity: $stock[$variant['id']] ?? null,
                );
            }, $product['variantsInfo']['variants'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function toOrder(array $order): OrderData
    {
        $billing = $order['billingInfo']['contactDetails'] ?? [];
        $destination = $order['shippingInfo']['logistics']['shippingDestination'] ?? $order['recipientInfo'] ?? [];
        $address = $destination['address'] ?? null;
        $contact = $destination['contactDetails'] ?? $billing;

        return new OrderData(
            externalId: (string) $order['id'],
            number: '#'.($order['number'] ?? $order['id']),
            customerName: trim(($billing['firstName'] ?? '').' '.($billing['lastName'] ?? '')) ?: null,
            customerEmail: $order['buyerInfo']['email'] ?? null,
            customerPhone: ($billing['phone'] ?? null) ?: (($contact['phone'] ?? null) ?: null),
            shippingAddress: $address ? array_filter([
                'name' => trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? '')),
                'company' => $contact['company'] ?? null,
                'address1' => $address['addressLine1'] ?? $address['addressLine'] ?? null,
                'address2' => $address['addressLine2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['subdivisionFullname'] ?? $address['subdivision'] ?? null,
                'zip' => $address['postalCode'] ?? null,
                'country_code' => $address['country'] ?? null,
                'phone' => $contact['phone'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match ($order['paymentStatus'] ?? null) {
                'PAID' => OrderData::PAYMENT_PAID,
                'FULLY_REFUNDED', 'PARTIALLY_REFUNDED' => OrderData::PAYMENT_REFUNDED,
                'DECLINED', 'CANCELED' => OrderData::PAYMENT_FAILED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: match ($order['fulfillmentStatus'] ?? null) {
                'FULFILLED' => OrderData::FULFILLMENT_FULFILLED,
                'PARTIALLY_FULFILLED' => OrderData::FULFILLMENT_PARTIAL,
                default => OrderData::FULFILLMENT_UNFULFILLED,
            },
            cancelled: ($order['status'] ?? null) === 'CANCELED',
            total: (float) ($order['priceSummary']['total']['amount'] ?? 0),
            currency: (string) ($order['currency'] ?? 'USD'),
            note: filled($order['buyerNote'] ?? null) ? $order['buyerNote'] : null,
            placedAt: isset($order['createdDate']) ? CarbonImmutable::parse($order['createdDate']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: $line['catalogReference']['options']['variantId'] ?? null,
                sku: $line['physicalProperties']['sku'] ?? null,
                name: (string) ($line['productName']['original'] ?? $line['productName']['translated'] ?? 'Item'),
                quantity: (int) ($line['quantity'] ?? 0),
                unitPrice: (float) ($line['price']['amount'] ?? 0),
                taxAmount: (float) ($line['taxDetails']['totalTax']['amount'] ?? 0),
                totalAmount: (float) ($line['totalPriceAfterTax']['amount'] ?? 0),
            ), $order['lineItems'] ?? []),
        );
    }
}
