<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileScanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanned_products_and_bins_are_resolved_within_the_warehouse(): void
    {
        $user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $user->currentOrganization();
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $other = Warehouse::create(['organization_id' => $org->id, 'name' => 'Other', 'code' => 'OTH']);
        $bin = WarehouseLocation::create(['organization_id' => $org->id, 'warehouse_id' => $warehouse->id, 'code' => 'A-01', 'barcode' => 'BC-A-01']);
        WarehouseLocation::create(['organization_id' => $org->id, 'warehouse_id' => $other->id, 'code' => 'B-01']);
        $variant = Product::create(['organization_id' => $org->id, 'name' => 'Tee', 'sku' => 'TEE'])->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'barcode' => '8901234567890', 'price' => 10]);
        app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 7, reason: 'Opening', locationId: $bin->id);
        $this->actingAs($user);

        $this->getJson("/api/v1/mobile/warehouses/{$warehouse->id}/product?code=8901234567890")
            ->assertOk()
            ->assertJsonPath('data.sku', 'TEE-M')
            ->assertJsonPath('data.bins.0.code', 'A-01')
            ->assertJsonPath('data.bins.0.quantity', 7);

        $this->getJson("/api/v1/mobile/warehouses/{$warehouse->id}/location?code=BC-A-01")
            ->assertOk()
            ->assertJsonPath('data.code', 'A-01')
            ->assertJsonPath('data.stock.0.quantity', 7);

        // A bin of another warehouse is not found here.
        $this->getJson("/api/v1/mobile/warehouses/{$warehouse->id}/location?code=B-01")->assertNotFound();
        $this->getJson("/api/v1/mobile/warehouses/{$warehouse->id}/summary")->assertOk()->assertJsonPath('data.to_pick', 0);

        // Another company's warehouse cannot be opened.
        $stranger = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->actingAs($stranger)->getJson("/api/v1/mobile/warehouses/{$warehouse->id}/summary")->assertNotFound();
    }
}
