<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryLocationBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlottingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Warehouse $warehouse;

    protected Store $store;

    protected WarehouseLocation $pickBin;

    protected WarehouseLocation $bulkBin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB']);
        $this->store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);

        $zone = fn (string $code, string $type) => WarehouseLocation::create(['organization_id' => $org->id, 'warehouse_id' => $this->warehouse->id, 'code' => $code, 'location_type' => $type, 'putaway_eligible' => false]);
        $bin = fn (WarehouseLocation $parent, string $code) => WarehouseLocation::create(['organization_id' => $org->id, 'warehouse_id' => $this->warehouse->id, 'parent_id' => $parent->id, 'code' => $code, 'location_type' => 'bin', 'capacity' => 100, 'capacity_unit' => 'units']);

        $pick = $zone('Z-PICK', 'picking');
        $bulk = $zone('Z-BULK', 'bulk_storage');
        $receiving = $zone('Z-RCV', 'receiving');
        $this->pickBin = $bin($pick, 'P-01');
        $this->bulkBin = $bin($bulk, 'K-01');
        $bin($receiving, 'DOCK-01');
    }

    public function test_fast_sellers_belong_in_the_picking_area_and_slow_ones_in_bulk(): void
    {
        $fast = $this->variant('FAST', $this->bulkBin, 40);
        $slow = $this->variant('SLOW', $this->pickBin, 20);
        $this->importOrder($this->store, $fast, 10, 100);

        $data = $this->actingAs($this->user)->getJson("/api/v1/warehouses/{$this->warehouse->id}/slotting")->assertOk()->json('data');
        $items = collect($data['items'])->keyBy('sku');

        $this->assertSame('A', $items['FAST']['abc_class']);
        $this->assertSame('C', $items['SLOW']['abc_class']);
        $this->assertSame(40, $items['FAST']['misplaced_units']);
        $this->assertSame(20, $items['SLOW']['misplaced_units']);

        $moves = collect($data['moves'])->keyBy('sku');
        $this->assertSame(['P-01', 40], [$moves['FAST']['to']['code'], $moves['FAST']['quantity']]);
        $this->assertSame('K-01', $moves['SLOW']['to']['code']);

        // Doing the suggested move puts the stock where it belongs.
        $this->postJson("/api/v1/warehouses/{$this->warehouse->id}/move-stock", [
            'product_variant_id' => $fast->id, 'from_location_id' => $this->bulkBin->id, 'to_location_id' => $this->pickBin->id, 'quantity' => 40,
        ])->assertOk()->assertJsonPath('message', 'Moved 40 units from K-01 to P-01.');

        $this->assertSame(40, (int) InventoryLocationBalance::where('warehouse_location_id', $this->pickBin->id)->where('product_variant_id', $fast->id)->value('quantity'));
        $this->postJson("/api/v1/warehouses/{$this->warehouse->id}/move-stock", [
            'product_variant_id' => $fast->id, 'from_location_id' => $this->bulkBin->id, 'to_location_id' => $this->pickBin->id, 'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_putaway_suggests_a_bin_by_class_and_room_never_a_receiving_dock(): void
    {
        $fast = $this->variant('FAST', null, 0);
        $slow = $this->variant('SLOW', null, 0);
        $other = $this->variant('OTHER', $this->pickBin, 90);
        app(InventoryEngine::class)->adjustStock(organizationId: $this->warehouse->organization_id, warehouseId: $this->warehouse->id, productVariantId: $fast->id, adjustmentType: 'add', quantity: 10, reason: 'Opening');
        $this->importOrder($this->store, $fast, 5, 100);
        $this->actingAs($this->user);

        $suggest = fn (ProductVariant $variant, int $quantity) => $this->getJson("/api/v1/warehouses/{$this->warehouse->id}/putaway-suggestion?product_variant_id={$variant->id}&quantity={$quantity}")->assertOk()->json('data');

        $this->assertSame(['A', 'P-01'], [$suggest($fast, 5)['abc_class'], $suggest($fast, 5)['location']['code']]);
        // The picking bin has room for 10 more units only, so 20 go to the next best area.
        $this->assertSame('K-01', $suggest($fast, 20)['location']['code']);
        $this->assertSame(['C', 'K-01'], [$suggest($slow, 5)['abc_class'], $suggest($slow, 5)['location']['code']]);
        // Stock of the same product stays together.
        $this->assertSame('P-01', $suggest($other, 5)['location']['code']);
    }

    private function variant(string $sku, ?WarehouseLocation $bin, int $quantity): ProductVariant
    {
        $org = $this->warehouse->organization_id;
        $variant = Product::create(['organization_id' => $org, 'name' => $sku, 'sku' => $sku])->variants()->create(['organization_id' => $org, 'sku' => $sku, 'price' => 100]);

        if ($bin && $quantity > 0) {
            app(InventoryEngine::class)->adjustStock(organizationId: $org, warehouseId: $this->warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: $quantity, reason: 'Opening', locationId: $bin->id);
        }

        return $variant;
    }
}
