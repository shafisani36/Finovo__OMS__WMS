<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-docking: when a delivery brings stock that orders are waiting for, those orders are allocated at once and
 * their pick tasks marked urgent, so the units go from the dock to packing instead of to a shelf first.
 */
class CrossDockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_delivery_allocates_the_orders_waiting_for_its_stock(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $store->warehouses()->attach($warehouse->id, ['is_default' => true]);
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 25]);

        $waiting = $this->importOrder($store, $variant, 3);
        $tooBig = $this->importOrder($store, $variant, 50);
        $this->actingAs($admin)->postJson("/api/v1/orders/{$waiting->id}/allocate")->assertOk();
        $this->assertSame('exception', $waiting->fresh()->order_status);

        $receipt = $this->postJson('/api/v1/inventory/receipts', [
            'warehouse_id' => $warehouse->id, 'supplier_name' => 'Supplier',
            'items' => [['product_variant_id' => $variant->id, 'expected_quantity' => 5]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/inventory/receipts/{$receipt['id']}/confirm", [
            'items' => [['item_id' => $receipt['items'][0]['id'], 'received_quantity' => 5]],
        ])
            ->assertOk()
            ->assertJsonPath('cross_dock', [$waiting->order_number]);

        $this->assertSame('allocated', $waiting->fresh()->order_status);
        $this->assertSame('urgent', PickTask::where('order_id', $waiting->id)->value('priority'));
        $this->assertNotSame('allocated', $tooBig->fresh()->order_status);
    }
}
