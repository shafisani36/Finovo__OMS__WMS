<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\UnitSerialNumber;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Serial numbers per unit: registered when stock arrives, scanned onto an order before it ships, shipped with it,
 * and back in stock (or held out of sale) when the unit is returned. Every step is in the unit's history.
 */
class SerialNumberTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Store $store;

    protected Warehouse $warehouse;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);
        $this->variant = Product::create(['organization_id' => $orgId, 'name' => 'Phone', 'sku' => 'PH-1'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'PH-1', 'price' => 100, 'track_serials' => true]);
        app(InventoryEngine::class)->adjustStock(organizationId: $orgId, warehouseId: $this->warehouse->id, productVariantId: $this->variant->id, adjustmentType: 'add', quantity: 3, reason: 'Opening');
        $this->actingAs($this->admin);
    }

    public function test_a_serial_is_registered_shipped_returned_and_traced(): void
    {
        $register = fn (array $serials) => $this->postJson('/api/v1/serials', ['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id, 'serials' => $serials]);

        $register(['SN-1', 'SN-2', ' sn-3 '])->assertCreated()->assertJsonPath('message', '3 serial number(s) registered.');
        $register(['SN-1'])->assertUnprocessable()->assertJsonPath('errors.serials.0', 'SN-1 is already registered for PH-1.');
        $register(['SN-4'])->assertUnprocessable()->assertJsonPath('errors.serials.0', 'Only 3 unit(s) of PH-1 are in Hub and 3 already have a serial number.');
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], UnitSerialNumber::orderBy('serial_number')->pluck('serial_number')->all());

        $order = $this->importOrder($this->store, $this->variant, 2, 100, ['customer_email' => 'sara@example.com']);
        $itemId = $order->items->first()->id;
        $this->putJson('/api/v1/picking/'.PickTask::where('order_id', $order->id)->value('id').'/status', ['status' => 'packed'])->assertOk();
        $shipmentId = $order->shipments()->value('id');

        // A serialised product ships only with a serial number for every unit.
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'T-1'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Order {$order->order_number} needs 2 serial number(s) for PH-1; 0 scanned.");

        $assign = fn (array $serials) => $this->postJson("/api/v1/orders/{$order->id}/serials", ['item_id' => $itemId, 'serials' => $serials]);
        $assign(['SN-1', 'SN-9'])->assertUnprocessable()->assertJsonPath('errors.serials.0', 'SN-9 is not in stock in Hub.');
        $assign(['SN-1', 'SN-2', 'SN-3'])->assertUnprocessable()->assertJsonPath('errors.serials.0', 'Only 2 unit(s) of PH-1 are on the order; 0 already have a serial number.');
        $assign(['SN-1', 'SN-2'])->assertOk()->assertJsonPath('data.0.status', 'allocated');

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'T-1'])->assertOk();

        $this->getJson('/api/v1/serials/trace?serial=sn-1')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'shipped')
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.events.*.type', ['received', 'assigned', 'shipped']);

        // A returned unit goes back with its serial number.
        $returnId = $this->postJson('/api/v1/returns', ['order_id' => $order->id, 'reason' => 'Faulty', 'items' => [['order_item_id' => $itemId, 'quantity' => 1]]])->json('data.id');
        $this->postJson("/api/v1/returns/{$returnId}/receive")->assertOk();
        $this->postJson("/api/v1/returns/{$returnId}/complete", ['disposition' => 'damaged', 'serials' => ['SN-3']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serials.0', 'SN-3 was not shipped on this order.');
        $this->postJson("/api/v1/returns/{$returnId}/complete", ['disposition' => 'damaged', 'serials' => ['SN-2']])->assertOk();

        $this->assertSame(['SN-1' => 'shipped', 'SN-2' => 'damaged', 'SN-3' => 'in_stock'], UnitSerialNumber::orderBy('serial_number')->pluck('status', 'serial_number')->all());
        $this->getJson('/api/v1/serials?status=in_stock')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.serial', 'SN-3');
    }

    public function test_cancelling_a_dispatch_keeps_the_serials_on_the_order(): void
    {
        $this->postJson('/api/v1/serials', ['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id, 'serials' => ['A1']])->assertCreated();
        $order = $this->importOrder($this->store, $this->variant, 1);
        $this->putJson('/api/v1/picking/'.PickTask::where('order_id', $order->id)->value('id').'/status', ['status' => 'packed'])->assertOk();
        $this->postJson("/api/v1/orders/{$order->id}/serials", ['item_id' => $order->items->first()->id, 'serials' => ['A1']])->assertOk();
        $shipmentId = $order->shipments()->value('id');

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'T-2'])->assertOk();
        $this->postJson("/api/v1/shipments/{$shipmentId}/cancel-dispatch")->assertOk();
        $this->assertSame('allocated', UnitSerialNumber::where('serial_number', 'A1')->value('status'));

        // A serial taken off the order is free again.
        $serialId = UnitSerialNumber::where('serial_number', 'A1')->value('id');
        $this->deleteJson("/api/v1/orders/{$order->id}/serials/{$serialId}")->assertOk();
        $this->assertSame(['in_stock', null], [UnitSerialNumber::find($serialId)->status, UnitSerialNumber::find($serialId)->order_item_id]);
    }

    public function test_products_without_serial_tracking_refuse_serials(): void
    {
        $this->variant->update(['track_serials' => false]);

        $this->postJson('/api/v1/serials', ['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id, 'serials' => ['X1']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.product_variant_id.0', 'PH-1 does not track serial numbers. Turn it on in the product first.');
    }
}
