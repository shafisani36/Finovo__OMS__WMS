<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Product::IMAGE_DISK);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->store = Store::create([
            'organization_id' => $this->admin->currentOrganization()->id, 'name' => 'Karachi Shop', 'code' => 'khi', 'platform' => StorePlatform::SHOPIFY,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
    }

    private function order(string $id, array $attributes = []): Order
    {
        $orgId = $this->admin->currentOrganization()->id;
        $order = Order::create($attributes + [
            'organization_id' => $orgId, 'store_id' => $this->store->id, 'order_number' => "#{$id}", 'external_order_id' => $id,
            'customer_name' => 'Ayesha Khan', 'shipping_address' => ['address1' => 'House 12', 'city' => 'Karachi'],
            'total_amount' => 3700, 'currency' => 'PKR', 'payment_status' => 'paid', 'placed_at' => now(),
        ]);

        Storage::disk(Product::IMAGE_DISK)->put("product-images/{$orgId}/{$id}.jpg", $this->sampleJpeg());
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Cap', 'sku' => "CAP-{$id}"]);
        $product->forceFill(['image_path' => "product-images/{$orgId}/{$id}.jpg"])->save();
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => "CAP-{$id}-1", 'price' => 1500]);

        $order->items()->create(['product_variant_id' => $variant->id, 'external_sku' => $variant->sku, 'product_name' => 'Cap', 'quantity' => 2, 'unit_price' => 1500, 'tax_amount' => 0, 'total_amount' => 3000, 'status' => 'pending']);
        $order->items()->create(['external_sku' => '', 'product_name' => 'Gift wrap', 'quantity' => 1, 'unit_price' => 200, 'tax_amount' => 0, 'total_amount' => 200, 'status' => 'pending']);

        return $order;
    }

    public function test_invoice_is_numbered_once_and_downloads_with_product_photos(): void
    {
        $first = $this->order('1');
        $second = $this->order('2');

        $pdf = $this->actingAs($this->admin)->get("/api/v1/orders/{$first->id}/invoice")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('/Subtype /Image', $pdf->getContent());

        $number = 'INV-'.now()->year.'-0001';
        $this->assertSame($number, $first->fresh()->invoice_number);
        $this->assertStringContainsString("{$number}.pdf", $pdf->headers->get('Content-Disposition'));

        // Downloading again keeps the same number; the next order gets the next one
        $this->actingAs($this->admin)->get("/api/v1/orders/{$first->id}/invoice")->assertOk();
        $this->actingAs($this->admin)->get("/api/v1/orders/{$second->id}/invoice")->assertOk();
        $this->assertSame($number, $first->fresh()->invoice_number);
        $this->assertSame('INV-'.now()->year.'-0002', $second->fresh()->invoice_number);
    }

    public function test_cancelled_and_other_organization_orders_are_not_invoiced(): void
    {
        $cancelled = $this->order('3', ['order_status' => 'cancelled']);
        $this->actingAs($this->admin)->getJson("/api/v1/orders/{$cancelled->id}/invoice")->assertUnprocessable();
        $this->assertNull($cancelled->fresh()->invoice_number);

        $order = $this->order('4');
        $outsider = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->actingAs($outsider)->get("/api/v1/orders/{$order->id}/invoice")->assertNotFound();
    }
}
