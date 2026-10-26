<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EmailService;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAllocationAndPickingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected Store $store;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $this->org = $this->user->currentOrganization();

        $this->warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Fulfillment Hub',
            'code' => 'HUB-1',
        ]);

        $this->store = Store::create([
            'organization_id' => $this->org->id,
            'name' => 'Online Store',
            'code' => 'STORE-1',
            'platform' => 'shopify',
            'status' => 'active',
        ]);

        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);

        $this->product = Product::create([
            'organization_id' => $this->org->id,
            'name' => 'Gaming Mouse',
            'sku' => 'GM-01',
        ]);

        $variant = $this->product->variants()->create([
            'organization_id' => $this->org->id,
            'sku' => 'GM-01',
            'price' => 59.00,
        ]);

        // Stock initial 50 units
        app(InventoryEngine::class)->adjustStock(
            organizationId: $this->org->id,
            warehouseId: $this->warehouse->id,
            productVariantId: $variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Initial Inbound'
        );
    }

    public function test_imported_order_is_allocated_and_gets_a_pick_task(): void
    {
        $this->importOrder($this->store, $this->product->variants()->first(), 2, 59.00, ['customer_name' => 'Jane Doe', 'customer_email' => 'jane@example.com']);

        $this->assertDatabaseHas('orders', [
            'organization_id' => $this->org->id,
            'customer_name' => 'Jane Doe',
            'order_status' => 'allocated',
        ]);

        // Available stock should be 48, reserved 2
        $variant = $this->product->variants()->first();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
            'reserved' => 2,
            'available' => 48,
        ]);

        // Pick task should be created
        $this->assertDatabaseHas('pick_tasks', [
            'organization_id' => $this->org->id,
            'status' => 'not_started',
            'items_count' => 2,
        ]);

        // List picking page API
        $pickList = $this->actingAs($this->user)->getJson('/api/v1/picking');
        $pickList->assertOk()
            ->assertJsonPath('kpis.pending_orders', 1)
            ->assertJsonPath('kpis.items_to_pick', 2);
    }

    public function test_quick_complete_advances_pick_task(): void
    {
        $order = Order::create([
            'organization_id' => $this->org->id,
            'store_id' => $this->store->id,
            'order_number' => 'ORD-TEST-1',
            'external_order_id' => 'EXT-1',
            'customer_name' => 'Alice',
            'order_status' => 'allocated',
        ]);

        $pickTask = PickTask::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'not_started',
            'items_count' => 1,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/picking/{$pickTask->id}/complete");
        $response->assertOk();

        $this->assertEquals('in_progress', $pickTask->fresh()->status);
    }

    public function test_packed_order_moves_to_shipments_and_is_dispatched(): void
    {
        $orderId = $this->importOrder($this->store, $this->product->variants()->first(), 2, 59.00, ['customer_name' => 'Sara Khan'])->id;
        $this->actingAs($this->user);
        $task = PickTask::where('order_id', $orderId)->firstOrFail();

        // Nothing reaches Shipments until the order is packed.
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'picked'])->assertOk();
        $this->getJson('/api/v1/shipments')->assertOk()->assertJsonCount(0, 'data');

        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertOk();
        $shipments = $this->getJson("/api/v1/shipments?store_id={$this->store->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('kpis.ready_to_dispatch', 1);
        $shipmentId = $shipments->json('data.0.id');
        $this->assertSame('packed', Order::find($orderId)->order_status);

        // Moving it back out of packed withdraws the shipment; packing again brings it back.
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'picked'])->assertOk();
        $this->assertDatabaseMissing('shipments', ['id' => $shipmentId]);
        $this->postJson("/api/v1/picking/{$task->id}/complete")->assertOk();
        $shipmentId = $this->getJson('/api/v1/shipments')->assertJsonCount(1, 'data')->json('data.0.id');

        // Another store does not see it.
        $other = Store::create(['organization_id' => $this->org->id, 'name' => 'Other', 'code' => 'STORE-2', 'platform' => 'shopify', 'status' => 'active']);
        $this->getJson("/api/v1/shipments?store_id={$other->id}")->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", [])->assertUnprocessable()->assertJsonValidationErrors('carrier');
        $this->postJson("/api/v1/shipments/{$shipmentId}/deliver")->assertUnprocessable();

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'TCS-1001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking_number', 'TCS-1001');

        // Stock has left the warehouse and the reservation is used up.
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $this->product->variants()->first()->id,
            'on_hand' => 48,
            'reserved' => 0,
            'available' => 48,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'order_status' => 'shipped', 'fulfillment_status' => 'fulfilled']);
        $this->assertSame('shipped', $task->fresh()->status);

        // The order has left picking and can no longer be dispatched twice, re-picked or cancelled.
        $this->getJson('/api/v1/picking')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'TCS-1002'])->assertUnprocessable();
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertUnprocessable();
        $this->getJson('/api/v1/shipments')->assertJsonPath('kpis.dispatched_today', 1)->assertJsonPath('kpis.in_transit', 1);

        $this->postJson("/api/v1/shipments/{$shipmentId}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'order_status' => 'delivered']);
    }

    public function test_cancelled_order_leaves_picking_and_shipments(): void
    {
        $orderId = $this->importOrder($this->store, $this->product->variants()->first(), 1, 59.00, ['customer_name' => 'Ali'])->id;
        $this->actingAs($this->user);
        $task = PickTask::where('order_id', $orderId)->firstOrFail();
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertOk();

        $this->getJson('/api/v1/picking')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/shipments')->assertJsonCount(0, 'data');
    }

    /** Create an order through the API, pack it and return its pending shipment ID. */
    private function packedShipment(?string $email): int
    {
        $orderId = $this->importOrder($this->store, $this->product->variants()->first(), 2, 59.00, ['customer_name' => 'Sara Khan', 'customer_email' => $email])->id;
        $this->actingAs($this->user);

        $task = PickTask::where('order_id', $orderId)->firstOrFail();
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertOk();

        return Shipment::where('order_id', $orderId)->value('id');
    }

    public function test_customer_is_emailed_tracking_details_when_the_order_is_dispatched(): void
    {
        Mail::fake();
        $shipmentId = $this->packedShipment('sara@example.com');

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'TCS-9001', 'tracking_url' => 'not a link'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tracking_url');

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", [
            'carrier' => 'TCS',
            'tracking_number' => 'TCS-9001',
            'tracking_url' => 'https://example.com/track/TCS-9001',
        ])
            ->assertOk()
            ->assertJsonPath('customer_emailed', true)
            ->assertJsonPath('data.tracking_url', 'https://example.com/track/TCS-9001');

        Mail::assertSent(Mailable::class, function (Mailable $mail) {
            $html = (string) $mail->render();

            return $mail->hasTo('sara@example.com')
                && str_contains($mail->subject, 'has been shipped')
                && str_contains($html, 'TCS-9001')
                && str_contains($html, 'https://example.com/track/TCS-9001')
                && str_contains($html, 'Gaming Mouse');
        });
    }

    public function test_known_carriers_get_a_tracking_link_and_orders_without_email_are_not_emailed(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/shipments/'.$this->packedShipment('ali@example.com').'/dispatch', ['carrier' => 'DHL', 'tracking_number' => '1234567890'])
            ->assertOk()
            ->assertJsonPath('data.tracking_url', 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id=1234567890');

        $this->postJson('/api/v1/shipments/'.$this->packedShipment(null).'/dispatch', ['carrier' => 'Leopards Courier', 'tracking_number' => 'LP-1'])
            ->assertOk()
            ->assertJsonPath('customer_emailed', false)
            ->assertJsonPath('data.tracking_url', null);

        // Only the order with an email address gets the shipping email.
        Mail::assertSentCount(1);
    }

    public function test_customer_gets_an_order_confirmation_for_a_new_imported_order(): void
    {
        Mail::fake();
        $emails = app(EmailService::class);

        $order = $this->importOrder($this->store, $this->product->variants()->first(), 3, 59.00, [
            'customer_name' => 'Hina',
            'customer_email' => 'hina@example.com',
            'shipping_address' => ['address1' => 'House 7', 'city' => 'Lahore', 'country' => 'Pakistan'],
        ]);
        $this->assertTrue($emails->sendOrderConfirmationEmail($order));

        Mail::assertSent(Mailable::class, function (Mailable $mail) {
            $html = (string) $mail->render();

            return $mail->hasTo('hina@example.com')
                && str_contains($mail->subject, 'confirmed')
                && str_contains($html, '3 × Gaming Mouse')
                && str_contains($html, '177.00')
                && str_contains($html, 'House 7, Lahore, Pakistan')
                && str_contains($html, 'Online Store');
        });

        // No address, history from before the store was connected, and cancelled orders are not confirmed.
        $this->assertFalse($emails->sendOrderConfirmationEmail($this->importOrder($this->store, $this->product->variants()->first(), 1)));
        $this->assertFalse($emails->sendOrderConfirmationEmail($this->importOrder($this->store, $this->product->variants()->first(), 1, 10, ['customer_email' => 'old@example.com', 'placed_at' => now()->subYear()])));
        $cancelled = $this->importOrder($this->store, $this->product->variants()->first(), 1, 10, ['customer_email' => 'x@example.com']);
        $cancelled->update(['order_status' => 'cancelled']);
        $this->assertFalse($emails->sendOrderConfirmationEmail($cancelled));

        Mail::assertSentCount(1);
    }

    public function test_tracking_id_is_generated_when_left_empty_and_never_repeats(): void
    {
        $first = $this->packedShipment(null);
        $generated = $this->postJson("/api/v1/shipments/{$first}/dispatch", ['carrier' => 'TCS'])
            ->assertOk()
            ->json('data.tracking_number');
        $this->assertMatchesRegularExpression('/^FNV[A-Z0-9]{10}$/', $generated);

        // A tracking ID typed in by hand cannot reuse one that is taken.
        $second = $this->packedShipment(null);
        $this->postJson("/api/v1/shipments/{$second}/dispatch", ['carrier' => 'TCS', 'tracking_number' => $generated])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tracking_number');

        // When every random ID comes out taken, the shipment's own ID is used instead.
        Str::createRandomStringsUsing(fn (int $length) => $length === 10 ? substr($generated, 3) : 'ABCD');
        $fallback = $this->postJson("/api/v1/shipments/{$second}/dispatch", ['carrier' => 'TCS'])->assertOk()->json('data.tracking_number');
        Str::createRandomStringsNormally();

        $this->assertSame('FNV'.str_pad((string) $second, 8, '0', STR_PAD_LEFT).'ABCD', $fallback);
    }

    public function test_order_screen_shows_the_next_steps_and_allocation_can_be_retried(): void
    {
        $variant = $this->product->variants()->first();

        // 60 units wanted, 50 in stock: nothing can be reserved yet.
        $order = $this->importOrder($this->store, $variant, 60);
        $this->actingAs($this->user)->getJson("/api/v1/orders/{$order->id}/fulfillment")
            ->assertOk()
            ->assertJsonPath('data.order_status', 'exception')
            ->assertJsonPath('data.can_allocate', true)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.pick_task', null);

        app(InventoryEngine::class)->adjustStock($this->org->id, $this->warehouse->id, $variant->id, 'add', 20, 'Restock');
        $order->items()->update(['status' => 'pending']);

        $this->postJson("/api/v1/orders/{$order->id}/allocate")
            ->assertOk()
            ->assertJsonPath('message', "Stock reserved for order {$order->order_number}. It is now on the picking list.");

        $this->getJson("/api/v1/orders/{$order->id}/fulfillment")
            ->assertJsonPath('data.can_allocate', false)
            ->assertJsonPath('data.pick_task.status', 'not_started');
        $this->postJson("/api/v1/orders/{$order->id}/allocate")->assertUnprocessable();

        // Another company's user cannot see it.
        $this->actingAs(User::factory()->create(['status' => 'active']))->getJson("/api/v1/orders/{$order->id}/fulfillment")->assertNotFound();
    }

    public function test_cancel_order_releases_reserved_stock(): void
    {
        $orderId = $this->importOrder($this->store, $this->product->variants()->first(), 5, 59.00, ['customer_name' => 'John Smith'])->id;
        $this->actingAs($this->user);

        // Cancel order
        $cancel = $this->actingAs($this->user)->postJson("/api/v1/orders/{$orderId}/cancel");
        $cancel->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'order_status' => 'cancelled',
        ]);

        // Reserved should be back to 0, available back to 50
        $variant = $this->product->variants()->first();
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $variant->id,
            'reserved' => 0,
            'available' => 50,
        ]);
    }

    public function test_order_is_sent_to_the_chosen_warehouse_and_listed_on_its_orders_tab(): void
    {
        $variant = $this->product->variants()->first();
        $north = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'North Hub', 'code' => 'HUB-N']);
        $this->store->warehouses()->attach($north->id);
        $outside = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Not Linked', 'code' => 'HUB-X']);

        // 60 units: the hub only has 50, so it cannot be placed on import; then stock arrives at North Hub.
        $order = $this->importOrder($this->store, $variant, 60);
        $this->assertSame('exception', $order->order_status);
        app(InventoryEngine::class)->adjustStock($this->org->id, $north->id, $variant->id, 'add', 80, 'Opening');
        $this->actingAs($this->user);

        $options = collect($this->getJson("/api/v1/orders/{$order->id}/fulfillment")->assertOk()->json('data.warehouse_options'))->keyBy('id');
        $this->assertFalse($options[$this->warehouse->id]['can_fulfil']);
        $this->assertTrue($options[$north->id]['can_fulfil']);
        $this->assertArrayNotHasKey($outside->id, $options->all());

        $this->postJson("/api/v1/orders/{$order->id}/allocate", ['warehouse_id' => $outside->id])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
        $this->postJson("/api/v1/orders/{$order->id}/allocate", ['warehouse_id' => $north->id])->assertOk()->assertJsonPath('data.order_status', 'allocated');

        $this->assertSame($north->id, $order->fresh()->pickTasks()->first()->warehouse_id);
        $this->getJson("/api/v1/orders/{$order->id}/fulfillment")->assertJsonPath('data.warehouse.name', 'North Hub');
        $this->getJson("/api/v1/orders?warehouse_id={$north->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id);
        $this->getJson("/api/v1/orders?warehouse_id={$this->warehouse->id}")->assertJsonCount(0, 'data');
    }
}
