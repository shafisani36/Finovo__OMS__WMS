<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders, Products and Inventory show only the managed store's data when a store_id is given.
 */
class StoreScopedListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_can_be_limited_to_one_store(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        [$wix, $shopify] = array_map(fn (StorePlatform $platform) => Store::create([
            'organization_id' => $orgId, 'name' => $platform->label(), 'code' => $platform->value, 'platform' => $platform,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]), [StorePlatform::WIX, StorePlatform::SHOPIFY]);

        foreach ([[$wix, 'Candle'], [$shopify, 'Tee']] as $i => [$store, $name]) {
            $product = Product::create(['organization_id' => $orgId, 'name' => $name, 'sku' => strtoupper($name)]);
            $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => strtoupper($name).'-1', 'price' => 10]);
            SkuMapping::create(['organization_id' => $orgId, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => (string) $i, 'external_sku' => $variant->sku]);
            InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 5, 'available' => 5]);
            Order::create(['organization_id' => $orgId, 'store_id' => $store->id, 'order_number' => "#{$name}", 'external_order_id' => (string) $i, 'customer_name' => 'Buyer', 'placed_at' => now()]);
        }

        $this->actingAs($admin)->getJson("/api/v1/orders?store_id={$wix->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', '#Candle')
            ->assertJsonPath('kpis.total_orders', 1);

        $this->actingAs($admin)->getJson("/api/v1/products?store_id={$wix->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Candle');

        $this->actingAs($admin)->getJson("/api/v1/inventory?store_id={$shopify->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson('/api/v1/orders')->assertJsonCount(2, 'data');
    }
}
