<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CycleCount;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cycle counts of one zone or bin (not the whole warehouse), and counts that start by themselves on a schedule.
 */
class CycleCountScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 06:00:00');

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 25]);

        foreach (['A01-R01-B01', 'A01-R02-B01', 'B01-R01-B01'] as $code) {
            $bin = WarehouseLocation::create([
                'organization_id' => $orgId, 'warehouse_id' => $this->warehouse->id, 'code' => $code,
                'location_type' => 'bin', 'putaway_eligible' => true, 'picking_eligible' => true, 'is_active' => true,
            ]);
            app(InventoryEngine::class)->adjustStock(
                organizationId: $orgId, warehouseId: $this->warehouse->id, productVariantId: $variant->id,
                adjustmentType: 'add', quantity: 5, reason: 'Opening', locationId: $bin->id,
            );
        }
        $this->actingAs($this->admin);
    }

    public function test_a_zone_count_covers_only_the_bins_of_that_zone(): void
    {
        $this->postJson('/api/v1/inventory/cycle-counts', ['warehouse_id' => $this->warehouse->id, 'title' => 'Zone A01', 'scope_type' => 'zone', 'scope_value' => 'A01'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.items');

        $this->postJson('/api/v1/inventory/cycle-counts', ['warehouse_id' => $this->warehouse->id, 'title' => 'One bin', 'scope_type' => 'location', 'scope_value' => 'B01-R01-B01'])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_a_scheduled_count_starts_when_due_and_again_after_its_interval(): void
    {
        $this->postJson('/api/v1/inventory/cycle-count-schedules', [
            'warehouse_id' => $this->warehouse->id, 'title' => 'Weekly zone A01', 'scope_type' => 'zone', 'scope_value' => 'A01',
            'every_days' => 7, 'next_run_on' => '2026-11-03',
        ])->assertCreated()->assertJsonPath('data.next_run_on', '2026-11-03');

        $this->artisan('cycle-counts:run-schedules')->assertSuccessful();
        $this->artisan('cycle-counts:run-schedules')->assertSuccessful();
        $this->assertSame(1, CycleCount::count());
        $this->assertSame(['Weekly zone A01', 2], [CycleCount::first()->title, CycleCount::first()->items()->count()]);

        $this->getJson('/api/v1/inventory/cycle-count-schedules')->assertOk()->assertJsonPath('data.0.next_run_on', '2026-11-10');

        $this->travelTo(Carbon::parse('2026-11-10 06:00:00'));
        $this->artisan('cycle-counts:run-schedules')->assertSuccessful();
        $this->assertSame(2, CycleCount::count());
    }

    public function test_a_schedule_can_be_removed(): void
    {
        $id = $this->postJson('/api/v1/inventory/cycle-count-schedules', [
            'warehouse_id' => $this->warehouse->id, 'title' => 'Monthly', 'scope_type' => 'full', 'every_days' => 30, 'next_run_on' => '2026-11-04',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/inventory/cycle-count-schedules/{$id}")->assertOk();
        $this->getJson('/api/v1/inventory/cycle-count-schedules')->assertOk()->assertJsonCount(0, 'data');
    }
}
