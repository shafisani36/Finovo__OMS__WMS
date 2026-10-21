<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Store product photos are copied to Finovo's storage and used on pages and in invoice PDFs.
 */
class ProductImageStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_photos_are_saved_locally_and_replaced_when_the_store_changes_them(): void
    {
        Storage::fake(Product::IMAGE_DISK);
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $store = Store::create([
            'organization_id' => $orgId, 'name' => 'Woo', 'code' => 'woo', 'platform' => StorePlatform::WOOCOMMERCE,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
            'external_reference' => 'https://shop.test', 'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ]);

        $photo = 'https://shop.test/cap.jpg';
        Http::fake([
            // Reads $photo by reference, so the test can change the store's photo between syncs
            'https://shop.test/wp-json/wc/v3/products*' => function () use (&$photo) {
                return Http::response([
                    ['id' => 20, 'name' => 'Cap', 'slug' => 'cap', 'type' => 'simple', 'status' => 'publish', 'sku' => 'CAP-1', 'price' => '15', 'short_description' => '', 'categories' => [], 'images' => [['src' => $photo]]],
                ], 200, ['X-WP-TotalPages' => '1']);
            },
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/cap.jpg' => Http::response($this->sampleJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://shop.test/cap-new.jpg' => Http::response($this->sampleJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://shop.test/broken.png' => Http::response('<html>Not found</html>', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sync")->assertOk();

        $product = Product::where('sku', 'CAP-1')->sole();
        $path = $product->getAttributes()['image_path'];
        Storage::disk(Product::IMAGE_DISK)->assertExists($path);
        $this->assertSame(Storage::disk(Product::IMAGE_DISK)->url($path), $product->image_url);
        $this->assertSame($photo, $product->getRawOriginal('image_url'));

        // Syncing again does not download the same photo again
        $this->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sync")->assertOk();
        Http::assertSentCount(5); // 2 × (products + orders) + 1 photo

        // The store changes the photo: the old copy is replaced
        $photo = 'https://shop.test/cap-new.jpg';
        $this->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sync")->assertOk();
        $newPath = Product::where('sku', 'CAP-1')->sole()->getAttributes()['image_path'];
        $this->assertNotSame($path, $newPath);
        Storage::disk(Product::IMAGE_DISK)->assertMissing($path);
        Storage::disk(Product::IMAGE_DISK)->assertExists($newPath);

        // A link that returns a web page instead of a picture is not saved; the store's link is kept
        $photo = 'https://shop.test/broken.png';
        $this->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/sync")->assertOk();
        $product = Product::where('sku', 'CAP-1')->sole();
        $this->assertNull($product->getAttributes()['image_path']);
        $this->assertSame($photo, $product->image_url);
    }
}
