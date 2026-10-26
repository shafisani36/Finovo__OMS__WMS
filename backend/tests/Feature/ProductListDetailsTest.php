<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_list_includes_image_variants_with_stock_and_stores(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $store = Store::create([
            'organization_id' => $orgId, 'name' => 'Demo Presta', 'code' => 'presta', 'platform' => StorePlatform::PRESTASHOP,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Cushion', 'sku' => 'CUSH', 'image_url' => 'https://shop.test/img/1.jpg']);

        foreach (['Black' => 4, 'White' => 2] as $colour => $qty) {
            $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => "CUSH-{$colour}", 'option_name' => 'Color', 'option_value' => $colour, 'price' => 18.9]);
            InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => $qty, 'available' => $qty]);
            SkuMapping::create(['organization_id' => $orgId, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => $colour, 'external_sku' => $variant->sku]);
        }

        $this->actingAs($admin)->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', 'https://shop.test/img/1.jpg')
            ->assertJsonPath('data.0.variants_count', 2)
            ->assertJsonPath('data.0.variants.0.option_name', 'Color')
            ->assertJsonPath('data.0.variants.0.option_value', 'Black')
            ->assertJsonPath('data.0.variants.0.stock.available', 4)
            ->assertJsonPath('data.0.stock.on_hand', 6)
            ->assertJsonCount(1, 'data.0.stores')
            ->assertJsonPath('data.0.stores.0.name', 'Demo Presta');
    }

    public function test_product_page_shows_everything_known_about_the_product(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $karachi = Warehouse::create(['organization_id' => $orgId, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $lahore = Warehouse::create(['organization_id' => $orgId, 'name' => 'Lahore Hub', 'code' => 'LHR-01']);
        $store = Store::create([
            'organization_id' => $orgId, 'name' => 'Demo Shopify', 'code' => 'shop', 'platform' => StorePlatform::SHOPIFY,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
        $product = Product::create([
            'organization_id' => $orgId, 'name' => 'Cushion', 'sku' => 'CUSH', 'description' => 'Soft cushion',
            'image_url' => 'https://cdn.test/front.jpg', 'images' => ['https://cdn.test/front.jpg', 'https://cdn.test/back.jpg'],
            'details' => ['Vendor' => 'Finovo Home', 'Tags' => ['soft']],
            'platform_data' => [(string) $store->id => ['id' => 'gid://shopify/Product/1', 'title' => 'Cushion']],
        ]);
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'CUSH-BLK', 'barcode' => '8901234567890', 'option_name' => 'Color', 'option_value' => 'Black', 'price' => 18.9]);
        InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $karachi->id, 'product_variant_id' => $variant->id, 'on_hand' => 5, 'available' => 4, 'reserved' => 1]);
        InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $lahore->id, 'product_variant_id' => $variant->id, 'on_hand' => 3, 'available' => 3]);
        SkuMapping::create(['organization_id' => $orgId, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => '901', 'external_sku' => 'CUSH-BLK']);
        $this->importOrder($store, $variant, 2, 18.9);

        $this->actingAs($admin)->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.images', ['https://cdn.test/front.jpg', 'https://cdn.test/back.jpg'])
            ->assertJsonPath('data.details.Vendor', 'Finovo Home')
            ->assertJsonPath('data.stock.on_hand', 8)
            ->assertJsonCount(2, 'data.warehouses')
            ->assertJsonPath('data.variants.0.barcode', '8901234567890')
            ->assertJsonPath('data.variants.0.warehouses.1.name', 'Lahore Hub')
            ->assertJsonPath('data.stores.0.name', 'Demo Shopify')
            ->assertJsonPath('data.stores.0.platform_data.title', 'Cushion')
            ->assertJsonPath('data.sales.units', 2);

        // Another company's product is not found.
        $other = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->actingAs($other)->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }
}
