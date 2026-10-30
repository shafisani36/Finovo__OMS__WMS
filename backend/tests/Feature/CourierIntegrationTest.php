<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CourierAccount;
use App\Models\Order;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CourierIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Store $store;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $this->store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);
    }

    public function test_courier_account_keeps_credentials_secret_and_checks_them(): void
    {
        Http::fake(['merchantapi.leopardscourier.com/api/getAllCities/*' => Http::response(['status' => 1, 'city_list' => [['id' => 789, 'name' => 'Lahore']]])]);
        $this->actingAs($this->user);

        $this->postJson('/api/v1/courier-accounts', ['carrier' => 'leopards', 'name' => 'Leopards Main', 'credentials' => ['api_key' => 'K']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.api_password');

        $id = $this->postJson('/api/v1/courier-accounts', ['carrier' => 'leopards', 'name' => 'Leopards Main', 'credentials' => ['api_key' => 'K', 'api_password' => 'P']])
            ->assertCreated()
            ->assertJsonMissingPath('data.credentials')
            ->assertJsonPath('data.credentials_set.api_password', true)
            ->json('data.id');

        // A blank credential on update keeps the saved one; the stored value is encrypted.
        $this->putJson("/api/v1/courier-accounts/{$id}", ['name' => 'Leopards Lahore', 'credentials' => ['api_key' => '', 'api_password' => 'P2']])->assertOk();
        $account = CourierAccount::findOrFail($id);
        $this->assertSame(['api_key' => 'K', 'api_password' => 'P2'], $account->credentials);
        $this->assertStringNotContainsString('P2', (string) $account->getRawOriginal('credentials'));

        $this->postJson("/api/v1/courier-accounts/{$id}/test")->assertOk()->assertJsonPath('message', 'Leopards Lahore is connected.');
    }

    public function test_dispatch_books_with_leopards_and_tracking_marks_it_delivered(): void
    {
        Http::fake([
            'merchantapi.leopardscourier.com/api/getAllCities/*' => Http::response(['status' => 1, 'city_list' => [['id' => 789, 'name' => 'Lahore']]]),
            'merchantapi.leopardscourier.com/api/bookPacket/*' => Http::response(['status' => 1, 'track_number' => 'LE7512345', 'slip_link' => 'https://merchantapi.leopardscourier.com/slip/LE7512345']),
            'merchantapi.leopardscourier.com/api/trackBookedPacket/*' => Http::sequence()
                ->push(['status' => 1, 'packet_list' => [['track_number' => 'LE7512345', 'booked_packet_status' => 'Arrived at Station']]])
                ->push(['status' => 1, 'packet_list' => [['track_number' => 'LE7512345', 'booked_packet_status' => 'Delivered']]]),
        ]);
        $account = $this->account('leopards', ['api_key' => 'K', 'api_password' => 'P']);
        [$order, $shipmentId] = $this->packedOrder(['payment_status' => 'pending', 'total_amount' => 2500]);
        $this->actingAs($this->user);

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'LE7512345')
            ->assertJsonPath('data.carrier', 'Leopards Courier')
            ->assertJsonPath('data.courier_status', 'booked')
            ->assertJsonPath('data.has_label', true);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'bookPacket')
            && $request['destination_city'] === 789
            && $request['booked_packet_collect_amount'] === 2500
            && $request['booked_packet_order_id'] === $order->order_number);

        $this->getJson("/api/v1/shipments/{$shipmentId}/label")->assertOk()->assertJsonPath('data.url', 'https://merchantapi.leopardscourier.com/slip/LE7512345');

        $this->postJson('/api/v1/shipments/track')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame(['shipped', 'in_transit'], [Shipment::find($shipmentId)->status, Shipment::find($shipmentId)->courier_status]);

        $this->artisan('couriers:track')->assertSuccessful();
        $this->assertSame('delivered', Shipment::find($shipmentId)->status);
        $this->assertSame('delivered', $order->fresh()->order_status);
    }

    public function test_a_refused_booking_is_shown_and_nothing_is_dispatched(): void
    {
        Http::fake(['api.postex.pk/*' => Http::response(['statusCode' => '400', 'statusMessage' => 'Invalid city name'], 400)]);
        $account = $this->account('postex', ['api_token' => 'T']);
        [, $shipmentId] = $this->packedOrder();
        $this->actingAs($this->user);

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.courier_account_id.0', 'PostEx: Invalid city name');

        $this->assertSame('pending', Shipment::find($shipmentId)->status);
    }

    public function test_postex_booking_and_label_pdf(): void
    {
        Http::fake([
            'api.postex.pk/services/integration/api/order/v3/create-order' => Http::response(['statusCode' => '200', 'statusMessage' => 'ORDER HAS BEEN CREATED', 'dist' => ['trackingNumber' => 'CX-90001']]),
            'api.postex.pk/services/integration/api/order/v1/get-invoice*' => Http::response('%PDF-1.4 label', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $account = $this->account('postex', ['api_token' => 'T'], ['pickup_address_code' => '002']);
        [, $shipmentId] = $this->packedOrder(['payment_status' => 'paid']);
        $this->actingAs($this->user);

        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['courier_account_id' => $account->id])->assertOk()->assertJsonPath('data.tracking_number', 'CX-90001');

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'create-order')
            && $request->header('token') === ['T']
            && $request['invoicePayment'] === '0'
            && $request['cityName'] === 'Lahore'
            && $request['pickupAddressCode'] === '002');

        $this->get("/api/v1/shipments/{$shipmentId}/label")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    private function account(string $carrier, array $credentials, array $settings = []): CourierAccount
    {
        return CourierAccount::create([
            'organization_id' => $this->user->currentOrganization()->id,
            'carrier' => $carrier,
            'name' => ucfirst($carrier),
            'credentials' => $credentials,
            'settings' => $settings,
        ]);
    }

    /**
     * @return array{0: Order, 1: int}
     */
    private function packedOrder(array $attributes = []): array
    {
        $org = $this->user->currentOrganization();
        $variant = Product::create(['organization_id' => $org->id, 'name' => 'Tee', 'sku' => 'TEE-'.uniqid()])->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-'.uniqid(), 'price' => 10]);
        app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $this->warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 5, reason: 'Opening');

        $order = $this->importOrder($this->store, $variant, 1, 2500, [
            'customer_name' => 'Sara Khan',
            'customer_phone' => '+92 300 1234567',
            'shipping_address' => ['address1' => 'House 1, Street 2', 'city' => 'Lahore'],
            ...$attributes,
        ]);
        PickTask::where('order_id', $order->id)->firstOrFail()->moveTo('packed', $this->user);

        return [$order, Shipment::where('order_id', $order->id)->value('id')];
    }
}
