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
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders, Products, Inventory and Picking show only the managed store's data when a store_id is given.
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
            $order = Order::create(['organization_id' => $orgId, 'store_id' => $store->id, 'order_number' => "#{$name}", 'external_order_id' => (string) $i, 'customer_name' => 'Buyer', 'placed_at' => now()]);
            $order->pickTasks()->create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'status' => 'not_started', 'items_count' => 1]);
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

        $this->actingAs($admin)->getJson("/api/v1/picking?store_id={$wix->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', '#Candle');

        // Stock figures match the table: only the managed store's products.
        $this->actingAs($admin)->getJson("/api/v1/inventory/kpis?store_id={$shopify->id}")
            ->assertOk()
            ->assertJsonPath('data.total_skus', 1)
            ->assertJsonPath('data.total_on_hand', 5);
        $this->actingAs($admin)->getJson('/api/v1/inventory/kpis')->assertJsonPath('data.total_on_hand', 10);

        $this->actingAs($admin)->getJson('/api/v1/orders')->assertJsonCount(2, 'data');
        $this->actingAs($admin)->getJson('/api/v1/picking')->assertJsonCount(2, 'data');
    }

    public function test_stock_movements_and_value_are_counted_per_store(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        [$store, $other] = array_map(fn (StorePlatform $platform) => Store::create([
            'organization_id' => $orgId, 'name' => $platform->label(), 'code' => $platform->value, 'platform' => $platform,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]), [StorePlatform::WIX, StorePlatform::SHOPIFY]);

        // A product imported from the first store, bought at 12 a unit.
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Desk Lamp', 'sku' => 'LAMP-1']);
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'LAMP-1', 'price' => 25, 'cost_price' => 12]);
        SkuMapping::create(['organization_id' => $orgId, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => 'lamp', 'external_sku' => 'LAMP-1']);
        app(InventoryEngine::class)->adjustStock($orgId, $warehouse->id, $variant->id, 'add', 4, 'Opening');

        $this->actingAs($admin)->getJson("/api/v1/inventory/kpis?store_id={$store->id}")->assertJsonPath('data.total_valuation', 48);
        $this->getJson("/api/v1/inventory/movements?store_id={$store->id}")->assertJsonCount(1, 'data')->assertJsonPath('kpis.total_products', 1);
        $this->getJson("/api/v1/inventory/movements?store_id={$other->id}")->assertJsonCount(0, 'data')->assertJsonPath('kpis.movements_this_month', 0);
        $this->getJson('/api/v1/inventory/movements?type=adjustment')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/movements?type=transfer')->assertJsonCount(0, 'data');
    }

    public function test_orders_and_products_typed_in_by_hand_must_be_complete(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);

        $this->actingAs($admin)->postJson('/api/v1/orders', ['customer_name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items', 'currency', 'payment_status']);
        $this->postJson('/api/v1/products', ['name' => 'X', 'sku' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants']);
    }
}
