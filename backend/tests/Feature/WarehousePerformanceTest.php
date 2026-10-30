<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CycleCount;
use App\Models\Order;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WarehousePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_measures_come_from_real_scans_shipments_and_counts(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $user->currentOrganization();
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $variant = Product::create(['organization_id' => $org->id, 'name' => 'Tee', 'sku' => 'TEE'])
            ->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'price' => 10]);

        // Two orders scanned at packing: one had a wrong unit caught at the pack scan.
        foreach ([[0, 2, 10], [1, 0, 60]] as $i => [$packErrors, $pickErrors, $hoursToShip]) {
            $order = Order::create([
                'organization_id' => $org->id, 'store_id' => $store->id, 'order_number' => "ORD-{$i}", 'customer_name' => 'Buyer',
                'order_status' => 'shipped', 'placed_at' => now()->subHours($hoursToShip + 2),
            ]);
            $order->items()->create(['product_variant_id' => $variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Tee', 'quantity' => 2, 'fulfilled_quantity' => 2, 'status' => 'shipped']);
            PickTask::create([
                'organization_id' => $org->id, 'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'shipped', 'items_count' => 2,
                'scan_progress' => ['pick' => [1 => 2], 'pack' => [1 => 2]], 'pick_errors' => $pickErrors, 'pack_errors' => $packErrors,
                'started_at' => now()->subHours(3), 'completed_at' => now()->subHours(2),
            ]);
            Shipment::create(['organization_id' => $org->id, 'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'shipped', 'shipped_at' => now()->subHours(2)]);
        }

        $count = CycleCount::create(['organization_id' => $org->id, 'warehouse_id' => $warehouse->id, 'count_number' => 'CC-1', 'title' => 'Weekly', 'status' => 'posted', 'created_by_user_id' => $user->id, 'posted_at' => now()->subDay()]);
        $count->items()->createMany([
            ['product_variant_id' => $variant->id, 'snapshot_quantity' => 10, 'counted_quantity' => 10, 'status' => 'approved'],
            ['product_variant_id' => $variant->id, 'snapshot_quantity' => 10, 'counted_quantity' => 9, 'variance_quantity' => -1, 'status' => 'approved'],
            ['product_variant_id' => $variant->id, 'snapshot_quantity' => 5, 'counted_quantity' => 5, 'status' => 'approved'],
            ['product_variant_id' => $variant->id, 'snapshot_quantity' => 5, 'counted_quantity' => 5, 'status' => 'approved'],
        ]);

        $bin = WarehouseLocation::create(['organization_id' => $org->id, 'warehouse_id' => $warehouse->id, 'code' => 'A-01', 'capacity' => 100, 'capacity_unit' => 'units']);
        app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 45, reason: 'Opening', locationId: $bin->id);

        $measures = collect($this->actingAs($user)->getJson("/api/v1/warehouses/{$warehouse->id}/performance")->assertOk()->json('data.measures'))->keyBy('key');

        $this->assertEquals(50, $measures['picking_accuracy']['value']);
        $this->assertFalse($measures['picking_accuracy']['meets']);
        // Shipped 10 h and 60 h after being placed: one within 48 h.
        $this->assertEquals(50, $measures['on_time_shipments']['value']);
        $this->assertEquals(35, $measures['total_cycle']['value']);
        $this->assertEquals(75, $measures['count_accuracy']['value']);
        $this->assertEquals(2, $measures['units_per_hour']['value']);
        $this->assertEquals(45, $measures['capacity_used']['value']);
        $this->assertEquals(100, $measures['fill_rate']['value']);
        $this->assertEquals(3, $measures['wrong_units_stopped']['value']);
        // No goods received notes were posted, so dock-to-stock has no value instead of a guessed one.
        $this->assertNull($measures['dock_to_stock']['value']);
    }

    public function test_a_wrong_scan_is_counted_against_the_order(): void
    {
        $user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $user->currentOrganization();
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $store->warehouses()->attach($warehouse->id, ['is_default' => true]);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Tee', 'sku' => 'TEE']);
        $tee = $product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'price' => 10]);
        $product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-L', 'price' => 10]);
        app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $warehouse->id, productVariantId: $tee->id, adjustmentType: 'add', quantity: 5, reason: 'Opening');

        $task = PickTask::where('order_id', $this->importOrder($store, $tee, 1)->id)->firstOrFail();
        $this->actingAs($user)->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'TEE-L'])->assertUnprocessable();

        $this->assertSame(1, $task->fresh()->pick_errors);
    }
}
