<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderAllocationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store can ship from several warehouses (in priority order) and a warehouse can serve several stores.
 */
class StoreWarehouseLinksTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $org;

    protected Warehouse $karachi;

    protected Warehouse $lahore;

    protected Warehouse $islamabad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->admin->currentOrganization();
        $this->karachi = $this->warehouse('Karachi DC', 'KHI');
        $this->lahore = $this->warehouse('Lahore DC', 'LHE');
        $this->islamabad = $this->warehouse('Islamabad DC', 'ISB');
    }

    public function test_a_store_is_connected_with_several_warehouses_in_priority_order(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/stores', [
            'name' => 'Walk-in Counter',
            'platform' => 'manual',
            'warehouse_ids' => [$this->lahore->id, $this->karachi->id],
        ])->assertCreated();

        $store = Store::where('name', 'Walk-in Counter')->firstOrFail();

        $this->assertSame([$this->lahore->id, $this->karachi->id], $store->warehouses->pluck('id')->all());
        $this->assertTrue((bool) $store->warehouses[0]->pivot->is_default);
        $this->assertFalse((bool) $store->warehouses[1]->pivot->is_default);
    }

    public function test_the_store_warehouses_can_be_changed_and_reordered(): void
    {
        $store = $this->store('Web Shop', [$this->karachi->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/stores/{$store->id}/warehouses", [
            'warehouse_ids' => [$this->islamabad->id, $this->karachi->id],
        ])->assertOk()
            ->assertJsonPath('message', 'Web Shop ships from Islamabad DC, Karachi DC (in this order).')
            ->assertJsonPath('data.warehouses.0.id', $this->islamabad->id);

        $this->assertSame([$this->islamabad->id, $this->karachi->id], $store->fresh()->warehouses->pluck('id')->all());

        // Orders are offered to the warehouses in the store's order.
        $order = Order::create([
            'organization_id' => $this->org->id,
            'store_id' => $store->id,
            'order_number' => 'FIN-1',
            'customer_name' => 'Ali',
            'order_status' => 'pending',
            'currency' => 'PKR',
        ]);
        $this->assertSame(
            [$this->islamabad->id, $this->karachi->id],
            app(OrderAllocationEngine::class)->eligibleWarehouses($order)->pluck('id')->all()
        );
    }

    public function test_a_warehouse_can_serve_several_stores(): void
    {
        $web = $this->store('Web Shop', [$this->karachi->id]);
        $counter = $this->store('Counter', [$this->karachi->id, $this->lahore->id]);
        $app = $this->store('Mobile App', []);

        $this->actingAs($this->admin)->putJson("/api/v1/warehouses/{$this->lahore->id}/stores", [
            'store_ids' => [$web->id, $app->id],
        ])->assertOk()->assertJsonCount(2, 'data');

        // Newly linked stores get the warehouse after the ones they already ship from.
        $this->assertSame([$this->karachi->id, $this->lahore->id], $web->fresh()->warehouses->pluck('id')->all());
        $this->assertSame([$this->lahore->id], $app->fresh()->warehouses->pluck('id')->all());
        $this->assertTrue((bool) $app->fresh()->warehouses[0]->pivot->is_default);

        // The unlinked store keeps its other warehouse, now as its main one.
        $counterWarehouses = $counter->fresh()->warehouses;
        $this->assertSame([$this->karachi->id], $counterWarehouses->pluck('id')->all());
        $this->assertTrue((bool) $counterWarehouses[0]->pivot->is_default);
    }

    public function test_warehouses_and_stores_of_another_company_are_refused(): void
    {
        $store = $this->store('Web Shop', [$this->karachi->id]);
        $outsider = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $foreignWarehouse = Warehouse::create([
            'organization_id' => $outsider->currentOrganization()->id,
            'name' => 'Elsewhere',
            'code' => 'ELS',
        ]);
        $foreignStore = Store::create([
            'organization_id' => $outsider->currentOrganization()->id,
            'name' => 'Other Shop',
            'code' => 'other-shop',
            'platform' => StorePlatform::MANUAL,
            'status' => Store::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/stores/{$store->id}/warehouses", ['warehouse_ids' => [$foreignWarehouse->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_ids');
        $this->actingAs($this->admin)->putJson("/api/v1/warehouses/{$this->karachi->id}/stores", ['store_ids' => [$foreignStore->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('store_ids');

        $this->assertSame([$this->karachi->id], $store->fresh()->warehouses->pluck('id')->all());
    }

    public function test_stores_needing_attention_are_counted_and_filtered(): void
    {
        $this->store('Healthy Shop', [$this->karachi->id]);
        $this->store('Failing Shop', [$this->karachi->id])->forceFill(['sync_status' => 'sync_issues'])->save();
        $this->store('Waiting Shop', [])->forceFill(['status' => Store::STATUS_SETUP_INCOMPLETE])->save();

        $this->actingAs($this->admin)->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('kpis.total_stores', 3)
            ->assertJsonPath('kpis.not_connected', 1)
            ->assertJsonPath('kpis.sync_issues', 1)
            ->assertJsonPath('kpis.needs_attention', 2);

        $names = collect($this->actingAs($this->admin)->getJson('/api/v1/stores?status=needs_attention')->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Failing Shop', 'Waiting Shop'], $names);
    }

    public function test_a_store_that_cannot_notify_finovo_is_not_counted_as_a_sync_issue(): void
    {
        config(['services.webhooks.base_url' => 'https://api.finovo.test']);
        $wix = $this->store('Wix Site', [$this->karachi->id]);
        $wix->forceFill(['platform' => StorePlatform::WIX, 'credentials' => ['site_id' => 'site-1', 'api_key' => 'key']])->save();

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$wix->id}/webhooks")
            ->assertOk()
            ->assertJsonPath('status', 'info')
            ->assertJsonPath('data.settings.webhooks.status', 'unavailable');

        $wix->refresh();
        $this->assertSame('healthy', $wix->sync_status);
        $this->assertNull($wix->connection_error);
    }

    private function warehouse(string $name, string $code): Warehouse
    {
        return Warehouse::create(['organization_id' => $this->org->id, 'name' => $name, 'code' => $code, 'is_active' => true]);
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    private function store(string $name, array $warehouseIds): Store
    {
        $store = Store::create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'code' => str($name)->slug()->toString(),
            'platform' => StorePlatform::MANUAL,
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
        ]);

        foreach (array_values($warehouseIds) as $index => $id) {
            $store->warehouses()->attach($id, ['is_default' => $index === 0, 'priority' => $index + 1]);
        }

        return $store;
    }
}
