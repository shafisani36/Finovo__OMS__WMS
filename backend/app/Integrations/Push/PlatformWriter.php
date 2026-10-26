<?php

namespace App\Integrations\Push;

use App\Integrations\Concerns\SendsPlatformRequests;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use Illuminate\Support\Collection;

/**
 * What every store writer shares: request handling and turning Finovo products and orders into the
 * pieces a store needs (options, photos, names, amounts, linked IDs).
 */
abstract class PlatformWriter implements StoreWriter
{
    use SendsPlatformRequests;

    /**
     * Option names and each variant's values. Finovo keeps several options in one field ("Size / Color",
     * "M / Red"); they are split when every variant has the same number of parts, otherwise kept whole.
     * A product with one variant and no option value has no options.
     *
     * @return array{names: list<string>, values: array<int, list<string>>}
     */
    protected function options(Product $product): array
    {
        /** @var Collection<int, ProductVariant> $variants */
        $variants = $product->variants;

        if ($variants->count() === 1 && blank($variants->first()->option_value)) {
            return ['names' => [], 'values' => [$variants->first()->id => []]];
        }

        $names = array_map('trim', explode(' / ', (string) ($variants->first()->option_name ?: 'Option')));
        $values = $variants->mapWithKeys(fn (ProductVariant $variant) => [
            $variant->id => array_map('trim', explode(' / ', (string) ($variant->option_value ?: $variant->sku))),
        ]);

        if ($values->contains(fn (array $parts) => count($parts) !== count($names))) {
            $names = [(string) ($variants->first()->option_name ?: 'Option')];
            $values = $variants->mapWithKeys(fn (ProductVariant $variant) => [$variant->id => [(string) ($variant->option_value ?: $variant->sku)]]);
        }

        return ['names' => $names, 'values' => $values->all()];
    }

    /**
     * The product photo as an address the store can download (stores cannot reach a local Finovo).
     */
    protected function publicImageUrl(Product $product): ?string
    {
        $url = $product->getRawOriginal('image_url') ?: ($product->images[0] ?? null);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        return $url && str_starts_with($url, 'https://') && $host && ! in_array($host, ['localhost', '127.0.0.1'], true) ? $url : null;
    }

    /**
     * The store's ID for a Finovo variant, if the variant is linked to this store.
     */
    protected function variantExternalId(Store $store, ?int $variantId): ?string
    {
        return $variantId ? SkuMapping::where('store_id', $store->id)->where('product_variant_id', $variantId)->value('external_id') : null;
    }

    /**
     * The store's ID for a Finovo product, if the product is linked to this store.
     */
    protected function productExternalId(Store $store, ?int $productId): ?string
    {
        return $productId ? StoreProductLink::where('store_id', $store->id)->where('product_id', $productId)->value('external_id') : null;
    }

    /**
     * ["Sara", "Khan"] from "Sara Khan" (a single word is the last name, which stores require).
     *
     * @return array{0: string, 1: string}
     */
    protected function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2) ?: [];

        return count($parts) === 2 ? [$parts[0], $parts[1]] : ['', $parts[0] ?? ''];
    }

    protected function amount(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * The order's shipping address, or its billing address, with the customer's name and phone filled in.
     *
     * @return array<string, string>
     */
    protected function deliveryAddress(Order $order): array
    {
        $address = $order->shipping_address ?: ($order->billing_address ?: []);

        return array_filter([
            ...$address,
            'name' => $address['name'] ?? $order->customer_name,
            'phone' => $address['phone'] ?? $order->customer_phone,
        ], fn ($value) => filled($value));
    }
}
