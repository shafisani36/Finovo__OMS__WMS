<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreAndWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $this->org = $this->user->currentOrganization();
    }

    public function test_can_create_and_list_warehouses(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/warehouses', [
            'name' => 'Main Distribution Center',
            'code' => 'MDC-01',
            'city' => 'Dallas',
            'priority' => 1,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('warehouses', [
            'organization_id' => $this->org->id,
            'name' => 'Main Distribution Center',
            'code' => 'MDC-01',
        ]);

        $list = $this->actingAs($this->user)->getJson('/api/v1/warehouses');
        $list->assertOk()
            ->assertJsonPath('kpis.total_warehouses', 1);
    }

    public function test_can_create_store_with_linked_warehouses_and_retrieve_kpis(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'North Warehouse',
            'code' => 'NW-01',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stores', [
            'name' => 'Shopify Retail Store',
            'platform' => 'shopify',
            'warehouse_ids' => [$warehouse->id],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('stores', [
            'organization_id' => $this->org->id,
            'name' => 'Shopify Retail Store',
            'platform' => 'shopify',
        ]);

        $list = $this->actingAs($this->user)->getJson('/api/v1/stores');
        $list->assertOk()
            ->assertJsonPath('kpis.total_stores', 1)
            ->assertJsonPath('kpis.connected_stores', 1)
            ->assertJsonPath('kpis.sync_issues', 0);
    }

    public function test_custom_bridge_store_generates_secure_api_credentials(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/stores', [
            'name' => 'Custom PHP Ecommerce',
            'platform' => 'custom_bridge',
            'bridge_url' => 'https://example.com/api',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['credentials' => ['api_key', 'api_secret']]);

        $this->assertDatabaseHas('stores', [
            'organization_id' => $this->org->id,
            'platform' => 'custom_bridge',
        ]);
    }
}
