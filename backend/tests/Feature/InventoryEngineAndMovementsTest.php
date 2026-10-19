<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryEngineAndMovementsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $this->org = $this->user->currentOrganization();

        $this->warehouseA = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-A',
        ]);

        $this->warehouseB = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Secondary Warehouse',
            'code' => 'WH-B',
        ]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'name' => 'Wireless Keyboard',
            'sku' => 'KB-001',
        ]);

        $this->product->variants()->create([
            'organization_id' => $this->org->id,
            'sku' => 'KB-001',
            'price' => 49.99,
        ]);
    }

    public function test_stock_adjustment_creates_balance_and_ledger_entry(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'adjustment_type' => 'add',
            'quantity' => 100,
            'reason' => 'Initial inventory receipt',
        ]);

        $response->assertOk();

        $variant = $this->product->variants()->first();

        $this->assertDatabaseHas('inventory_balances', [
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouseA->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 100,
            'available' => 100,
        ]);

        $this->assertDatabaseHas('inventory_ledger_entries', [
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouseA->id,
            'product_variant_id' => $variant->id,
            'movement_type' => 'adjustment',
            'quantity' => 100,
            'balance_after' => 100,
        ]);
    }

    public function test_cannot_subtract_more_than_available_inventory(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/adjustments', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'adjustment_type' => 'subtract',
            'quantity' => 50,
            'reason' => 'Damaged goods',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_transfer_stock_between_warehouses_updates_both_and_creates_transfer_records(): void
    {
        // First add stock to Warehouse A
        $engine = app(InventoryEngine::class);
        $variant = $this->product->variants()->first();

        $engine->adjustStock(
            organizationId: $this->org->id,
            warehouseId: $this->warehouseA->id,
            productVariantId: $variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Stocking'
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/inventory/transfers', [
            'product_id' => $this->product->id,
            'from_warehouse_id' => $this->warehouseA->id,
            'to_warehouse_id' => $this->warehouseB->id,
            'quantity' => 20,
            'notes' => 'Seasonal transfer to north store',
        ]);

        $response->assertOk();

        // Warehouse A should have 30 left
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouseA->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 30,
            'available' => 30,
        ]);

        // Warehouse B should have 20
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouseB->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 20,
            'available' => 20,
        ]);

        // Check recent movements API
        $movements = $this->actingAs($this->user)->getJson('/api/v1/inventory/movements');
        $movements->assertOk()
            ->assertJsonPath('kpis.total_warehouses', 2)
            ->assertJsonPath('kpis.total_products', 1);
    }
}
