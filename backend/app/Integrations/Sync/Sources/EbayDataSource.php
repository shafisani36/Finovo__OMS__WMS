<?php

namespace App\Integrations\Sync\Sources;

use App\Integrations\Authorization\EbayAuthorization;
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
 * Reads inventory items (with their offer price) from the eBay Sell Inventory API and orders from the Fulfillment API.
 * Listings created on eBay outside the Inventory API are not visible to it; their orders still import.
 */
class EbayDataSource extends HttpDataSource
{
    private const ITEMS_PER_PAGE = 100;

    private const ORDERS_PER_PAGE = 50;

    private int $itemCount = 0;

    public function __construct(
        protected EbayAuthorization $authorization
    ) {}

    protected function platform(): string
    {
        return 'eBay';
    }

    public function products(Store $store): iterable
    {
        for ($offset = 0; ; $offset += self::ITEMS_PER_PAGE) {
            $response = $this->send(fn () => $this->api($store)->get('sell/inventory/v1/inventory_item', [
                'limit' => self::ITEMS_PER_PAGE,
                'offset' => $offset,
            ]));
            $items = (array) $response->json('inventoryItems');

            foreach ($items as $item) {
                $this->itemCount++;

                yield $this->toProduct($item, $this->price($store, (string) $item['sku']));
            }

            if ($offset + self::ITEMS_PER_PAGE >= (int) $response->json('total', 0)) {
                return;
            }
        }
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $filter = $updatedSince
            ? ['filter' => 'lastmodifieddate:['.CarbonImmutable::instance($updatedSince)->utc()->format('Y-m-d\TH:i:s.v\Z').'..]']
            : [];

        for ($offset = 0; ; $offset += self::ORDERS_PER_PAGE) {
            $response = $this->send(fn () => $this->api($store)->get('sell/fulfillment/v1/order', $filter + [
                'limit' => self::ORDERS_PER_PAGE,
                'offset' => $offset,
            ]));
            $orders = (array) $response->json('orders');

            foreach ($orders as $order) {
                yield $this->toOrder($order);
            }

            if ($offset + self::ORDERS_PER_PAGE >= (int) $response->json('total', 0)) {
                break;
            }
        }

        if ($this->itemCount === 0) {
            $this->warnings[] = 'eBay returned no Inventory API listings, so no products were imported. Listings created directly on eBay are not available to the Inventory API; their orders are still imported.';
        }
    }

    private function api(Store $store): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->baseUrl($this->authorization->apiBase().'/')
            ->withToken($this->authorization->accessToken($store));
    }

    /**
     * Selling price from the item's offer; items without a published offer have no price yet.
     */
    private function price(Store $store, string $sku): float
    {
        $response = $this->send(fn () => $this->api($store)->get('sell/inventory/v1/offer', ['sku' => $sku]), [400, 404]);

        return (float) ($response->json('offers.0.pricingSummary.price.value') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toProduct(array $item, float $price): ProductData
    {
        $product = $item['product'] ?? [];
        $sku = (string) $item['sku'];

        return new ProductData(
            externalId: $sku,
            name: (string) ($product['title'] ?? $sku),
            handle: $sku,
            description: self::plainText($product['description'] ?? null),
            category: null,
            imageUrl: $product['imageUrls'][0] ?? null,
            isActive: true,
            variants: [new VariantData(
                externalId: $sku,
                sku: $sku,
                barcode: $product['upc'][0] ?? $product['ean'][0] ?? null,
                optionName: null,
                optionValue: null,
                price: $price,
                costPrice: null,
                stockQuantity: isset($item['availability']['shipToLocationAvailability']['quantity'])
                    ? (int) $item['availability']['shipToLocationAvailability']['quantity']
                    : null,
            )],
        );
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function toOrder(array $order): OrderData
    {
        $shipTo = $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
        $address = $shipTo['contactAddress'] ?? null;
        $buyer = $order['buyer']['buyerRegistrationAddress'] ?? [];

        return new OrderData(
            externalId: (string) $order['orderId'],
            number: (string) $order['orderId'],
            customerName: ($shipTo['fullName'] ?? null) ?: (($buyer['fullName'] ?? null) ?: ($order['buyer']['username'] ?? null)),
            customerEmail: ($shipTo['email'] ?? null) ?: ($buyer['email'] ?? null),
            customerPhone: $shipTo['primaryPhone']['phoneNumber'] ?? $buyer['primaryPhone']['phoneNumber'] ?? null,
            shippingAddress: $address ? array_filter([
                'name' => $shipTo['fullName'] ?? null,
                'address1' => $address['addressLine1'] ?? null,
                'address2' => $address['addressLine2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['stateOrProvince'] ?? null,
                'zip' => $address['postalCode'] ?? null,
                'country_code' => $address['countryCode'] ?? null,
                'phone' => $shipTo['primaryPhone']['phoneNumber'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match ($order['orderPaymentStatus'] ?? null) {
                'PAID' => OrderData::PAYMENT_PAID,
                'FULLY_REFUNDED', 'PARTIALLY_REFUNDED' => OrderData::PAYMENT_REFUNDED,
                'FAILED' => OrderData::PAYMENT_FAILED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: match ($order['orderFulfillmentStatus'] ?? null) {
                'FULFILLED' => OrderData::FULFILLMENT_FULFILLED,
                'IN_PROGRESS' => OrderData::FULFILLMENT_PARTIAL,
                default => OrderData::FULFILLMENT_UNFULFILLED,
            },
            cancelled: ($order['cancelStatus']['cancelState'] ?? null) === 'CANCELED',
            total: (float) ($order['pricingSummary']['total']['value'] ?? 0),
            currency: (string) ($order['pricingSummary']['total']['currency'] ?? 'USD'),
            note: filled($order['buyerCheckoutNotes'] ?? null) ? $order['buyerCheckoutNotes'] : null,
            placedAt: isset($order['creationDate']) ? CarbonImmutable::parse($order['creationDate']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: filled($line['sku'] ?? null) ? $line['sku'] : null,
                sku: filled($line['sku'] ?? null) ? $line['sku'] : null,
                name: (string) ($line['title'] ?? 'eBay item'),
                quantity: (int) ($line['quantity'] ?? 0),
                unitPrice: (int) ($line['quantity'] ?? 0) > 0 ? round((float) ($line['lineItemCost']['value'] ?? 0) / (int) $line['quantity'], 2) : 0.0,
                taxAmount: array_sum(array_map(fn (array $tax) => (float) ($tax['amount']['value'] ?? 0), $line['taxes'] ?? [])),
                totalAmount: (float) ($line['total']['value'] ?? $line['lineItemCost']['value'] ?? 0),
            ), $order['lineItems'] ?? []),
        );
    }
}
