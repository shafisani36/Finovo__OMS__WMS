<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Order controls: hold and release, approval of high-value orders, customer credit limits, the order timeline
 * (audit history), splitting and merging orders, and orders at risk of missing the shipping deadline (SLA).
 */
class OrderControlsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Store $store;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($warehouse->id, ['is_default' => true]);
        $this->variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 100]);
        app(InventoryEngine::class)->adjustStock(organizationId: $orgId, warehouseId: $warehouse->id, productVariantId: $this->variant->id, adjustmentType: 'add', quantity: 50, reason: 'Opening');
        $this->actingAs($this->admin);
    }

    public function test_a_held_order_is_not_allocated_until_released_and_the_timeline_shows_it(): void
    {
        $order = $this->create(2);

        $this->postJson("/api/v1/orders/{$order['id']}/hold", ['reason' => 'Payment check'])->assertOk()->assertJsonPath('data.hold.type', 'manual');
        $this->postJson("/api/v1/orders/{$order['id']}/allocate")->assertUnprocessable()->assertJsonPath('message', "Order {$order['order_number']} is on hold: Payment check");

        $this->postJson("/api/v1/orders/{$order['id']}/release")->assertOk()->assertJsonPath('data.hold', null);
        $this->postJson("/api/v1/orders/{$order['id']}/allocate")->assertOk();

        $types = collect($this->getJson("/api/v1/orders/{$order['id']}/timeline")->assertOk()->json('data'))->pluck('type')->all();
        $this->assertSame(['created', 'held', 'released', 'status'], array_slice($types, 0, 4));
    }

    public function test_high_value_orders_wait_for_approval(): void
    {
        $this->putJson('/api/v1/orders/settings', ['approval_threshold' => 500, 'sla_hours' => 48])->assertOk();

        $small = $this->create(2);
        $large = $this->create(8);

        $this->assertNull(Order::find($small['id'])->hold_type);
        $this->assertSame('approval', Order::find($large['id'])->hold_type);

        $this->getJson('/api/v1/orders?hold=approval')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/orders/{$large['id']}/release")->assertOk()->assertJsonPath('message', "Order {$large['order_number']} approved.");
        $this->assertSame('approved', Order::find($large['id'])->events->last()->type);
    }

    public function test_an_order_over_the_customers_credit_limit_is_held(): void
    {
        $first = $this->create(3);
        $customer = Customer::find(Order::find($first['id'])->customer_id);
        $this->putJson("/api/v1/customers/{$customer->id}/credit-limit", ['credit_limit' => 400])->assertOk();

        $second = $this->create(2);

        $this->assertSame('credit', Order::find($second['id'])->hold_type);
        $this->assertStringContainsString('credit limit of 400', (string) Order::find($second['id'])->hold_reason);
    }

    public function test_an_order_is_split_and_orders_are_merged(): void
    {
        $order = $this->create(5);
        $itemId = Order::find($order['id'])->items()->value('id');

        $new = $this->postJson("/api/v1/orders/{$order['id']}/split", ['items' => [['item_id' => $itemId, 'quantity' => 2]]])
            ->assertCreated()
            ->assertJsonPath('data.order_number', $order['order_number'].'-2')
            ->json('data');

        $this->assertSame([3, 300.0], [(int) Order::find($order['id'])->items()->sum('quantity'), (float) Order::find($order['id'])->total_amount]);
        $this->assertSame([2, 200.0], [(int) Order::find($new['id'])->items()->sum('quantity'), (float) Order::find($new['id'])->total_amount]);

        $this->postJson('/api/v1/orders/merge', ['order_ids' => [$order['id'], $new['id']]])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order['order_number']);

        $this->assertSame([5, 500.0], [(int) Order::find($order['id'])->items()->sum('quantity'), (float) Order::find($order['id'])->total_amount]);
        $this->assertSame('cancelled', Order::find($new['id'])->order_status);
    }

    public function test_orders_past_the_shipping_deadline_are_flagged(): void
    {
        $this->putJson('/api/v1/orders/settings', ['approval_threshold' => null, 'sla_hours' => 24])->assertOk();
        $late = $this->create(1);
        $soon = $this->create(1);
        $fresh = $this->create(1);
        Order::whereKey($late['id'])->update(['placed_at' => now()->subHours(30)]);
        Order::whereKey($soon['id'])->update(['placed_at' => now()->subHours(20)]);

        $this->getJson('/api/v1/orders/sla')
            ->assertOk()
            ->assertJsonPath('data.sla_hours', 24)
            ->assertJsonPath('data.breached.0.order_number', $late['order_number'])
            ->assertJsonPath('data.at_risk.0.order_number', $soon['order_number'])
            ->assertJsonCount(1, 'data.breached')
            ->assertJsonCount(1, 'data.at_risk');
        $this->assertNotNull($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(int $quantity): array
    {
        return $this->postJson('/api/v1/orders', [
            'store_id' => $this->store->id,
            'customer_name' => 'Sara Khan',
            'customer_email' => 'sara@example.com',
            'currency' => 'USD',
            'payment_status' => 'pending',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => 100]],
        ])->assertCreated()->json('data');
    }
}
