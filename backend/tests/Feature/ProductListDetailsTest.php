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
}
