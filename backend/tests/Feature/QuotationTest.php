<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->store = Store::create([
            'organization_id' => $orgId, 'name' => 'Karachi Shop', 'code' => 'khi', 'platform' => StorePlatform::SHOPIFY,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Cotton Tee', 'sku' => 'TEE']);
        $this->variantId = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'TEE-BLK-M', 'option_name' => 'Color', 'option_value' => 'Black', 'price' => 1500])->id;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $this->store->id,
            'customer_name' => 'Ayesha Khan',
            'customer_email' => 'ayesha@example.com',
            'billing_address' => ['address1' => 'House 12, DHA', 'city' => 'Karachi', 'country' => 'Pakistan'],
            'currency' => 'pkr',
            'items' => [
                ['product_variant_id' => $this->variantId, 'description' => 'Cotton Tee - Black / M', 'quantity' => 10, 'unit_price' => 1500, 'discount_percent' => 10, 'tax_percent' => 18],
                ['description' => 'Custom printing', 'quantity' => 1, 'unit_price' => 2000],
            ],
        ];
    }

    public function test_quotation_is_created_with_number_and_totals_and_downloads_as_pdf(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->payload())->assertCreated();

        // Line 1: 15,000 − 1,500 discount = 13,500 + 18% tax 2,430 = 15,930. Line 2: 2,000.
        $response->assertJsonPath('data.quotation_number', 'QT-'.now()->year.'-0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'PKR')
            ->assertJsonPath('data.subtotal', '17000.00')
            ->assertJsonPath('data.discount_total', '1500.00')
            ->assertJsonPath('data.tax_total', '2430.00')
            ->assertJsonPath('data.total_amount', '17930.00')
            ->assertJsonPath('data.items.0.sku', 'TEE-BLK-M')
            ->assertJsonPath('data.valid_until', now()->addDays(15)->toDateString());

        $id = $response->json('data.id');
        $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->payload())->assertJsonPath('data.quotation_number', 'QT-'.now()->year.'-0002');

        $pdf = $this->actingAs($this->admin)->get("/api/v1/quotations/{$id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->admin)->getJson('/api/v1/quotations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('kpis.open', 2);
    }

    public function test_accepted_quotation_converts_into_an_order_once(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->payload())->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/convert")->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/status", ['status' => 'sent'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/status", ['status' => 'accepted'])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/convert")->assertCreated();

        $quotation = Quotation::findOrFail($id);
        $order = Order::with('items')->findOrFail($quotation->order_id);
        $this->assertSame('converted', $quotation->status);
        $this->assertSame('17930.00', (string) $order->total_amount);
        $this->assertCount(2, $order->items);
        $this->assertSame($this->variantId, $order->items[0]->product_variant_id);
        $this->assertNotNull($order->customer_id);

        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/convert")->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/quotations/{$id}", $this->payload())->assertUnprocessable();
    }

    public function test_expired_quotation_cannot_be_accepted_and_only_drafts_are_deleted(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->payload())->json('data.id');
        Quotation::whereKey($id)->update(['valid_until' => now()->subDay()->toDateString()]);

        $this->actingAs($this->admin)->getJson("/api/v1/quotations/{$id}")->assertJsonPath('data.is_expired', true);
        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/status", ['status' => 'accepted'])->assertUnprocessable();

        $this->actingAs($this->admin)->postJson("/api/v1/quotations/{$id}/status", ['status' => 'rejected'])->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/v1/quotations/{$id}")->assertUnprocessable();
    }

    public function test_other_organizations_cannot_see_or_use_quotations(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/quotations', $this->payload())->json('data.id');
        $outsider = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);

        $this->actingAs($outsider)->getJson("/api/v1/quotations/{$id}")->assertNotFound();
        $this->actingAs($outsider)->get("/api/v1/quotations/{$id}/pdf")->assertNotFound();
        $this->actingAs($outsider)->postJson('/api/v1/quotations', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_id', 'items.0.product_variant_id']);
    }
}
