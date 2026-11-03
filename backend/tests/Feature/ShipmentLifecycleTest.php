<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CourierAccount;
use App\Models\InventoryBalance;
use App\Models\InventoryLocationBalance;
use App\Models\OrderReturn;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Dispatch with parcel details, cancelling a dispatch, parcels the courier brings back, bulk dispatch, the
 * shipment timeline, shipping labels and the load sheet.
 */
class ShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse;

    protected WarehouseLocation $bin;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $this->bin = WarehouseLocation::create([
            'organization_id' => $org->id, 'warehouse_id' => $this->warehouse->id, 'code' => 'A01-B01',
            'location_type' => 'bin', 'putaway_eligible' => true, 'picking_eligible' => true, 'is_active' => true,
        ]);
        $this->store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);
        $this->variant = Product::create(['organization_id' => $org->id, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $org->id, 'sku' => 'SER-01', 'price' => 1250]);
        app(InventoryEngine::class)->adjustStock(
            organizationId: $org->id, warehouseId: $this->warehouse->id, productVariantId: $this->variant->id,
            adjustmentType: 'add', quantity: 10, reason: 'Opening', locationId: $this->bin->id,
        );
        $this->actingAs($this->user);
    }

    public function test_dispatch_saves_parcel_details_and_the_timeline(): void
    {
        $shipmentId = $this->packedShipment(['payment_status' => 'pending', 'total_amount' => 2500]);

        $this->getJson("/api/v1/shipments/{$shipmentId}")->assertOk()->assertJsonPath('data.cod_amount', 2500);

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'tcs', 'tracking_number' => 'TCS-1', 'weight_kg' => 1.2, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10, 'pieces' => 2, 'cod_amount' => 2000])
            ->assertOk()
            ->assertJsonPath('data.carrier', 'TCS')
            ->assertJsonPath('data.weight_kg', 1.2)
            ->assertJsonPath('data.pieces', 2)
            ->assertJsonPath('data.dimensions_cm', [30, 20, 10])
            ->assertJsonPath('data.cod_amount', 2000)
            ->assertJsonPath('data.can_cancel_dispatch', true);

        $this->getJson("/api/v1/shipments/{$shipmentId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'shipped')
            ->assertJsonPath('data.events.0.type', 'dispatched')
            ->assertJsonPath('data.events.0.description', 'Dispatched with TCS, tracking ID TCS-1');
    }

    public function test_a_cancelled_dispatch_puts_the_stock_back_in_its_bin(): void
    {
        Http::fake([
            'merchantapi.leopardscourier.com/api/getAllCities/*' => Http::response(['status' => 1, 'city_list' => [['id' => 789, 'name' => 'Lahore']]]),
            'merchantapi.leopardscourier.com/api/bookPacket/*' => Http::response(['status' => 1, 'track_number' => 'LE1']),
            'merchantapi.leopardscourier.com/api/cancelBookedPackets/*' => Http::response(['status' => 1]),
        ]);
        $account = $this->account();
        $shipmentId = $this->packedShipment(quantity: 3);

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])->assertOk();
        $this->assertSame(7, $this->binQuantity());
        $this->assertSame(7, $this->balance()->on_hand);

        $this->postJson("/api/v1/shipments/{$shipmentId}/cancel-dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tracking_number', null);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'cancelBookedPackets') && $request['cn_numbers'] === 'LE1');
        $balance = $this->balance();
        $this->assertSame([10, 3, 7], [$balance->on_hand, $balance->reserved, $balance->available]);
        $this->assertSame(10, $this->binQuantity());

        $order = Shipment::find($shipmentId)->order;
        $this->assertSame(['packed', 'allocated'], [$order->order_status, $order->items->first()->status]);
        $this->assertSame('packed', PickTask::where('order_id', $order->id)->value('status'));
        $this->assertTrue(app(InventoryEngine::class)->reconcileLedgerWithBalances($order->organization_id)['reconciled']);

        // It can be dispatched again, and the timeline keeps both steps.
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'DHL'])->assertOk();
        $this->assertSame(7, $this->binQuantity());
        $this->getJson("/api/v1/shipments/{$shipmentId}")->assertJsonPath('data.events.1.type', 'dispatch_cancelled')->assertJsonCount(3, 'data.events');
    }

    public function test_a_courier_that_cannot_be_reached_leaves_the_order_ready_to_dispatch(): void
    {
        Http::fake(fn () => throw new RequestException(new Response(new Psr7Response(403))));
        $account = $this->account();
        $shipmentId = $this->packedShipment();

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.courier_account_id.0', 'Leopards could not be reached (error 403). Try again in a moment.');
        $this->assertSame('pending', Shipment::find($shipmentId)->status);
        $this->assertSame(10, $this->binQuantity());
    }

    public function test_a_collected_parcel_cannot_be_cancelled(): void
    {
        $shipmentId = $this->packedShipment();
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS'])->assertOk();
        Shipment::whereKey($shipmentId)->update(['courier_status' => 'in_transit', 'courier_status_text' => 'Arrived at Lahore']);

        $this->postJson("/api/v1/shipments/{$shipmentId}/cancel-dispatch")
            ->assertUnprocessable()
            ->assertJsonPath('errors.shipment.0', 'The courier already has this parcel (Arrived at Lahore), so its dispatch cannot be cancelled.');
        $this->assertSame('shipped', Shipment::find($shipmentId)->status);
    }

    public function test_a_parcel_the_courier_brings_back_is_returned_and_a_return_is_raised(): void
    {
        Http::fake([
            'merchantapi.leopardscourier.com/api/getAllCities/*' => Http::response(['status' => 1, 'city_list' => [['id' => 789, 'name' => 'Lahore']]]),
            'merchantapi.leopardscourier.com/api/bookPacket/*' => Http::response(['status' => 1, 'track_number' => 'LE2']),
            'merchantapi.leopardscourier.com/api/trackBookedPacket/*' => Http::sequence()
                ->push(['status' => 1, 'packet_list' => [['track_number' => 'LE2', 'booked_packet_status' => 'Undelivered - Refused by consignee']]])
                ->push(['status' => 1, 'packet_list' => [['track_number' => 'LE2', 'booked_packet_status' => 'Returned to shipper']]]),
        ]);
        $account = $this->account();
        $shipmentId = $this->packedShipment(quantity: 2);
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])->assertOk();

        $this->postJson('/api/v1/shipments/track')->assertOk();
        $this->getJson('/api/v1/shipments?status=issues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('kpis.issues', 1);

        $this->postJson('/api/v1/shipments/track')->assertOk()->assertJsonPath('data.returned', 1);
        $shipment = Shipment::find($shipmentId);
        $this->assertSame('returned', $shipment->status);
        $this->assertNotNull($shipment->returned_at);

        $return = OrderReturn::where('order_id', $shipment->order_id)->with('items')->sole();
        $this->assertSame(['requested', 2], [$return->status, $return->items->sole()->quantity]);
        $this->getJson('/api/v1/shipments?status=returned')->assertJsonPath('kpis.returned', 1)->assertJsonPath('kpis.issues', 0);

        // A returned parcel is no longer tracked.
        $this->postJson('/api/v1/shipments/track')->assertOk()->assertJsonPath('data.checked', 0);
    }

    public function test_bulk_dispatch_reports_each_order(): void
    {
        $first = $this->packedShipment();
        $second = $this->packedShipment();
        $this->postJson("/api/v1/shipments/{$second}/dispatch", ['carrier' => 'TCS'])->assertOk();
        $third = $this->packedShipment();

        $this->postJson('/api/v1/shipments/dispatch', ['shipment_ids' => [$first, $second, $third], 'carrier' => 'M&P', 'weight_kg' => 0.8])
            ->assertOk()
            ->assertJsonPath('message', '2 of 3 orders dispatched. 1 could not be dispatched; see the list.')
            ->assertJsonPath('data.0.dispatched', true)
            ->assertJsonPath('data.1.dispatched', false)
            ->assertJsonPath('data.1.message', 'This shipment has already been dispatched.')
            ->assertJsonPath('data.2.dispatched', true);

        $this->assertSame(['shipped', 'M&P', '0.80'], [Shipment::find($third)->status, Shipment::find($third)->carrier, (string) Shipment::find($third)->weight_kg]);
    }

    public function test_labels_and_load_sheet_are_printed_for_dispatched_parcels(): void
    {
        $pending = $this->packedShipment();
        $shipped = $this->packedShipment();
        $this->postJson("/api/v1/shipments/{$shipped}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'TCS-77'])->assertOk();

        $this->postJson('/api/v1/shipments/labels', ['shipment_ids' => [$pending, $shipped]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shipment_ids');

        $labels = $this->post('/api/v1/shipments/labels', ['shipment_ids' => [$shipped]]);
        $labels->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $labels->getContent());

        $sheet = $this->post('/api/v1/shipments/load-sheet', ['shipment_ids' => [$shipped]]);
        $sheet->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $sheet->getContent());
    }

    private function account(): CourierAccount
    {
        return CourierAccount::create([
            'organization_id' => $this->user->currentOrganization()->id,
            'carrier' => 'leopards',
            'name' => 'Leopards',
            'credentials' => ['api_key' => 'K', 'api_password' => 'P'],
            'settings' => [],
        ]);
    }

    private function packedShipment(array $attributes = [], int $quantity = 1): int
    {
        $order = $this->importOrder($this->store, $this->variant, $quantity, 1250, [
            'customer_name' => 'Sara Khan',
            'customer_phone' => '03001234567',
            'shipping_address' => ['address1' => 'House 1', 'city' => 'Lahore'],
            ...$attributes,
        ]);
        PickTask::where('order_id', $order->id)->firstOrFail()->moveTo('packed', $this->user);

        return Shipment::where('order_id', $order->id)->value('id');
    }

    private function balance(): InventoryBalance
    {
        return InventoryBalance::where('warehouse_id', $this->warehouse->id)->where('product_variant_id', $this->variant->id)->sole();
    }

    private function binQuantity(): int
    {
        return (int) InventoryLocationBalance::where('warehouse_location_id', $this->bin->id)->where('product_variant_id', $this->variant->id)->sum('quantity');
    }
}
