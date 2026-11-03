<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 3PL client portal: a client's user sees only the stock, orders and shipments of that client's stores, and
 * cannot open anything of the warehouse staff.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected Store $ownStore;

    protected Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->client = Client::create(['organization_id' => $orgId, 'name' => 'Glow Brand', 'code' => 'GLOW']);
        $other = Client::create(['organization_id' => $orgId, 'name' => 'Other Brand', 'code' => 'OTH']);
        $this->ownStore = $this->store('Glow Shop', $this->client, $warehouse);
        $this->otherStore = $this->store('Other Shop', $other, $warehouse);

        foreach ([[$this->ownStore, 'SER-01', 12], [$this->otherStore, 'OTH-01', 7]] as [$store, $sku, $units]) {
            $variant = $this->variant($sku);
            SkuMapping::create(['organization_id' => $orgId, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => $sku, 'external_sku' => $sku]);
            InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => $units, 'available' => $units]);
            $this->importOrder($store, $variant, 1, 10, ['customer_name' => "{$sku} customer"]);
        }
    }

    public function test_a_client_sees_only_their_own_stock_and_orders(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)->getJson('/api/v1/portal/stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SER-01')
            ->assertJsonPath('data.0.on_hand', 12)
            ->assertJsonPath('client.name', 'Glow Brand');

        $this->getJson('/api/v1/portal/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer', 'SER-01 customer')
            ->assertJsonPath('data.0.store', 'Glow Shop');

        $this->getJson('/api/v1/portal/activity')->assertOk();
    }

    public function test_a_client_cannot_open_the_staff_screens(): void
    {
        $this->actingAs($this->clientUser());

        foreach (['/api/v1/stores', '/api/v1/warehouses', '/api/v1/orders', '/api/v1/inventory', '/api/v1/barcode/settings', "/api/v1/orders/{$this->otherStore->orders()->value('id')}"] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.role', 'client');
    }

    public function test_staff_cannot_use_the_portal(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/portal/stock')->assertForbidden();
    }

    public function test_an_admin_adds_a_client_user_linked_to_a_client(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'name' => 'Glow Viewer', 'email' => 'viewer@glow.test', 'role' => 'client', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        $this->postJson('/api/v1/employees', [
            'name' => 'Glow Viewer', 'email' => 'viewer@glow.test', 'role' => 'client', 'client_id' => $this->client->id, 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ])->assertCreated();

        $this->assertSame($this->client->id, User::where('email', 'viewer@glow.test')->value('client_id'));
    }

    private function clientUser(): User
    {
        $user = User::factory()->create(['status' => 'active', 'role' => UserRole::CLIENT, 'client_id' => $this->client->id]);
        $user->memberships()->create(['organization_id' => $this->admin->currentOrganization()->id, 'role' => UserRole::CLIENT->value]);
        $user->forceFill(['current_organization_id' => $this->admin->currentOrganization()->id])->save();

        return $user;
    }

    private function store(string $name, Client $client, Warehouse $warehouse): Store
    {
        $store = Store::create(['organization_id' => $client->organization_id, 'client_id' => $client->id, 'name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'platform' => 'manual', 'status' => 'active']);
        $store->warehouses()->attach($warehouse->id, ['is_default' => true]);

        return $store;
    }

    private function variant(string $sku): ProductVariant
    {
        return Product::create(['organization_id' => $this->admin->currentOrganization()->id, 'name' => $sku, 'sku' => $sku])
            ->variants()->create(['organization_id' => $this->admin->currentOrganization()->id, 'sku' => $sku, 'price' => 10]);
    }
}
