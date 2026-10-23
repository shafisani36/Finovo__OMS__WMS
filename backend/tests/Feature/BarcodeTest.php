<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_settings_have_defaults_and_only_admins_change_them(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR]);
        $settings = ['format' => 'CODE39', 'width' => 1.5, 'height' => 80, 'display_value' => false, 'font_size' => 12, 'margin' => 8];

        $this->actingAs($admin)->getJson('/api/v1/barcode/settings')->assertOk()->assertJsonPath('data.format', 'CODE128')->assertJsonPath('data.height', 60);

        $this->putJson('/api/v1/barcode/settings', [...$settings, 'format' => 'QR'])->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->putJson('/api/v1/barcode/settings', $settings)->assertOk();
        $this->getJson('/api/v1/barcode/settings')->assertJsonPath('data.format', 'CODE39')->assertJsonPath('data.display_value', false);

        $this->actingAs($operator)->putJson('/api/v1/barcode/settings', $settings)->assertForbidden();
    }

    public function test_scanning_finds_orders_by_number_or_tracking_id_and_products_by_sku(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $org = $user->currentOrganization();
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB-1']);
        $store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'S-1', 'platform' => 'shopify', 'status' => 'active']);
        $store->warehouses()->attach($warehouse->id, ['is_default' => true]);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Mouse', 'sku' => 'M-1']);
        $variant = $product->variants()->create(['organization_id' => $org->id, 'sku' => 'M-1', 'barcode' => '8901234567890', 'price' => 10]);
        app(InventoryEngine::class)->adjustStock($org->id, $warehouse->id, $variant->id, 'add', 10, 'Opening');

        $order = $this->importOrder($store, $variant, 2, 10, ['order_number' => '#1042']);
        $this->actingAs($user);

        // The order and its open picking step; the scan screen moves it on through the picking API.
        $taskId = $this->getJson('/api/v1/barcode/scan?code=1042')
            ->assertOk()
            ->assertJsonPath('type', 'order')
            ->assertJsonPath('data.order_number', '#1042')
            ->assertJsonPath('data.pick_task.status', 'not_started')
            ->json('data.pick_task.id');
        $this->putJson("/api/v1/picking/{$taskId}/status", ['status' => 'packed'])->assertOk();

        $shipmentId = $this->getJson('/api/v1/barcode/scan?code=%231042')->assertJsonPath('data.shipment.status', 'pending')->json('data.shipment.id');
        $tracking = $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS'])->json('data.tracking_number');

        $this->getJson('/api/v1/barcode/scan?code='.$tracking)
            ->assertJsonPath('data.order_number', '#1042')
            ->assertJsonPath('data.pick_task', null)
            ->assertJsonPath('data.shipment.status', 'shipped');

        $this->getJson('/api/v1/barcode/scan?code=8901234567890')
            ->assertOk()
            ->assertJsonPath('type', 'product')
            ->assertJsonPath('data.sku', 'M-1')
            ->assertJsonPath('data.stock.on_hand', 8);

        $this->getJson('/api/v1/barcode/scan?code=NOPE')->assertNotFound();
        $this->assertSame('shipped', PickTask::find($taskId)->status);
    }
}
