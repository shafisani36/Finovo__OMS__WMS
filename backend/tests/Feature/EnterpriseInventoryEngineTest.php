<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryLocationBalance;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReorderRule;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseInventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $userA;
    protected User $userB;
    protected User $supervisorA;
    protected Warehouse $warehouse1;
    protected Warehouse $warehouse2;
    protected WarehouseLocation $dockLocation;
    protected WarehouseLocation $storageBin;
    protected Product $product;
    protected ProductVariant $variant;
    protected InventoryEngine $inventoryEngine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryEngine = app(InventoryEngine::class);

        // Org A
        $this->orgA = Organization::create(['name' => 'Acme Corporation', 'slug' => 'acme']);
        $this->userA = User::create([
            'name' => 'Alice Operator',
            'email' => 'alice@acme.com',
            'password' => bcrypt('Password123!'),
            'role' => 'operator',
            'is_active' => true,
            'current_organization_id' => $this->orgA->id,
        ]);
        $this->userA->memberships()->create(['organization_id' => $this->orgA->id, 'role' => 'operator']);

        $this->supervisorA = User::create([
            'name' => 'Bob Supervisor',
            'email' => 'bob@acme.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'is_active' => true,
            'current_organization_id' => $this->orgA->id,
        ]);
        $this->supervisorA->memberships()->create(['organization_id' => $this->orgA->id, 'role' => 'admin']);

        // Org B
        $this->orgB = Organization::create(['name' => 'Beta Logistics', 'slug' => 'beta']);
        $this->userB = User::create([
            'name' => 'Charlie Beta',
            'email' => 'charlie@beta.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'is_active' => true,
            'current_organization_id' => $this->orgB->id,
        ]);
        $this->userB->memberships()->create(['organization_id' => $this->orgB->id, 'role' => 'admin']);

        // Warehouses in Org A
        $this->warehouse1 = Warehouse::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Main Distribution Center',
            'code' => 'DC-01',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->warehouse2 = Warehouse::create([
            'organization_id' => $this->orgA->id,
            'name' => 'East Coast Hub',
            'code' => 'ECH-01',
            'status' => 'active',
            'is_active' => true,
        ]);

        // Locations in Warehouse 1
        $this->dockLocation = WarehouseLocation::create([
            'organization_id' => $this->orgA->id,
            'warehouse_id' => $this->warehouse1->id,
            'code' => 'DOCK-01',
            'location_type' => 'dock',
            'putaway_eligible' => true,
            'is_active' => true,
        ]);

        $this->storageBin = WarehouseLocation::create([
            'organization_id' => $this->orgA->id,
            'warehouse_id' => $this->warehouse1->id,
            'code' => 'A-01-R01-S01-B01',
            'location_type' => 'bin',
            'picking_eligible' => true,
            'putaway_eligible' => true,
            'is_active' => true,
        ]);

        // Catalog Product in Org A
        $this->product = Product::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Wireless Ergonomic Mouse',
            'sku' => 'WM-1001',
            'category' => 'Electronics',
            'base_uom' => 'pcs',
            'tracking_type' => 'lot_tracked',
            'is_active' => true,
        ]);

        $this->variant = $this->product->variants()->create([
            'organization_id' => $this->orgA->id,
            'sku' => 'WM-1001',
            'price' => 49.99,
            'cost_price' => 25.00,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Product registration defines identity but DOES NOT create physical stock.
     */
    public function test_product_creation_does_not_create_stock(): void
    {
        $avail = $this->inventoryEngine->getAvailability(
            $this->orgA->id,
            $this->warehouse1->id,
            $this->variant->id
        );

        $this->assertEquals(0, $avail['on_hand']);
        $this->assertEquals(0, $avail['available']);
        $this->assertEquals(0, $avail['reserved']);
        $this->assertDatabaseMissing('inventory_balances', [
            'organization_id' => $this->orgA->id,
            'warehouse_id' => $this->warehouse1->id,
            'product_variant_id' => $this->variant->id,
        ]);
    }

    /**
     * Test 2: Inbound receiving adds stock to receiving dock location and updates ledger.
     */
    public function test_receiving_stock_updates_dock_location_and_ledger(): void
    {
        $response = $this->actingAs($this->userA, 'sanctum')->postJson('/api/v1/inventory/receipts', [
            'warehouse_id' => $this->warehouse1->id,
            'supplier_name' => 'Logitech Global Supplier',
            'purchase_order_number' => 'PO-9901',
            'receiving_location_id' => $this->dockLocation->id,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'expected_quantity' => 100,
                    'lot_number' => 'LOT-2026-104',
                    'expiry_date' => '2028-12-31',
                    'unit_cost' => 24.50,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $receiptId = $response->json('data.id');
        $itemId = $response->json('data.items.0.id');

        // Confirm receipt
        $confirmResponse = $this->actingAs($this->userA, 'sanctum')->postJson("/api/v1/inventory/receipts/{$receiptId}/confirm", [
            'items' => [
                [
                    'item_id' => $itemId,
                    'received_quantity' => 100,
                    'location_id' => $this->dockLocation->id,
                    'lot_number' => 'LOT-2026-104',
                ],
            ],
        ]);

        $confirmResponse->assertStatus(200);

        // Assert warehouse balance
        $avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(100, $avail['on_hand']);
        $this->assertEquals(100, $avail['available']);

        // Assert dock location balance
        $dockAvail = $this->inventoryEngine->getAvailability(
            $this->orgA->id,
            $this->warehouse1->id,
            $this->variant->id,
            $this->dockLocation->id,
            'LOT-2026-104'
        );
        $this->assertEquals(100, $dockAvail['on_hand']);

        // Assert ledger entry created with type 'receipt'
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'organization_id' => $this->orgA->id,
            'warehouse_id' => $this->warehouse1->id,
            'movement_type' => 'receipt',
            'quantity' => 100,
            'balance_after' => 100,
            'lot_number' => 'LOT-2026-104',
        ]);
    }

    /**
     * Test 3: Putaway moves stock from dock location to storage bin location without changing warehouse total.
     */
    public function test_putaway_moves_stock_from_dock_to_bin(): void
    {
        // First receive 100 units at dock
        $this->inventoryEngine->receiveStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            quantity: 100,
            locationId: $this->dockLocation->id,
            lotNumber: 'LOT-2026-104'
        );

        // Putaway 60 units to storage bin
        $this->inventoryEngine->putawayStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            fromLocationId: $this->dockLocation->id,
            toLocationId: $this->storageBin->id,
            quantity: 60,
            lotNumber: 'LOT-2026-104',
            userId: $this->userA->id
        );

        // Total warehouse on-hand is still 100
        $whAvail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(100, $whAvail['on_hand']);

        // Dock location has 40 units remaining
        $dockAvail = $this->inventoryEngine->getAvailability(
            $this->orgA->id,
            $this->warehouse1->id,
            $this->variant->id,
            $this->dockLocation->id,
            'LOT-2026-104'
        );
        $this->assertEquals(40, $dockAvail['on_hand']);

        // Storage bin has 60 units
        $binAvail = $this->inventoryEngine->getAvailability(
            $this->orgA->id,
            $this->warehouse1->id,
            $this->variant->id,
            $this->storageBin->id,
            'LOT-2026-104'
        );
        $this->assertEquals(60, $binAvail['on_hand']);
    }

    /**
     * Test 4: Reservation prevents overselling under strict invariants.
     */
    public function test_reservation_prevents_overselling(): void
    {
        // Initialize 50 units
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Opening Balance'
        );

        // Reserve 30 units
        $res = $this->inventoryEngine->reserveStock(
            organizationId: $this->orgA->id,
            orderId: 101,
            orderItemId: 1,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            quantity: 30
        );

        $avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(50, $avail['on_hand']);
        $this->assertEquals(30, $avail['reserved']);
        $this->assertEquals(20, $avail['available']);

        // Attempting to reserve 25 units when only 20 are available must throw RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->inventoryEngine->reserveStock(
            organizationId: $this->orgA->id,
            orderId: 102,
            orderItemId: 2,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            quantity: 25
        );
    }

    /**
     * Test 5: Multi-stage inter-warehouse transfer tracks in-transit stock and handles discrepancies.
     */
    public function test_inter_warehouse_transfer_lifecycle(): void
    {
        // 100 units at WH1
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 100,
            reason: 'Stock initialization'
        );

        // Dispatch transfer of 40 units from WH1 to WH2
        $dispatchResponse = $this->actingAs($this->userA, 'sanctum')->postJson('/api/v1/inventory/transfers', [
            'from_warehouse_id' => $this->warehouse1->id,
            'to_warehouse_id' => $this->warehouse2->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 40,
            'lot_number' => 'LOT-TRF-01',
            'notes' => 'Replenish East Coast',
            'in_transit_only' => true,
        ]);

        $dispatchResponse->assertStatus(201);
        $transferId = $dispatchResponse->json('data.id');

        // WH1 on-hand is now 60, in_transit is 40
        $wh1Avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(60, $wh1Avail['on_hand']);
        $this->assertEquals(40, $wh1Avail['in_transit']);

        // WH2 has not received yet: 0 on-hand
        $wh2Avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse2->id, $this->variant->id);
        $this->assertEquals(0, $wh2Avail['on_hand']);

        // Destination receives 38 units (2 units damaged/lost in transit)
        $receiveResponse = $this->actingAs($this->supervisorA, 'sanctum')->postJson("/api/v1/inventory/transfers/{$transferId}/receive", [
            'received_quantity' => 38,
            'discrepancy_reason' => '2 units damaged during truck transport',
        ]);

        $receiveResponse->assertStatus(200);

        // WH2 has 38 units on-hand
        $wh2After = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse2->id, $this->variant->id);
        $this->assertEquals(38, $wh2After['on_hand']);
        $this->assertEquals(38, $wh2After['available']);

        // WH1 in_transit cleared
        $wh1After = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(0, $wh1After['in_transit']);
    }

    /**
     * Test 6: Stock adjustment enforces separation of duties (cannot approve own request).
     */
    public function test_adjustment_approval_separation_of_duties(): void
    {
        // 50 units
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Init'
        );

        // Alice submits adjustment request requiring approval
        $requestResponse = $this->actingAs($this->userA, 'sanctum')->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse1->id,
            'product_variant_id' => $this->variant->id,
            'adjustment_type' => 'subtract',
            'quantity' => 10,
            'reason' => 'Expired stock disposal',
            'require_approval' => true,
        ]);

        $requestResponse->assertStatus(201);
        $adjustmentId = $requestResponse->json('data.id');

        // Alice tries to approve her own request -> Must be blocked (403 Forbidden)
        $selfApproveResponse = $this->actingAs($this->userA, 'sanctum')->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/approve");
        $selfApproveResponse->assertStatus(403);
        $selfApproveResponse->assertJsonFragment(['status' => 'error']);

        // Bob (Supervisor) approves it -> Succeeds (200 OK)
        $supervisorApprove = $this->actingAs($this->supervisorA, 'sanctum')->postJson("/api/v1/inventory/adjustments/{$adjustmentId}/approve");
        $supervisorApprove->assertStatus(200);

        $avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(40, $avail['on_hand']);
    }

    /**
     * Test 7: Cycle count session snapshot baseline, variance calculation, and ledger reconciliation posting.
     */
    public function test_cycle_count_variance_and_reconciliation(): void
    {
        // 100 units
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 100,
            reason: 'Opening stock'
        );

        // Create cycle count
        $createRes = $this->actingAs($this->userA, 'sanctum')->postJson('/api/v1/inventory/cycle-counts', [
            'warehouse_id' => $this->warehouse1->id,
            'title' => 'Q4 Annual Electronics Audit',
            'scope_type' => 'full',
        ]);

        $createRes->assertStatus(201);
        $countId = $createRes->json('data.id');
        $itemId = $createRes->json('data.items.0.id');

        // Record physical count: counted 95 units (variance: -5)
        $recordRes = $this->actingAs($this->userA, 'sanctum')->putJson("/api/v1/inventory/cycle-counts/{$countId}/record-counts", [
            'counts' => [
                [
                    'item_id' => $itemId,
                    'counted_quantity' => 95,
                    'notes' => '5 units missing from shelf',
                ],
            ],
        ]);

        $recordRes->assertStatus(200);
        $this->assertEquals(-5, $recordRes->json('data.items.0.variance_quantity'));

        // Supervisor approves and posts variances
        $postRes = $this->actingAs($this->supervisorA, 'sanctum')->postJson("/api/v1/inventory/cycle-counts/{$countId}/approve-and-post");
        $postRes->assertStatus(200);

        // New on-hand balance is accurately adjusted to 95
        $avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(95, $avail['on_hand']);
        $this->assertEquals(95, $avail['available']);
    }

    /**
     * Test 8: Quarantined stock is excluded from available-to-promise reservations.
     */
    public function test_quarantined_stock_is_excluded_from_availability(): void
    {
        // 50 units
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Initial stock'
        );

        // Quarantine 20 units
        $this->inventoryEngine->quarantineStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            quantity: 20,
            reason: 'Pending QA inspection batch test'
        );

        $avail = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(50, $avail['on_hand']);
        $this->assertEquals(20, $avail['quarantined']);
        $this->assertEquals(30, $avail['available']); // Only 30 can be promised/reserved!

        // Release 10 units after inspection passed
        $this->inventoryEngine->releaseQuarantine(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            quantity: 10,
            reason: 'QA inspection passed for batch part 1'
        );

        $afterRelease = $this->inventoryEngine->getAvailability($this->orgA->id, $this->warehouse1->id, $this->variant->id);
        $this->assertEquals(10, $afterRelease['quarantined']);
        $this->assertEquals(40, $afterRelease['available']);
    }

    /**
     * Test 9: Ledger-to-balance reconciliation detects discrepancies without silently overwriting.
     */
    public function test_ledger_to_balance_reconciliation(): void
    {
        // Normal state: ledger matches materialized balance
        $this->inventoryEngine->adjustStock(
            organizationId: $this->orgA->id,
            warehouseId: $this->warehouse1->id,
            productVariantId: $this->variant->id,
            adjustmentType: 'add',
            quantity: 50,
            reason: 'Init'
        );

        $reconcileRes = $this->actingAs($this->userA, 'sanctum')->postJson('/api/v1/inventory/reconcile', [
            'warehouse_id' => $this->warehouse1->id,
        ]);

        $reconcileRes->assertStatus(200);
        $this->assertTrue($reconcileRes->json('data.reconciled'));
        $this->assertEquals(0, $reconcileRes->json('data.discrepancy_count'));
    }

    /**
     * Test 10: Strict multi-tenant isolation across all inventory resources.
     */
    public function test_stock_alerts_list_out_of_stock_and_low_items_for_the_bell(): void
    {
        $item = function (string $sku, ?int $available, ?int $reorderPoint = null, bool $active = true) {
            $variant = ProductVariant::create(['organization_id' => $this->orgA->id, 'product_id' => $this->product->id, 'sku' => $sku, 'price' => 10, 'is_active' => $active]);
            if ($available !== null) {
                InventoryBalance::create(['organization_id' => $this->orgA->id, 'warehouse_id' => $this->warehouse1->id, 'product_variant_id' => $variant->id, 'on_hand' => $available, 'available' => $available]);
            }
            if ($reorderPoint !== null) {
                ReorderRule::create(['organization_id' => $this->orgA->id, 'warehouse_id' => $this->warehouse1->id, 'product_variant_id' => $variant->id, 'min_quantity' => 0, 'reorder_point' => $reorderPoint, 'reorder_quantity' => 50, 'is_active' => true]);
            }
        };

        $item('LOW-DEFAULT', 3);          // at or below the default threshold of 10
        $item('EMPTY', 0);                // out of stock
        $item('LOW-RULE', 20, 25);        // below its own reorder point
        $item('HEALTHY', 15);             // above the default threshold: no alert
        $item('RULE-ONLY', null, 5);      // rule but never stocked: out of stock
        $item('ARCHIVED', 0, null, false); // archived products do not alert

        $response = $this->actingAs($this->userA, 'sanctum')->getJson('/api/v1/inventory/replenishment/alerts')->assertOk();

        $this->assertEqualsCanonicalizing(['EMPTY', 'RULE-ONLY', 'LOW-DEFAULT', 'LOW-RULE'], array_column($response->json('data'), 'sku'));
        $this->assertSame('out_of_stock', $response->json('data.0.status'));
        $response->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.out_of_stock', 2)
            ->assertJsonPath('meta.low_stock', 2);
        $this->assertSame(25, collect($response->json('data'))->firstWhere('sku', 'LOW-RULE')['threshold']);

        $this->actingAs($this->userB, 'sanctum')->getJson('/api/v1/inventory/replenishment/alerts')->assertJsonPath('meta.total', 0);
    }

    public function test_cross_tenant_isolation_is_strictly_enforced(): void
    {
        // User B (from Org B) attempts to view or transfer stock from Org A's warehouse
        $forbiddenTransfer = $this->actingAs($this->userB, 'sanctum')->postJson('/api/v1/inventory/transfers', [
            'from_warehouse_id' => $this->warehouse1->id, // Org A warehouse
            'to_warehouse_id' => $this->warehouse2->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 10,
        ]);

        $forbiddenTransfer->assertStatus(404); // Scoped to org, cannot find warehouse from another tenant
    }
}
