<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAllocationAndPickingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected Store $store;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $this->org = $this->user->currentOrganization();

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Fulfillment Hub',
            'code' => 'HUB-1',
        ]);

        $this->store = Store::create([
            'organization_id' => $this->org->id,
            'name' => 'Online Store',
            'code' => 'STORE-1',
            'platform' => 'shopify',
            'status' => 'active',
        ]);

        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'name' => 'Gaming Mouse',
            'sku' => 'GM-01',
        ]);

        $variant = $this->product->variants()->create([
            'organization_id' => $this->org->id,
            'sku' => 'GM-01',
            'price' => 59.00,
        ]);

        // Stock initial 50 units
        app(InventoryEngine::class)->adjustStock(
            organizationId: $this->org->id,
            warehouseId: $this->warehouse->id,
            productVariantId: $variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Initial Inbound'
        );
    }

    public function test_can_create_order_auto_allocate_and_create_pick_task(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/orders', [
            'store_id' => $this->store->id,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 59.00,
                ],
            ],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('orders', [
            'organization_id' => $this->org->id,
            'customer_name' => 'Jane Doe',
            'order_status' => 'allocated',
        ]);

        // Available stock should be 48, reserved 2
        $variant = $this->product->variants()->first();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
            'reserved' => 2,
            'available' => 48,
        ]);

        // Pick task should be created
        $this->assertDatabaseHas('pick_tasks', [
            'organization_id' => $this->org->id,
            'status' => 'not_started',
            'items_count' => 2,
        ]);

        // List picking page API
        $pickList = $this->actingAs($this->user)->getJson('/api/v1/picking');
        $pickList->assertOk()
            ->assertJsonPath('kpis.pending_orders', 1)
            ->assertJsonPath('kpis.items_to_pick', 2);
    }

    public function test_quick_complete_advances_pick_task(): void
    {
        $order = \App\Models\Order::create([
            'organization_id' => $this->org->id,
            'store_id' => $this->store->id,
            'order_number' => 'ORD-TEST-1',
            'external_order_id' => 'EXT-1',
            'customer_name' => 'Alice',
            'order_status' => 'allocated',
        ]);

        $pickTask = PickTask::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'not_started',
            'items_count' => 1,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/picking/{$pickTask->id}/complete");
        $response->assertOk();

        $this->assertEquals('in_progress', $pickTask->fresh()->status);
    }

    public function test_cancel_order_releases_reserved_stock(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/orders', [
            'store_id' => $this->store->id,
            'customer_name' => 'John Smith',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_price' => 59.00,
                ],
            ],
        ]);

        $orderId = $response->json('data.id');

        // Cancel order
        $cancel = $this->actingAs($this->user)->postJson("/api/v1/orders/{$orderId}/cancel");
        $cancel->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'order_status' => 'cancelled',
        ]);

        // Reserved should be back to 0, available back to 50
        $variant = $this->product->variants()->first();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $variant->id,
            'reserved' => 0,
            'available' => 50,
        ]);
    }
}
