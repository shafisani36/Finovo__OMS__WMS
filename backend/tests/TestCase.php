<?php

namespace Tests;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\OrderAllocationEngine;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** A real 4×4 JPEG photo, so image tests do not need the GD extension. */
    private const SAMPLE_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgABAAEAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4uiiiv2s+PP/2Q==';

    protected function sampleJpeg(): string
    {
        return base64_decode(self::SAMPLE_JPEG);
    }

    /**
     * An order as a store import brings it in (orders are never typed into Finovo), allocated against stock.
     *
     * @param  array<string, mixed>  $attributes  order fields to override, e.g. customer_email
     */
    protected function importOrder(Store $store, ProductVariant $variant, int $quantity, float $unitPrice = 10.0, array $attributes = []): Order
    {
        $order = Order::create($attributes + [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'order_number' => '#'.Str::upper(Str::random(8)),
            'external_order_id' => (string) Str::uuid(),
            'customer_name' => 'Test Customer',
            'order_status' => 'imported',
            'total_amount' => $quantity * $unitPrice,
            'currency' => 'USD',
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'external_sku' => $variant->sku,
            'product_name' => $variant->product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $quantity * $unitPrice,
            'status' => 'pending',
        ]);

        return app(OrderAllocationEngine::class)->allocateOrder($order)->load('items');
    }
}
