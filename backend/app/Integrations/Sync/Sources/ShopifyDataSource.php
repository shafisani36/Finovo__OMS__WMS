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
 * Reads products, variants, stock and orders through the Shopify GraphQL Admin API.
 * Page sizes keep every query under Shopify's 1,000-point query cost limit.
 */
class ShopifyDataSource extends HttpDataSource
{
    private const PRODUCTS_PER_PAGE = 20;

    private const VARIANTS_PER_PAGE = 40;

    private const ORDERS_PER_PAGE = 20;

    private const LINES_PER_ORDER = 40;

    private const VARIANT_FIELDS = 'legacyResourceId sku barcode price inventoryQuantity selectedOptions { name value } inventoryItem { unitCost { amount } }';

    /** Fields Shopify treats as protected customer data; only returned once the app is approved for them. */
    private const CUSTOMER_FIELDS = 'email phone customer { firstName lastName } shippingAddress { name company address1 address2 city province zip country countryCodeV2 phone }';

    public function products(Store $store): iterable
    {
        $query = 'query($cursor: String) { products(first: '.self::PRODUCTS_PER_PAGE.', after: $cursor) {
            pageInfo { hasNextPage endCursor }
            nodes {
                id legacyResourceId title handle description productType status
                featuredMedia { preview { image { url } } }
                variants(first: '.self::VARIANTS_PER_PAGE.') { pageInfo { hasNextPage endCursor } nodes { '.self::VARIANT_FIELDS.' } }
            }
        } }';

        $cursor = null;

        do {
            $page = $this->query($store, $query, ['cursor' => $cursor])['products'];

            foreach ($page['nodes'] as $node) {
                yield $this->toProduct($node, $this->allVariants($store, $node));
            }

            $cursor = $page['pageInfo']['endCursor'];
        } while ($page['pageInfo']['hasNextPage']);
    }

    public function orders(Store $store, ?DateTimeInterface $updatedSince): iterable
    {
        $filter = $updatedSince ? "updated_at:>'".CarbonImmutable::instance($updatedSince)->utc()->format('Y-m-d\TH:i:s\Z')."'" : null;
        $withCustomerData = true;
        $cursor = null;

        while (true) {
            try {
                $page = $this->query($store, $this->ordersQuery($withCustomerData), ['cursor' => $cursor, 'query' => $filter])['orders'];
            } catch (ShopifyCustomerDataDenied) {
                // Read the orders without customer details rather than not at all.
                $withCustomerData = false;
                $this->warnings[] = 'Shopify withheld customer names, emails and addresses. Request "Protected customer data" access for the Finovo app in the Shopify Partner Dashboard (App → API access), then sync again.';

                continue;
            }

            foreach ($page['nodes'] as $node) {
                yield $this->toOrder($node);
            }

            if (! $page['pageInfo']['hasNextPage']) {
                return;
            }

            $cursor = $page['pageInfo']['endCursor'];
        }
    }

    protected function platform(): string
    {
        return 'Shopify';
    }

    private function ordersQuery(bool $withCustomerData): string
    {
        return 'query($cursor: String, $query: String) { orders(first: '.self::ORDERS_PER_PAGE.', after: $cursor, query: $query, sortKey: UPDATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
                legacyResourceId name createdAt cancelledAt displayFinancialStatus displayFulfillmentStatus note
                currentTotalPriceSet { shopMoney { amount currencyCode } }
                '.($withCustomerData ? self::CUSTOMER_FIELDS : '').'
                lineItems(first: '.self::LINES_PER_ORDER.') { nodes {
                    name sku quantity
                    variant { legacyResourceId }
                    originalUnitPriceSet { shopMoney { amount } }
                    discountedTotalSet { shopMoney { amount } }
                    taxLines { priceSet { shopMoney { amount } } }
                } }
            }
        } }';
    }

    /**
     * Products with more variants than fit in the first page get the rest fetched separately.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function allVariants(Store $store, array $node): array
    {
        $variants = $node['variants']['nodes'];
        $pageInfo = $node['variants']['pageInfo'];

        while ($pageInfo['hasNextPage']) {
            $page = $this->query($store, 'query($id: ID!, $cursor: String) { product(id: $id) {
                variants(first: '.self::VARIANTS_PER_PAGE.', after: $cursor) { pageInfo { hasNextPage endCursor } nodes { '.self::VARIANT_FIELDS.' } }
            } }', ['id' => $node['id'], 'cursor' => $pageInfo['endCursor']])['product']['variants'];

            array_push($variants, ...$page['nodes']);
            $pageInfo = $page['pageInfo'];
        }

        return $variants;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $variants
     */
    private function toProduct(array $node, array $variants): ProductData
    {
        return new ProductData(
            externalId: (string) $node['legacyResourceId'],
            name: (string) $node['title'],
            handle: $node['handle'] ?? null,
            description: filled($node['description'] ?? null) ? $node['description'] : null,
            category: filled($node['productType'] ?? null) ? $node['productType'] : null,
            imageUrl: $node['featuredMedia']['preview']['image']['url'] ?? null,
            isActive: ($node['status'] ?? 'ACTIVE') === 'ACTIVE',
            variants: array_map(fn (array $variant) => $this->toVariant($variant), $variants),
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function toVariant(array $node): VariantData
    {
        // Shopify gives single-variant products one option "Title" = "Default Title"; that is not a real option.
        $options = array_values(array_filter(
            $node['selectedOptions'] ?? [],
            fn (array $option) => ! ($option['name'] === 'Title' && $option['value'] === 'Default Title'),
        ));
        $cost = $node['inventoryItem']['unitCost']['amount'] ?? null;

        return new VariantData(
            externalId: (string) $node['legacyResourceId'],
            sku: filled($node['sku'] ?? null) ? trim($node['sku']) : null,
            barcode: filled($node['barcode'] ?? null) ? $node['barcode'] : null,
            optionName: $options ? implode(' / ', array_column($options, 'name')) : null,
            optionValue: $options ? implode(' / ', array_column($options, 'value')) : null,
            price: (float) $node['price'],
            costPrice: $cost !== null ? (float) $cost : null,
            stockQuantity: isset($node['inventoryQuantity']) ? (int) $node['inventoryQuantity'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function toOrder(array $node): OrderData
    {
        $customer = $node['customer'] ?? null;
        $address = $node['shippingAddress'] ?? null;
        $customerName = trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')) ?: ($address['name'] ?? null);

        return new OrderData(
            externalId: (string) $node['legacyResourceId'],
            number: (string) $node['name'],
            customerName: $customerName ?: null,
            customerEmail: $node['email'] ?? null,
            customerPhone: $node['phone'] ?? $address['phone'] ?? null,
            shippingAddress: $address ? array_filter([
                'name' => $address['name'] ?? null,
                'company' => $address['company'] ?? null,
                'address1' => $address['address1'] ?? null,
                'address2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['province'] ?? null,
                'zip' => $address['zip'] ?? null,
                'country' => $address['country'] ?? null,
                'country_code' => $address['countryCodeV2'] ?? null,
                'phone' => $address['phone'] ?? null,
            ], fn ($value) => filled($value)) : null,
            paymentStatus: match ($node['displayFinancialStatus'] ?? null) {
                'PAID' => OrderData::PAYMENT_PAID,
                'REFUNDED', 'PARTIALLY_REFUNDED' => OrderData::PAYMENT_REFUNDED,
                'VOIDED' => OrderData::PAYMENT_FAILED,
                default => OrderData::PAYMENT_PENDING,
            },
            fulfillmentStatus: match ($node['displayFulfillmentStatus'] ?? null) {
                'FULFILLED' => OrderData::FULFILLMENT_FULFILLED,
                'PARTIALLY_FULFILLED' => OrderData::FULFILLMENT_PARTIAL,
                default => OrderData::FULFILLMENT_UNFULFILLED,
            },
            cancelled: filled($node['cancelledAt'] ?? null),
            total: (float) ($node['currentTotalPriceSet']['shopMoney']['amount'] ?? 0),
            currency: (string) ($node['currentTotalPriceSet']['shopMoney']['currencyCode'] ?? 'USD'),
            note: filled($node['note'] ?? null) ? $node['note'] : null,
            placedAt: isset($node['createdAt']) ? CarbonImmutable::parse($node['createdAt']) : null,
            lines: array_map(fn (array $line) => new OrderLineData(
                externalVariantId: isset($line['variant']['legacyResourceId']) ? (string) $line['variant']['legacyResourceId'] : null,
                sku: filled($line['sku'] ?? null) ? $line['sku'] : null,
                name: (string) $line['name'],
                quantity: (int) $line['quantity'],
                unitPrice: (float) ($line['originalUnitPriceSet']['shopMoney']['amount'] ?? 0),
                taxAmount: array_sum(array_map(fn (array $tax) => (float) ($tax['priceSet']['shopMoney']['amount'] ?? 0), $line['taxLines'] ?? [])),
                totalAmount: (float) ($line['discountedTotalSet']['shopMoney']['amount'] ?? 0),
            ), $node['lineItems']['nodes'] ?? []),
        );
    }

    /**
     * Run a GraphQL query (rate limits are retried by send()).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function query(Store $store, string $query, array $variables): array
    {
        $url = 'https://'.$store->external_reference.'/admin/api/'.config('services.shopify.api_version').'/graphql.json';

        $response = $this->send(fn () => Http::acceptJson()
            ->timeout(30)
            ->withHeaders(['X-Shopify-Access-Token' => (string) $store->credential('access_token')])
            ->post($url, ['query' => $query, 'variables' => $variables]));

        $errors = $response->json('errors');

        if (! empty($errors)) {
            $message = is_array($errors) ? (string) ($errors[0]['message'] ?? json_encode($errors)) : (string) $errors;

            if (str_contains($message, 'protected-customer-data') || str_contains($message, 'not approved to access')) {
                throw new ShopifyCustomerDataDenied($message);
            }

            throw new RuntimeException("Shopify said: {$message}");
        }

        return (array) $response->json('data');
    }

    protected function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        $errors = $response->json('errors');

        return is_array($errors) && ($errors[0]['extensions']['code'] ?? null) === 'THROTTLED';
    }
}
