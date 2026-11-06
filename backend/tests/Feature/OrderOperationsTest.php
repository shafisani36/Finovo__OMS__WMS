<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecurringOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bulk actions and CSV import, the company's own order statuses, the customer self-service page, recurring
 * orders, and refunds or replacements for returns.
 */
class OrderOperationsTest extends TestCase
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
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 10]);
        Product::create(['organization_id' => $orgId, 'name' => 'Cream', 'sku' => 'CRM-02'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'CRM-02', 'price' => 5]);
        app(InventoryEngine::class)->adjustStock(organizationId: $orgId, warehouseId: $warehouse->id, productVariantId: $this->variant->id, adjustmentType: 'add', quantity: 50, reason: 'Opening');
        $this->actingAs($this->admin);
    }

    public function test_bulk_actions_report_each_order(): void
    {
        $ids = [$this->create(1)['id'], $this->create(2)['id'], $this->create(3)['id']];

        $this->postJson('/api/v1/orders/bulk', ['action' => 'hold', 'order_ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/orders/bulk', ['action' => 'hold', 'order_ids' => $ids, 'reason' => 'Stock audit'])->assertOk()->assertJsonCount(3, 'data.done');

        $this->postJson('/api/v1/orders/bulk', ['action' => 'allocate', 'order_ids' => $ids])
            ->assertOk()
            ->assertJsonCount(0, 'data.done')
            ->assertJsonCount(3, 'data.failed')
            ->assertJsonPath('data.failed.0.message', 'Order '.Order::find($ids[0])->order_number.' is on hold: Stock audit');

        $this->postJson('/api/v1/orders/bulk', ['action' => 'release', 'order_ids' => $ids])->assertOk()->assertJsonCount(3, 'data.done');
        $this->postJson('/api/v1/orders/bulk', ['action' => 'allocate', 'order_ids' => $ids])->assertOk()->assertJsonCount(3, 'data.done');
        $this->assertSame(['allocated'], Order::whereIn('id', $ids)->distinct()->pluck('order_status')->all());

        $this->postJson('/api/v1/orders/bulk', ['action' => 'cancel', 'order_ids' => [$ids[0]]])->assertOk()->assertJsonPath('message', '1 order(s) done.');
        $this->assertSame('cancelled', Order::find($ids[0])->order_status);
    }

    public function test_orders_are_imported_from_a_csv_file_all_or_nothing(): void
    {
        $header = "order_ref,customer_name,customer_email,sku,quantity,unit_price,currency,payment_status\n";
        $bad = $header."A1,Sara Khan,sara@example.com,SER-01,2,10,PKR,paid\nA1,Sara Khan,sara@example.com,NOPE-9,1,5,PKR,paid\n";

        $this->post('/api/v1/orders/import', ['store_id' => $this->store->id, 'file' => $this->csv($bad)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'Row 3: no product has SKU NOPE-9.');
        $this->assertSame(0, Order::count());

        $good = $header."A1,Sara Khan,sara@example.com,SER-01,2,10,PKR,paid\nA1,Sara Khan,sara@example.com,crm-02,1,5,PKR,paid\nB7,Ali Raza,,SER-01,1,10,PKR,pending\n";
        $this->post('/api/v1/orders/import', ['store_id' => $this->store->id, 'file' => $this->csv($good)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('message', '2 orders were imported.');

        $sara = Order::where('customer_name', 'Sara Khan')->firstOrFail();
        $this->assertSame([2, 25.0, 'paid', $this->store->id], [$sara->items()->count(), (float) $sara->total_amount, $sara->payment_status, $sara->store_id]);
    }

    public function test_the_company_defines_its_own_order_statuses(): void
    {
        $order = $this->create(1);
        $statuses = [['key' => 'awaiting_stock', 'label' => 'Awaiting stock', 'color' => 'amber'], ['key' => 'gift_wrap', 'label' => 'Gift wrap', 'color' => 'purple']];
        $this->putJson('/api/v1/orders/statuses', ['statuses' => $statuses])->assertOk();
        $this->getJson('/api/v1/orders/statuses')->assertOk()->assertJsonPath('data', $statuses);

        $this->putJson("/api/v1/orders/{$order['id']}/custom-status", ['custom_status' => 'unknown'])->assertUnprocessable();
        $this->putJson("/api/v1/orders/{$order['id']}/custom-status", ['custom_status' => 'awaiting_stock'])->assertOk();
        $this->getJson('/api/v1/orders?custom_status=awaiting_stock')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('custom_status', Order::find($order['id'])->events->last()->type);

        // A status still on an order cannot be removed.
        $this->putJson('/api/v1/orders/statuses', ['statuses' => [$statuses[1]]])->assertUnprocessable();
        $this->putJson("/api/v1/orders/{$order['id']}/custom-status", ['custom_status' => null])->assertOk();
        $this->putJson('/api/v1/orders/statuses', ['statuses' => [$statuses[1]]])->assertOk();
    }

    public function test_customers_track_orders_and_ask_to_cancel_or_return_them(): void
    {
        $waiting = $this->create(1);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/public/orders/track', ['order_number' => $waiting['order_number'], 'email' => 'other@example.com'])->assertNotFound();
        $this->postJson('/api/v1/public/orders/track', ['order_number' => $waiting['order_number'], 'email' => 'SARA@example.com'])
            ->assertOk()
            ->assertJsonPath('data.order_number', $waiting['order_number'])
            ->assertJsonPath('data.items.0.name', 'Serum')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonMissingPath('data.id');

        $this->postJson('/api/v1/public/orders/cancel-request', ['order_number' => $waiting['order_number'], 'email' => 'sara@example.com', 'reason' => 'Ordered twice'])->assertOk();
        $held = Order::find($waiting['id']);
        $this->assertSame(['manual', 'Customer asked to cancel: Ordered twice'], [$held->hold_type, $held->hold_reason]);

        $shipped = $this->shippedOrder();
        $this->app['auth']->forgetGuards();
        $item = $this->postJson('/api/v1/public/orders/track', ['order_number' => $shipped->order_number, 'email' => 'sara@example.com'])
            ->assertOk()
            ->assertJsonPath('data.shipments.0.tracking_number', 'T-1')
            ->assertJsonPath('data.items.0.returnable_quantity', 3)
            ->json('data.items.0');

        $this->postJson('/api/v1/public/orders/return-request', [
            'order_number' => $shipped->order_number,
            'email' => 'sara@example.com',
            'reason' => 'Wrong shade',
            'items' => [['item_id' => $item['item_id'], 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->assertSame('Customer request: Wrong shade', $shipped->returns()->value('reason'));
    }

    public function test_a_repeated_order_is_created_on_its_schedule(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $order = $this->create(2);

        $plan = $this->postJson("/api/v1/orders/{$order['id']}/repeat", ['interval' => 'weekly', 'starts_on' => '2026-11-03'])->assertCreated()->json('data');
        $this->assertSame([2], array_column($plan['items'], 'quantity'));

        $this->artisan('orders:recurring')->assertSuccessful();
        $this->artisan('orders:recurring')->assertSuccessful();
        $this->assertSame(2, Order::count());
        $made = Order::latest('id')->first();
        $this->assertSame([2, 20.0, $plan['id']], [(int) $made->items()->sum('quantity'), (float) $made->total_amount, $made->recurring_order_id]);
        $this->assertSame('2026-11-10', RecurringOrder::find($plan['id'])->next_run_at->toDateString());

        $this->putJson("/api/v1/recurring-orders/{$plan['id']}", ['interval' => 'monthly', 'active' => false])->assertOk();
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->artisan('orders:recurring')->assertSuccessful();
        $this->assertSame(2, Order::count());

        $this->postJson("/api/v1/recurring-orders/{$plan['id']}/run")->assertCreated();
        $this->assertSame(3, Order::count());
        Carbon::setTestNow();
    }

    public function test_a_recurring_order_is_set_up_without_an_earlier_order(): void
    {
        Carbon::setTestNow('2026-11-03 09:00:00');
        $plan = [
            'interval' => 'fortnightly',
            'starts_on' => '2026-11-03',
            'store_id' => $this->store->id,
            'customer_name' => 'Ayesha Khan',
            'customer_email' => 'ayesha@example.com',
            'currency' => 'PKR',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 3, 'unit_price' => 10]],
        ];

        $this->postJson('/api/v1/recurring-orders', [...$plan, 'starts_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->postJson('/api/v1/recurring-orders', [...$plan, 'items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');

        $created = $this->postJson('/api/v1/recurring-orders', $plan)->assertCreated()->json('data');
        $this->assertSame(['Ayesha Khan', 30, 'fortnightly'], [$created['customer_name'], $created['total'], $created['interval']]);

        $this->artisan('orders:recurring')->assertSuccessful();
        $made = Order::sole();
        $this->assertSame(['Ayesha Khan', 3, 'pending'], [$made->customer_name, (int) $made->items()->sum('quantity'), $made->payment_status]);
        $this->assertSame('2026-11-17', RecurringOrder::find($created['id'])->next_run_at->toDateString());
        Carbon::setTestNow();
    }

    public function test_a_return_is_refunded_or_replaced_once(): void
    {
        $order = $this->shippedOrder();
        $itemId = $order->items()->value('id');
        $first = $this->completedReturn($order, $itemId, 2);

        $this->postJson("/api/v1/returns/{$first}/resolve", ['resolution' => 'refund', 'amount' => 31])->assertUnprocessable();
        $this->postJson("/api/v1/returns/{$first}/resolve", ['resolution' => 'refund', 'amount' => 20, 'method' => 'Bank transfer'])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'refund');
        $this->assertSame('partially_refunded', $order->fresh()->payment_status);
        $this->postJson("/api/v1/returns/{$first}/resolve", ['resolution' => 'refund', 'amount' => 5])->assertUnprocessable();

        $second = $this->completedReturn($order, $itemId, 1);
        $replacement = $this->postJson("/api/v1/returns/{$second}/resolve", ['resolution' => 'replacement'])->assertOk()->json('data.replacement_order');
        $new = Order::where('order_number', $replacement['order_number'])->firstOrFail();
        $this->assertSame([1, 0.0, 'paid'], [(int) $new->items()->sum('quantity'), (float) $new->total_amount, $new->payment_status]);
        $this->assertStringContainsString('Replacement for return', (string) $new->notes);
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
            'currency' => 'PKR',
            'payment_status' => 'pending',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
    }

    /** An order for 3 units at 10, paid, packed and dispatched with tracking ID T-1. */
    private function shippedOrder(): Order
    {
        $order = $this->importOrder($this->store, $this->variant, 3, 10, ['customer_email' => 'sara@example.com', 'payment_status' => 'paid']);
        $this->actingAs($this->admin);
        $this->putJson('/api/v1/picking/'.PickTask::where('order_id', $order->id)->value('id').'/status', ['status' => 'packed'])->assertOk();
        $shipmentId = $order->shipments()->value('id');
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'T-1'])->assertOk();

        return $order->fresh();
    }

    private function completedReturn(Order $order, int $itemId, int $quantity): int
    {
        $id = $this->postJson('/api/v1/returns', ['order_id' => $order->id, 'reason' => 'Damaged', 'items' => [['order_item_id' => $itemId, 'quantity' => $quantity]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/returns/{$id}/receive")->assertOk();
        $this->postJson("/api/v1/returns/{$id}/complete", ['disposition' => 'damaged'])->assertOk();

        return $id;
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('orders.csv', $content);
    }
}
