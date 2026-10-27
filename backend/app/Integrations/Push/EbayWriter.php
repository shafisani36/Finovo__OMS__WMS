<?php

namespace App\Integrations\Push;

use App\Integrations\Authorization\EbayAuthorization;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Services\StoreSyncOutbox;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes to an eBay seller account: inventory items (Inventory API), shipments with tracking (Fulfillment API),
 * cancellations (Post-Order API) and quantities. eBay orders are placed by buyers only, so none are created from
 * Finovo, and an inventory item is listed for sale (category, price and policies) on eBay itself.
 */
class EbayWriter extends PlatformWriter implements ProductWriter
{
    public function __construct(
        protected EbayAuthorization $authorization
    ) {}

    protected function platform(): string
    {
        return 'eBay';
    }

    public function createOrder(Store $store, Order $order): string
    {
        throw new RuntimeException('eBay orders can only be placed by buyers on eBay, so Finovo cannot create this order there.');
    }

    /**
     * eBay keeps the buyer's details and payment as the buyer entered them.
     */
    public function updateOrder(Store $store, Order $order): void {}

    /**
     * eBay orders have no warehouse steps between paid and shipped, so there is nothing to show yet.
     */
    public function updateStatus(Store $store, Order $order): void {}

    /**
     * A seller cancellation through the Post-Order API (eBay refunds a paid buyer). Not available in eBay's sandbox.
     */
    public function cancelOrder(Store $store, Order $order): void
    {
        $current = $this->order($store, $order);

        if (($current['cancelStatus']['cancelState'] ?? null) === 'CANCELED') {
            return;
        }

        $paid = ($current['orderPaymentStatus'] ?? null) === 'PAID';

        $this->call(fn () => $this->api($store, postOrder: true)->post('post-order/v2/cancellation', array_filter([
            'legacyOrderId' => $order->external_order_id,
            'cancelReason' => 'OUT_OF_STOCK_OR_CANNOT_FULFILL',
            'buyerPaid' => $paid,
            'buyerPaidDate' => $paid && isset($current['paymentSummary']['payments'][0]['paymentDate'])
                ? ['value' => $current['paymentSummary']['payments'][0]['paymentDate']]
                : null,
        ], fn ($value) => $value !== null)));
    }

    public function setStock(Store $store, LinkedVariant $variant, int $quantity): void
    {
        $result = $this->call(fn () => $this->api($store)->post('sell/inventory/v1/bulk_update_price_quantity', ['requests' => [[
            'sku' => $variant->sku ?? $variant->externalId,
            'shipToLocationAvailability' => ['quantity' => $quantity],
        ]]]));

        $failure = collect($result['responses'] ?? [])->first(fn (array $response) => (int) ($response['statusCode'] ?? 200) >= 400);

        if ($failure) {
            throw new RuntimeException('eBay said: '.($failure['errors'][0]['message'] ?? 'the quantity was not accepted.'));
        }
    }

    /**
     * A shipping fulfillment for the unshipped lines with the courier and tracking number; eBay tells the buyer.
     */
    public function fulfill(Store $store, Order $order, Shipment $shipment): ?string
    {
        $current = $this->order($store, $order);

        if (($current['orderFulfillmentStatus'] ?? null) === 'FULFILLED') {
            return null;
        }

        $response = $this->send(fn () => $this->api($store)->post("sell/fulfillment/v1/order/{$order->external_order_id}/shipping_fulfillment", [
            'lineItems' => array_map(fn (array $line) => ['lineItemId' => $line['lineItemId'], 'quantity' => (int) $line['quantity']], $current['lineItems'] ?? []),
            'shippedDate' => ($shipment->shipped_at ?? now())->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'shippingCarrierCode' => $shipment->carrier ?: 'Other',
            'trackingNumber' => $shipment->tracking_number,
        ]));

        // eBay answers with the new fulfillment's address: .../shipping_fulfillment/{fulfillmentId}
        $location = $response->header('Location');

        return $location ? basename(parse_url($location, PHP_URL_PATH) ?: $location) : null;
    }

    /**
     * eBay learns of the delivery from the courier's tracking.
     */
    public function reportDelivered(Store $store, Order $order, Shipment $shipment): void {}

    /**
     * eBay returns are opened by the buyer on eBay, so a return taken in Finovo is recorded in Finovo only.
     */
    public function reportReturn(Store $store, Order $order, OrderReturn $return): void {}

    /**
     * One inventory item per variant, keyed by its SKU (as Finovo imports eBay items).
     */
    public function saveProduct(Store $store, Product $product, ?string $externalId): PushedProduct
    {
        $single = $product->variants->count() === 1;
        $image = $this->publicImageUrl($product);
        $ids = [];

        foreach ($product->variants as $variant) {
            $this->send(fn () => $this->api($store)
                ->withHeaders(['Content-Language' => 'en-US'])
                ->put('sell/inventory/v1/inventory_item/'.rawurlencode($variant->sku), [
                    'condition' => 'NEW',
                    'availability' => ['shipToLocationAvailability' => [
                        'quantity' => app(StoreSyncOutbox::class)->publishableQuantity($store, $variant->id),
                    ]],
                    'product' => array_filter([
                        'title' => mb_substr($single ? $product->name : trim($product->name.' - '.($variant->option_value ?: $variant->sku)), 0, 80),
                        'description' => $product->description ?: null,
                        'imageUrls' => $image ? [$image] : null,
                        'upc' => $variant->barcode ? [$variant->barcode] : null,
                    ]),
                ]));
            $ids[$variant->id] = $variant->sku;
        }

        return new PushedProduct($ids[$product->variants->first()->id], $ids);
    }

    public function deleteProduct(Store $store, string $externalId): void
    {
        $productId = StoreProductLink::where('store_id', $store->id)->where('external_id', $externalId)->value('product_id');
        $skus = SkuMapping::where('store_id', $store->id)
            ->whereIn('product_variant_id', ProductVariant::where('product_id', $productId)->pluck('id'))
            ->pluck('external_id');

        foreach ($skus->push($externalId)->unique() as $sku) {
            $this->send(fn () => $this->api($store)->delete('sell/inventory/v1/inventory_item/'.rawurlencode((string) $sku)), [404]);
        }
    }

    /**
     * The Post-Order API takes the same user token with "IAF" in front instead of "Bearer".
     */
    private function api(Store $store, bool $postOrder = false): PendingRequest
    {
        $request = Http::acceptJson()->asJson()->timeout(30)->baseUrl($this->authorization->apiBase().'/');
        $token = $this->authorization->accessToken($store);

        return $postOrder ? $request->withHeaders(['Authorization' => "IAF {$token}"]) : $request->withToken($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Store $store, Order $order): array
    {
        return $this->call(fn () => $this->api($store)->get("sell/fulfillment/v1/order/{$order->external_order_id}"));
    }

    /**
     * @param  Closure(): Response  $request
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private function call(Closure $request): array
    {
        return (array) $this->send($request)->json();
    }
}
