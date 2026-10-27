<?php

namespace Tests\Feature;

use App\Models\InventoryLocationBalance;
use App\Models\Organization;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickingScanAndWaveTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected Store $store;

    protected ProductVariant $mouse;

    protected ProductVariant $keyboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $this->org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Hub', 'code' => 'HUB-1']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Shop', 'code' => 'S-1', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);

        $this->mouse = $this->variant('Mouse', 'M-1', '8901234567890', 'B-02-01');
        $this->keyboard = $this->variant('Keyboard', 'K-1', '8909876543210', 'A-01-03');
    }

    public function test_pick_and_pack_are_verified_unit_by_unit(): void
    {
        $order = $this->importOrder($this->store, $this->mouse, 2);
        $task = PickTask::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->user);

        $this->getJson("/api/v1/picking/{$task->id}/lines")
            ->assertOk()
            ->assertJsonPath('data.stage', 'pick')
            ->assertJsonPath('data.units', 2)
            ->assertJsonPath('data.lines.0.location', 'B-02-01');

        // The wrong product is refused and nothing is counted.
        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => '8909876543210'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', "K-1 is not in order {$order->order_number}. Put it back.");

        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => '8901234567890'])->assertOk()->assertJsonPath('completed', false);
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame('picking', $order->fresh()->order_status);

        // A printed label is scanned as its SKU.
        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'M-1 | Mouse | Price 10.00'])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('picked', $task->fresh()->status);

        // Packing checks every unit again; one too many is refused.
        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'M-1'])->assertOk()->assertJsonPath('data.stage', 'pack');
        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'M-1'])->assertOk()->assertJsonPath('completed', true);
        $this->assertSame('packed', $task->fresh()->status);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => 'pending']);

        $this->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'M-1'])->assertUnprocessable();
    }

    public function test_moving_a_task_back_clears_its_scans(): void
    {
        $order = $this->importOrder($this->store, $this->mouse, 1);
        $task = PickTask::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->user)->postJson("/api/v1/picking/{$task->id}/scan", ['code' => 'M-1'])->assertOk();

        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'not_started'])->assertOk();

        $this->assertNull($task->fresh()->scan_progress);
        $this->getJson("/api/v1/picking/{$task->id}/lines")->assertJsonPath('data.scanned', 0);
    }

    public function test_wave_lists_items_in_bin_order_and_puts_each_unit_in_its_tote(): void
    {
        $first = $this->importOrder($this->store, $this->mouse, 1);
        $second = $this->importOrder($this->store, $this->mouse, 1);
        $second->items()->create(['product_variant_id' => $this->keyboard->id, 'external_sku' => 'K-1', 'product_name' => 'Keyboard', 'quantity' => 1, 'status' => 'pending']);
        $taskIds = PickTask::whereIn('order_id', [$first->id, $second->id])->orderBy('id')->pluck('id')->all();
        $this->actingAs($this->user);

        $this->postJson('/api/v1/picking/waves', ['task_ids' => [$taskIds[0]]])->assertUnprocessable();

        $wave = $this->postJson('/api/v1/picking/waves', ['task_ids' => $taskIds])
            ->assertCreated()
            ->assertJsonPath('data.units', 3)
            // A-01-03 (keyboard) comes before B-02-01 (mouse) on the walk.
            ->assertJsonPath('data.lines.0.sku', 'K-1')
            ->assertJsonPath('data.lines.1.sku', 'M-1')
            ->assertJsonPath('data.lines.1.quantity', 2)
            ->json('data');

        $this->postJson('/api/v1/picking/waves', ['task_ids' => $taskIds])->assertUnprocessable();
        $this->getJson('/api/v1/picking')->assertJsonPath('data.0.wave_number', $wave['wave_number']);

        $this->postJson("/api/v1/picking/waves/{$wave['id']}/scan", ['code' => 'M-1'])->assertOk()->assertJsonPath('tote', 1);
        $this->postJson("/api/v1/picking/waves/{$wave['id']}/scan", ['code' => 'M-1'])->assertOk()->assertJsonPath('tote', 2);
        $this->postJson("/api/v1/picking/waves/{$wave['id']}/scan", ['code' => 'M-1'])->assertUnprocessable();
        $this->postJson("/api/v1/picking/waves/{$wave['id']}/scan", ['code' => 'K-1'])
            ->assertOk()
            ->assertJsonPath('tote', 2)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(['picked', 'picked'], PickTask::whereIn('id', $taskIds)->orderBy('id')->pluck('status')->all());
        $this->getJson('/api/v1/picking/waves')->assertJsonCount(0, 'data');
    }

    public function test_cancelled_wave_returns_its_orders_to_the_list(): void
    {
        $taskIds = collect([1, 2])
            ->map(fn () => PickTask::where('order_id', $this->importOrder($this->store, $this->mouse, 1)->id)->value('id'))
            ->all();
        $this->actingAs($this->user);
        $waveId = $this->postJson('/api/v1/picking/waves', ['task_ids' => $taskIds])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/picking/waves/{$waveId}")->assertOk();

        $this->assertSame(0, PickTask::whereIn('id', $taskIds)->whereNotNull('pick_wave_id')->count());
        $this->postJson('/api/v1/picking/waves', ['task_ids' => $taskIds])->assertCreated();
    }

    public function test_forecast_projects_stock_out_and_order_quantity(): void
    {
        // 30 units sold in the last 30 days is 1 a day; 20 of the 50 are left to sell.
        foreach (range(1, 3) as $i) {
            $this->importOrder($this->store, $this->mouse, 10);
        }

        $row = $this->actingAs($this->user)->getJson('/api/v1/inventory/replenishment/forecast?days=30&cover_days=30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame('M-1', $row['sku']);
        $this->assertSame(30, $row['sold']);
        $this->assertEquals(1, $row['daily_rate']);
        $this->assertSame(20, $row['available']);
        $this->assertEquals(20, $row['days_of_cover']);
        // Lead time 7 + cover 30 = 37 days at 1 a day, 20 in stock.
        $this->assertSame(17, $row['suggested_quantity']);
        $this->assertSame('order_soon', $row['status']);
    }

    private function variant(string $name, string $sku, string $barcode, string $bin): ProductVariant
    {
        $product = Product::create(['organization_id' => $this->org->id, 'name' => $name, 'sku' => $sku]);
        $variant = $product->variants()->create(['organization_id' => $this->org->id, 'sku' => $sku, 'barcode' => $barcode, 'price' => 10]);
        app(InventoryEngine::class)->adjustStock(organizationId: $this->org->id, warehouseId: $this->warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 50, reason: 'Opening');

        $location = WarehouseLocation::create(['organization_id' => $this->org->id, 'warehouse_id' => $this->warehouse->id, 'code' => $bin]);
        InventoryLocationBalance::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $this->warehouse->id,
            'warehouse_location_id' => $location->id,
            'product_variant_id' => $variant->id,
            'condition' => 'available',
            'quantity' => 50,
        ]);

        return $variant;
    }
}
