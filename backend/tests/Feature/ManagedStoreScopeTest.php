<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two stores share one warehouse; every list for the managed store (?store_id=) shows only that store's work.
 */
class ManagedStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected Store $counter;

    protected Store $kiosk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Shared Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->counter = $this->store('Counter');
        $this->kiosk = $this->store('Kiosk');
    }

    public function test_a_product_added_for_a_finovo_only_store_is_listed_only_there(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/products', [
            'name' => 'Vitamin C Serum',
            'sku' => 'SER-01',
            'variants' => [['sku' => 'SER-01', 'price' => 2500]],
            'store_ids' => [$this->counter->id],
        ])->assertCreated()->assertJsonPath('message', 'Vitamin C Serum was added.');

        $this->assertSame(['Vitamin C Serum'], $this->names('/api/v1/products?store_id='.$this->counter->id));
        $this->assertSame([], $this->names('/api/v1/products?store_id='.$this->kiosk->id));
    }

    public function test_orders_are_listed_for_their_own_store_even_in_a_shared_warehouse(): void
    {
        $variant = $this->variant('Lip Balm', 'LIP-04');

        foreach ([$this->counter, $this->kiosk] as $store) {
            $this->actingAs($this->admin)->postJson('/api/v1/orders', [
                'store_id' => $store->id,
                'customer_name' => "{$store->name} customer",
                'payment_status' => 'paid',
                'currency' => 'PKR',
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 450]],
            ])->assertCreated();
        }

        $orders = $this->actingAs($this->admin)->getJson('/api/v1/orders?store_id='.$this->kiosk->id)->assertOk();
        $this->assertSame(['Kiosk customer'], collect($orders->json('data'))->pluck('customer_name')->all());
        $orders->assertJsonPath('kpis.total_orders', 1);

        // Selling a product in a Finovo-only store puts it in that store's catalog.
        $this->assertSame(['Lip Balm'], $this->names('/api/v1/products?store_id='.$this->kiosk->id));
    }

    public function test_warehouse_work_is_counted_for_the_managed_store_only(): void
    {
        foreach ([[$this->counter, 2], [$this->kiosk, 1]] as [$store, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $order = Order::create([
                    'organization_id' => $this->org->id,
                    'store_id' => $store->id,
                    'order_number' => "{$store->name}-{$i}",
                    'customer_name' => 'Ali',
                    'order_status' => 'allocated',
                    'currency' => 'PKR',
                ]);
                PickTask::create([
                    'organization_id' => $this->org->id,
                    'order_id' => $order->id,
                    'warehouse_id' => $this->warehouse->id,
                    'status' => 'not_started',
                ]);
            }
        }

        $this->actingAs($this->admin)->getJson("/api/v1/mobile/warehouses/{$this->warehouse->id}/summary?store_id={$this->counter->id}")
            ->assertOk()->assertJsonPath('data.to_pick', 2);
        $this->actingAs($this->admin)->getJson("/api/v1/mobile/warehouses/{$this->warehouse->id}/summary?store_id={$this->kiosk->id}")
            ->assertOk()->assertJsonPath('data.to_pick', 1);
        $this->actingAs($this->admin)->getJson("/api/v1/picking?warehouse_id={$this->warehouse->id}&store_id={$this->kiosk->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_orders_and_products_of_no_store_can_be_given_to_a_finovo_only_store(): void
    {
        $this->variant('Sunscreen', 'SUN-02');
        Order::create([
            'organization_id' => $this->org->id,
            'order_number' => 'FIN-1001',
            'customer_name' => 'Walk-in',
            'order_status' => 'pending',
            'currency' => 'PKR',
        ]);

        $this->artisan('stores:adopt', ['store' => $this->counter->id, '--dry-run' => true])
            ->expectsOutput('1 orders and 1 products would be given to Counter.')
            ->assertSuccessful();
        $this->assertSame([], $this->names('/api/v1/products?store_id='.$this->counter->id));

        $this->artisan('stores:adopt', ['store' => $this->counter->id])->assertSuccessful();

        $this->assertSame(['Sunscreen'], $this->names('/api/v1/products?store_id='.$this->counter->id));
        $this->actingAs($this->admin)->getJson('/api/v1/orders?store_id='.$this->counter->id)->assertJsonPath('kpis.total_orders', 1);
    }

    public function test_product_figures_follow_the_managed_store(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/products', [
            'name' => 'Serum', 'sku' => 'SER-01', 'variants' => [['sku' => 'SER-01', 'price' => 2500]], 'store_ids' => [$this->counter->id],
        ])->assertCreated();
        $this->variant('Not in any store', 'NONE-1');

        $this->actingAs($this->admin)->getJson('/api/v1/products?store_id='.$this->counter->id)
            ->assertJsonPath('kpis.total_products', 1)
            ->assertJsonPath('kpis.active_products', 1);
        $this->actingAs($this->admin)->getJson('/api/v1/products?per_page=100000')->assertJsonPath('meta.per_page', 200);
    }

    private function store(string $name): Store
    {
        $store = Store::create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'code' => strtolower($name),
            'platform' => StorePlatform::MANUAL,
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
        ]);
        $store->warehouses()->attach($this->warehouse->id, ['is_default' => true, 'priority' => 1]);

        return $store;
    }

    private function variant(string $name, string $sku): ProductVariant
    {
        $product = Product::create(['organization_id' => $this->org->id, 'name' => $name, 'sku' => $sku]);

        return $product->variants()->create(['organization_id' => $this->org->id, 'sku' => $sku, 'price' => 100]);
    }

    /**
     * @return list<string>
     */
    private function names(string $url): array
    {
        return collect($this->actingAs($this->admin)->getJson($url)->assertOk()->json('data'))->pluck('name')->all();
    }
}
