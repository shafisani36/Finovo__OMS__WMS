<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryLocationBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scans saved on a phone while offline are sent later with the ID the phone gave them (Idempotency-Key):
 * a scan sent twice is applied once, a scan ID reused for another request is refused, and the server's answer to a
 * scan that no longer fits the stock (a conflict) is kept, so a retry gets the same answer.
 */
class OfflineScanSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected WarehouseLocation $from;

    protected WarehouseLocation $to;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        [$this->from, $this->to] = array_map(fn (string $code) => WarehouseLocation::create([
            'organization_id' => $orgId, 'warehouse_id' => $this->warehouse->id, 'code' => $code,
            'location_type' => 'bin', 'putaway_eligible' => true, 'picking_eligible' => true, 'is_active' => true,
        ]), ['A01-B01', 'A01-B02']);
        $this->variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 25]);
        app(InventoryEngine::class)->adjustStock(
            organizationId: $orgId, warehouseId: $this->warehouse->id, productVariantId: $this->variant->id,
            adjustmentType: 'add', quantity: 10, reason: 'Opening', locationId: $this->from->id,
        );
    }

    public function test_a_scan_sent_twice_is_applied_once(): void
    {
        $first = $this->move(3, 'scan-0001-aaaa')->assertOk();
        $this->move(3, 'scan-0001-aaaa')
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('message', $first->json('message'));

        $this->assertSame([7, 3], [$this->inBin($this->from), $this->inBin($this->to)]);
    }

    public function test_a_scan_id_reused_for_another_request_is_refused(): void
    {
        $this->move(3, 'scan-0002-bbbb')->assertOk();

        $this->move(4, 'scan-0002-bbbb')->assertStatus(409)->assertJsonPath('message', 'This scan was already sent with different details. Scan it again.');
        $this->assertSame(3, $this->inBin($this->to));
    }

    public function test_a_conflicting_scan_keeps_its_answer(): void
    {
        $this->move(50, 'scan-0003-cccc')->assertUnprocessable();
        // Stock arrives meanwhile; the old scan is still answered the same way, it is not applied late.
        $this->move(50, 'scan-0003-cccc')->assertUnprocessable()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(0, $this->inBin($this->to));
    }

    public function test_scan_ids_belong_to_one_user(): void
    {
        $colleague = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $colleague->memberships()->create(['organization_id' => $this->admin->currentOrganization()->id, 'role' => UserRole::ADMIN->value]);
        $colleague->forceFill(['current_organization_id' => $this->admin->currentOrganization()->id])->save();

        $this->move(2, 'scan-0004-dddd')->assertOk();
        $this->actingAs($colleague)->withHeader('Idempotency-Key', 'scan-0004-dddd')
            ->postJson("/api/v1/warehouses/{$this->warehouse->id}/move-stock", $this->payload(2))
            ->assertOk()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(4, $this->inBin($this->to));
    }

    public function test_a_malformed_scan_id_is_refused(): void
    {
        $this->move(1, 'bad key!')->assertStatus(400);
        $this->assertSame(0, $this->inBin($this->to));
    }

    private function move(int $quantity, string $key)
    {
        return $this->actingAs($this->admin)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/warehouses/{$this->warehouse->id}/move-stock", $this->payload($quantity));
    }

    /**
     * @return array<string, int>
     */
    private function payload(int $quantity): array
    {
        return ['product_variant_id' => $this->variant->id, 'from_location_id' => $this->from->id, 'to_location_id' => $this->to->id, 'quantity' => $quantity];
    }

    private function inBin(WarehouseLocation $location): int
    {
        return (int) InventoryLocationBalance::where('warehouse_location_id', $location->id)->sum('quantity');
    }
}
