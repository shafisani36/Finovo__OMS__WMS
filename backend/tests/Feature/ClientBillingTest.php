<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\InventoryLedgerEntry;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected Store $shop;

    protected Store $daraz;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');

        $this->user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $this->shop = Store::create(['organization_id' => $this->org->id, 'name' => 'Brand Shop', 'code' => 'B-SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->daraz = Store::create(['organization_id' => $this->org->id, 'name' => 'Brand Daraz', 'code' => 'B-DARAZ', 'platform' => 'manual', 'status' => 'active']);

        $product = Product::create(['organization_id' => $this->org->id, 'name' => 'Tee', 'sku' => 'TEE']);
        $this->variant = $product->variants()->create(['organization_id' => $this->org->id, 'sku' => 'TEE-M', 'price' => 10]);
        // Sold in both of the brand's stores: its stock must be counted once.
        foreach ([$this->shop, $this->daraz] as $store) {
            SkuMapping::create(['organization_id' => $this->org->id, 'store_id' => $store->id, 'product_variant_id' => $this->variant->id, 'external_id' => "x-{$store->id}", 'external_sku' => 'TEE-M']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_client_is_created_with_its_rate_card_and_stores(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/billing/clients', $this->clientPayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'BRAND')
            ->assertJsonPath('data.currency', 'PKR')
            ->assertJsonCount(2, 'data.stores');

        $this->postJson('/api/v1/billing/clients', $this->clientPayload())->assertUnprocessable()->assertJsonValidationErrors('code');

        $client = Client::firstOrFail();
        $this->putJson("/api/v1/billing/clients/{$client->id}", [...$this->clientPayload(), 'store_ids' => [$this->shop->id]])->assertOk();
        $this->assertNull($this->daraz->fresh()->client_id);
    }

    public function test_charges_cover_storage_receiving_orders_and_returns(): void
    {
        $client = $this->client();

        // 10 units before October, 20 more received on 11 October, 5 shipped on 16 October.
        $this->ledger('2026-09-25 10:00', 'receipt', 10, 10);
        $this->ledger('2026-10-11 09:00', 'receipt', 20, 30);
        $this->ledger('2026-10-16 15:00', 'shipment', -5, 25);

        $order = $this->shippedOrder($this->shop, [3, 2], '2026-10-16 15:00');
        $this->shippedOrder($this->daraz, [1], '2026-10-17 10:00');
        OrderReturn::create(['organization_id' => $this->org->id, 'order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'return_number' => 'RET-1', 'status' => 'completed', 'completed_at' => '2026-10-18 10:00']);

        $data = $this->actingAs($this->user)->getJson("/api/v1/billing/clients/{$client->id}/charges?from=2026-10-01&to=2026-10-31")
            ->assertOk()
            ->json('data');

        // 1-10 Oct: 10 a day (100); 11-15 Oct: 30 a day (150); 16-20 Oct (today): 25 a day (125). Later days not yet.
        $this->assertSame(375, $data['activity']['unit_days']);
        $this->assertSame(20, $data['activity']['units_received']);
        $this->assertSame(2, $data['activity']['orders_shipped']);
        $this->assertSame(6, $data['activity']['units_shipped']);
        $this->assertSame(4, $data['activity']['additional_units']);
        $this->assertSame(1, $data['activity']['returns']);

        // 375 x 0.5 + 20 x 5 + 2 x 50 + 4 x 10 + 1 x 30 = 187.5 + 100 + 100 + 40 + 30
        $this->assertEquals(457.5, $data['total']);
    }

    public function test_monthly_minimum_tops_up_low_charges(): void
    {
        $client = $this->client(['monthly_minimum' => 1000]);
        $this->shippedOrder($this->shop, [1], '2026-09-10 10:00');

        $lines = $this->actingAs($this->user)->getJson("/api/v1/billing/clients/{$client->id}/charges?from=2026-09-01&to=2026-09-30")->json('data.lines');

        $this->assertSame(['orders', 'minimum'], array_column($lines, 'type'));
        $this->assertEquals(950, $lines[1]['amount']);
    }

    public function test_invoice_lifecycle(): void
    {
        $client = $this->client();
        $this->shippedOrder($this->shop, [2], '2026-09-10 10:00');
        $this->actingAs($this->user);

        $invoice = $this->postJson('/api/v1/billing/invoices', ['client_id' => $client->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()
            ->assertJsonPath('data.invoice_number', 'BILL-2026-0001')
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $this->assertEquals(60, $invoice['total']);

        // The same period cannot be billed twice.
        $this->postJson('/api/v1/billing/invoices', ['client_id' => $client->id, 'period_start' => '2026-09-15', 'period_end' => '2026-10-15'])->assertUnprocessable();

        // A hand-added charge survives a recalculation.
        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/lines", ['description' => 'Gift wrapping', 'quantity' => 2, 'rate' => 15])->assertOk()->assertJsonPath('data.total', 90);
        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/recalculate")->assertOk()->assertJsonPath('data.total', 90)->assertJsonCount(3, 'data.lines');

        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/pay")->assertUnprocessable();
        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/issue")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.due_date', '2026-11-04');
        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/lines", ['description' => 'Late', 'quantity' => 1, 'rate' => 5])->assertUnprocessable();

        $this->getJson('/api/v1/billing/invoices')->assertOk()->assertJsonPath('kpis.outstanding.0.amount', 90);
        $this->getJson("/api/v1/billing/invoices/{$invoice['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/pay")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/void")->assertUnprocessable();
        $this->deleteJson("/api/v1/billing/clients/{$client->id}")->assertUnprocessable();
    }

    public function test_voided_invoice_frees_its_period(): void
    {
        $client = $this->client();
        $this->shippedOrder($this->shop, [1], '2026-09-10 10:00');
        $this->actingAs($this->user);
        $id = $this->postJson('/api/v1/billing/invoices', ['client_id' => $client->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->json('data.id');

        $this->postJson("/api/v1/billing/invoices/{$id}/void")->assertOk();

        $this->postJson('/api/v1/billing/invoices', ['client_id' => $client->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])
            ->assertCreated()
            ->assertJsonPath('data.invoice_number', 'BILL-2026-0002');
        $this->assertSame(1, ClientInvoice::where('status', 'void')->count());
    }

    public function test_billing_needs_permission(): void
    {
        // Warehouse operators do not see what clients are charged.
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR]);

        $this->actingAs($operator)->getJson('/api/v1/billing/clients')->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function clientPayload(): array
    {
        return [
            'name' => 'Brand Co', 'code' => 'BRAND', 'currency' => 'pkr', 'payment_terms_days' => 15,
            'storage_per_unit_day' => 0.5, 'receiving_per_unit' => 5, 'order_fee' => 50, 'additional_unit_fee' => 10,
            'return_fee' => 30, 'monthly_minimum' => 0, 'store_ids' => [$this->shop->id, $this->daraz->id],
        ];
    }

    private function client(array $rates = []): Client
    {
        $client = Client::create(['organization_id' => $this->org->id, ...collect($this->clientPayload())->except('store_ids')->all(), 'currency' => 'PKR', ...$rates]);
        Store::whereIn('id', [$this->shop->id, $this->daraz->id])->update(['client_id' => $client->id]);

        return $client;
    }

    private function ledger(string $at, string $type, int $quantity, int $after): void
    {
        InventoryLedgerEntry::create([
            'organization_id' => $this->org->id, 'warehouse_id' => $this->warehouse->id, 'product_variant_id' => $this->variant->id,
            'movement_type' => $type, 'quantity' => $quantity, 'balance_before' => $after - $quantity, 'balance_after' => $after, 'created_at' => $at,
        ]);
    }

    /**
     * @param  list<int>  $quantities
     */
    private function shippedOrder(Store $store, array $quantities, string $at): Order
    {
        $order = Order::create(['organization_id' => $this->org->id, 'store_id' => $store->id, 'order_number' => 'ORD-'.uniqid(), 'customer_name' => 'Buyer', 'order_status' => 'shipped']);
        foreach ($quantities as $quantity) {
            $order->items()->create(['product_variant_id' => $this->variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Tee', 'quantity' => $quantity, 'status' => 'shipped']);
        }
        Shipment::create(['organization_id' => $this->org->id, 'order_id' => $order->id, 'warehouse_id' => $this->warehouse->id, 'status' => 'shipped', 'shipped_at' => $at]);

        return $order;
    }
}
