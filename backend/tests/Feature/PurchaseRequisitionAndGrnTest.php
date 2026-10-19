<?php

namespace Tests\Feature;

use App\Models\GoodsReceivedNote;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequisitionAndGrnTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $orgA;
    protected Organization $orgB;
    protected User $requesterA;
    protected User $managerA;
    protected User $userB;
    protected Warehouse $warehouseA;
    protected WarehouseLocation $dockLocation;
    protected Product $product;
    protected ProductVariant $variant1;
    protected ProductVariant $variant2;

    protected function setUp(): void
    {
        parent::setUp();

        // Org A
        $this->orgA = Organization::create(['name' => 'Finovo Logistics', 'slug' => 'finovo']);
        $this->requesterA = User::create([
            'name' => 'Alice Requester',
            'email' => 'alice@finovo.com',
            'password' => bcrypt('Password123!'),
            'role' => 'operator',
            'is_active' => true,
            'current_organization_id' => $this->orgA->id,
        ]);
        $this->requesterA->memberships()->create(['organization_id' => $this->orgA->id, 'role' => 'operator']);

        $this->managerA = User::create([
            'name' => 'Marcus Manager',
            'email' => 'marcus@finovo.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'is_active' => true,
            'current_organization_id' => $this->orgA->id,
        ]);
        $this->managerA->memberships()->create(['organization_id' => $this->orgA->id, 'role' => 'admin']);

        // Org B (For multi-tenant isolation tests)
        $this->orgB = Organization::create(['name' => 'Rival Logistics', 'slug' => 'rival']);
        $this->userB = User::create([
            'name' => 'Bob Rival',
            'email' => 'bob@rival.com',
            'password' => bcrypt('Password123!'),
            'role' => 'admin',
            'is_active' => true,
            'current_organization_id' => $this->orgB->id,
        ]);
        $this->userB->memberships()->create(['organization_id' => $this->orgB->id, 'role' => 'admin']);

        // Warehouse & Locations in Org A
        $this->warehouseA = Warehouse::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Central Hub WH-1',
            'code' => 'WH-CH1',
            'address' => '100 Industrial Parkway',
            'is_active' => true,
        ]);

        $this->dockLocation = WarehouseLocation::create([
            'organization_id' => $this->orgA->id,
            'warehouse_id' => $this->warehouseA->id,
            'code' => 'DOCK-IN-01',
            'zone' => 'RECEIVING',
            'aisle' => '01',
            'rack' => '01',
            'shelf' => 'A',
            'bin' => '01',
            'is_active' => true,
        ]);

        // Products & Variants
        $this->product = Product::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Industrial Barcode Scanner',
            'sku' => 'SCN-IND',
            'category' => 'Electronics',
            'base_uom' => 'pcs',
            'tracking_type' => 'lot_tracked',
        ]);

        $this->variant1 = ProductVariant::create([
            'organization_id' => $this->orgA->id,
            'product_id' => $this->product->id,
            'sku' => 'SCN-IND-01',
            'barcode' => '789123456001',
            'price' => 199.99,
            'cost' => 120.00,
        ]);

        $this->variant2 = ProductVariant::create([
            'organization_id' => $this->orgA->id,
            'product_id' => $this->product->id,
            'sku' => 'SCN-IND-02',
            'barcode' => '789123456002',
            'price' => 249.99,
            'cost' => 150.00,
        ]);
    }

    public function test_can_create_purchase_requisition_as_draft_with_items_and_totals(): void
    {
        $response = $this->actingAs($this->requesterA, 'sanctum')->postJson('/api/v1/purchase-requisitions', [
            'warehouse_id' => $this->warehouseA->id,
            'department' => 'Operations & Warehouse Tech',
            'priority' => 'high',
            'required_by_date' => now()->addDays(14)->toDateString(),
            'purpose' => 'Q4 Warehouse terminal expansion',
            'justification' => 'Current scanners are aging and failing battery tests during pick waves.',
            'currency' => 'USD',
            'tax_rate' => 5.0,
            'items' => [
                [
                    'product_variant_id' => $this->variant1->id,
                    'sku' => $this->variant1->sku,
                    'product_name' => 'Industrial Barcode Scanner v1',
                    'requested_quantity' => 10,
                    'estimated_unit_price' => 120.00,
                    'uom' => 'pcs',
                ],
                [
                    'product_variant_id' => $this->variant2->id,
                    'sku' => $this->variant2->sku,
                    'product_name' => 'Industrial Barcode Scanner v2 Pro',
                    'requested_quantity' => 5,
                    'estimated_unit_price' => 150.00,
                    'uom' => 'pcs',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.priority', 'high');

        $this->assertDatabaseHas('purchase_requisitions', [
            'organization_id' => $this->orgA->id,
            'requester_id' => $this->requesterA->id,
            'status' => 'draft',
            'subtotal' => 1950.00, // (10 * 120) + (5 * 150) = 1200 + 750 = 1950
            'tax_amount' => 97.50, // 5% of 1950
            'total_amount' => 2047.50,
        ]);

        $this->assertDatabaseCount('purchase_requisition_items', 2);
        $this->assertDatabaseHas('purchase_requisition_approvals', [
            'action' => 'created',
            'user_id' => $this->requesterA->id,
        ]);
    }

    public function test_can_submit_requisition_for_approval(): void
    {
        $pr = $this->createDraftPr();

        $response = $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit", [
            'comments' => 'Budget approved by Dept Head, requesting procurement signoff.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('purchase_requisitions', [
            'id' => $pr->id,
            'status' => 'submitted',
        ]);

        $this->assertDatabaseHas('purchase_requisition_approvals', [
            'purchase_requisition_id' => $pr->id,
            'action' => 'submitted',
            'user_id' => $this->requesterA->id,
        ]);
    }

    public function test_separation_of_duties_prevents_requester_from_approving_own_requisition(): void
    {
        $pr = $this->createDraftPr();

        // Submit first
        $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit");

        // Requester tries to self-approve!
        $response = $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/approve", [
            'comments' => 'I approve my own request.',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['approver']);

        // Status must remain submitted
        $this->assertEquals('submitted', $pr->fresh()->status);
    }

    public function test_authorized_manager_can_approve_requisition(): void
    {
        $pr = $this->createDraftPr();
        $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit");

        // Manager approves
        $response = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/approve", [
            'comments' => 'Verified against Capex Q4 allocation. Approved.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'approved');

        $fresh = $pr->fresh();
        $this->assertEquals('approved', $fresh->status);
        $this->assertEquals($this->managerA->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);

        // Check approved quantities on items
        foreach ($fresh->items as $item) {
            $this->assertEquals($item->requested_quantity, $item->approved_quantity);
        }
    }

    public function test_can_reject_requisition_with_reason(): void
    {
        $pr = $this->createDraftPr();
        $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit");

        $response = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/reject", [
            'reason' => 'Budget freeze until next fiscal quarter.',
            'comments' => 'Please resubmit in January.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'rejected');

        $fresh = $pr->fresh();
        $this->assertEquals('rejected', $fresh->status);
        $this->assertEquals($this->managerA->id, $fresh->rejected_by);
        $this->assertEquals('Budget freeze until next fiscal quarter.', $fresh->rejection_reason);
    }

    public function test_can_convert_approved_pr_to_purchase_order_with_remaining_quantities(): void
    {
        $pr = $this->createDraftPr();
        $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit");
        $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/approve");

        $prItem1 = $pr->items()->first();

        // Partially order: 6 units out of 10 for item 1
        $response = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/convert-po", [
            'supplier_name' => 'Zebra Technologies Supplier',
            'supplier_email' => 'orders@zebra.com',
            'payment_terms' => 'Net 45',
            'items' => [
                [
                    'purchase_requisition_item_id' => $prItem1->id,
                    'ordered_quantity' => 6,
                    'unit_cost' => 118.00, // Negotiated discount
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.supplier_name', 'Zebra Technologies Supplier');

        // PR should now be partially_ordered
        $this->assertEquals('partially_ordered', $pr->fresh()->status);
        $this->assertEquals(6, $prItem1->fresh()->ordered_quantity);
        $this->assertEquals(4, $prItem1->fresh()->remaining_quantity);

        // Convert the remaining units (4 of item 1, and 5 of item 2)
        $prItem2 = $pr->items()->skip(1)->first();
        $response2 = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/convert-po", [
            'supplier_name' => 'Honeywell Supplier Corp',
            'items' => [
                [
                    'purchase_requisition_item_id' => $prItem1->id,
                    'ordered_quantity' => 4,
                    'unit_cost' => 120.00,
                ],
                [
                    'purchase_requisition_item_id' => $prItem2->id,
                    'ordered_quantity' => 5,
                    'unit_cost' => 150.00,
                ],
            ],
        ]);

        $response2->assertStatus(201);
        // All approved items fully ordered -> PR status becomes 'ordered'
        $this->assertEquals('ordered', $pr->fresh()->status);
        $this->assertEquals(0, $prItem1->fresh()->remaining_quantity);
        $this->assertEquals(0, $prItem2->fresh()->remaining_quantity);
    }

    public function test_cannot_order_more_than_remaining_approved_quantity(): void
    {
        $pr = $this->createDraftPr();
        $this->actingAs($this->requesterA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/submit");
        $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/approve");

        $prItem1 = $pr->items()->first();

        // Try ordering 15 units when only 10 were approved
        $response = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/convert-po", [
            'supplier_name' => 'Zebra Technologies Supplier',
            'items' => [
                [
                    'purchase_requisition_item_id' => $prItem1->id,
                    'ordered_quantity' => 15,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_can_create_grn_against_purchase_order_with_condition_breakdown(): void
    {
        $po = $this->createSamplePo();

        $response = $this->actingAs($this->requesterA, 'sanctum')->postJson('/api/v1/goods-received-notes', [
            'purchase_order_id' => $po->id,
            'receiving_location_id' => $this->dockLocation->id,
            'supplier_delivery_note' => 'DN-994821',
            'carrier' => 'DHL Freight',
            'tracking_or_vehicle_number' => 'TRK-DHL-001',
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'received_quantity' => 10,
                    'accepted_quantity' => 8,
                    'damaged_quantity' => 1,
                    'quarantined_quantity' => 1,
                    'rejected_quantity' => 0,
                    'pending_inspection_quantity' => 0,
                    'lot_number' => 'LOT-2026-X1',
                    'expiry_date' => now()->addYears(2)->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_received_units', 10)
            ->assertJsonPath('data.total_accepted_units', 8)
            ->assertJsonPath('data.total_damaged_units', 1)
            ->assertJsonPath('data.total_quarantined_units', 1);

        $this->assertDatabaseHas('goods_received_notes', [
            'purchase_order_id' => $po->id,
            'supplier_delivery_note' => 'DN-994821',
            'total_received_units' => 10,
        ]);
    }

    public function test_grn_over_receipt_tolerance_enforcement(): void
    {
        $po = $this->createSamplePo(); // 10 units ordered

        // Try to receive 15 units without allow_over_receipt flag (> 10% tolerance = 11)
        $response = $this->actingAs($this->requesterA, 'sanctum')->postJson('/api/v1/goods-received-notes', [
            'purchase_order_id' => $po->id,
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'received_quantity' => 15,
                    'accepted_quantity' => 15,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // With explicit allow_over_receipt = true, should succeed
        $responseValid = $this->actingAs($this->requesterA, 'sanctum')->postJson('/api/v1/goods-received-notes', [
            'purchase_order_id' => $po->id,
            'allow_over_receipt' => true,
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'received_quantity' => 15,
                    'accepted_quantity' => 15,
                ],
            ],
        ]);

        $responseValid->assertStatus(201);
    }

    public function test_can_post_grn_to_authoritative_inventory_with_conditions_and_update_po(): void
    {
        $po = $this->createSamplePo(); // Ordered 10 units

        // Create GRN: 7 accepted, 2 damaged, 1 quarantined
        $grnResponse = $this->actingAs($this->requesterA, 'sanctum')->postJson('/api/v1/goods-received-notes', [
            'purchase_order_id' => $po->id,
            'receiving_location_id' => $this->dockLocation->id,
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'received_quantity' => 10,
                    'accepted_quantity' => 7,
                    'damaged_quantity' => 2,
                    'quarantined_quantity' => 1,
                    'rejected_quantity' => 0,
                    'pending_inspection_quantity' => 0,
                    'lot_number' => 'LOT-2026-POST',
                ],
            ],
        ]);

        $grnId = $grnResponse->json('data.id');

        // Post to authoritative inventory!
        $postResponse = $this->actingAs($this->managerA, 'sanctum')->postJson("/api/v1/goods-received-notes/{$grnId}/post");

        $postResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'posted');

        // Verify Authoritative Inventory Balances:
        // On hand = 10 (7 accepted + 2 damaged + 1 quarantined)
        // Available = 7
        // Damaged = 2
        // Quarantined = 1
        $balance = InventoryBalance::where('organization_id', $this->orgA->id)
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('product_variant_id', $this->variant1->id)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(10, $balance->on_hand);
        $this->assertEquals(7, $balance->available);
        $this->assertEquals(2, $balance->damaged);
        $this->assertEquals(1, $balance->quarantined);

        // Verify Immutable Inventory Ledger entries were written
        $ledgerEntries = InventoryLedgerEntry::where('organization_id', $this->orgA->id)
            ->where('product_variant_id', $this->variant1->id)
            ->get();

        $this->assertCount(3, $ledgerEntries); // 1 for available (7), 1 for damaged (2), 1 for quarantined (1)

        // Verify PO status updated to 'received' because all 10 ordered units were received
        $poFresh = $po->fresh();
        $this->assertEquals('received', $poFresh->status);
        $this->assertEquals(10, $poFresh->items->first()->received_quantity);
    }

    public function test_multi_tenant_isolation_prevents_cross_org_access(): void
    {
        $pr = $this->createDraftPr();

        // User from Org B cannot view Org A's PR
        $viewResponse = $this->actingAs($this->userB, 'sanctum')->getJson("/api/v1/purchase-requisitions/{$pr->id}");
        $viewResponse->assertStatus(404);

        // User from Org B cannot approve Org A's PR
        $approveResponse = $this->actingAs($this->userB, 'sanctum')->postJson("/api/v1/purchase-requisitions/{$pr->id}/approve");
        $approveResponse->assertStatus(404);

        // Create PO in Org A
        $po = $this->createSamplePo();

        // User from Org B cannot create GRN against Org A's PO
        $grnResponse = $this->actingAs($this->userB, 'sanctum')->postJson('/api/v1/goods-received-notes', [
            'purchase_order_id' => $po->id,
            'items' => [
                [
                    'purchase_order_item_id' => $po->items->first()->id,
                    'received_quantity' => 5,
                    'accepted_quantity' => 5,
                ],
            ],
        ]);
        $grnResponse->assertStatus(404);
    }

    public function test_can_generate_and_stream_pr_and_grn_pdf(): void
    {
        $pr = $this->createDraftPr();

        $prPdfResponse = $this->actingAs($this->requesterA, 'sanctum')->get("/api/v1/purchase-requisitions/{$pr->id}/pdf");
        $prPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $prPdfResponse->headers->get('content-type'));
        $this->assertStringContainsString($pr->pr_number, $prPdfResponse->headers->get('content-disposition'));

        // PO and GRN PDF
        $po = $this->createSamplePo();
        $grn = GoodsReceivedNote::create([
            'organization_id' => $this->orgA->id,
            'grn_number' => 'GRN-2026-TEST-PDF',
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouseA->id,
            'received_by_user_id' => $this->requesterA->id,
            'status' => 'draft',
            'received_at' => now(),
            'total_received_units' => 10,
            'total_accepted_units' => 10,
        ]);

        $grnPdfResponse = $this->actingAs($this->requesterA, 'sanctum')->get("/api/v1/goods-received-notes/{$grn->id}/pdf");
        $grnPdfResponse->assertStatus(200);
        $this->assertEquals('application/pdf', $grnPdfResponse->headers->get('content-type'));
        $this->assertStringContainsString($grn->grn_number, $grnPdfResponse->headers->get('content-disposition'));
    }

    protected function createDraftPr(): PurchaseRequisition
    {
        $pr = PurchaseRequisition::create([
            'organization_id' => $this->orgA->id,
            'pr_number' => 'PR-2026-0001',
            'requester_id' => $this->requesterA->id,
            'department' => 'Operations',
            'warehouse_id' => $this->warehouseA->id,
            'priority' => 'normal',
            'status' => 'draft',
            'subtotal' => 1950.00,
            'total_amount' => 1950.00,
            'currency' => 'USD',
        ]);

        $pr->items()->create([
            'product_variant_id' => $this->variant1->id,
            'sku' => $this->variant1->sku,
            'product_name' => 'Scanner v1',
            'requested_quantity' => 10,
            'estimated_unit_price' => 120.00,
            'estimated_subtotal' => 1200.00,
            'uom' => 'pcs',
        ]);

        $pr->items()->create([
            'product_variant_id' => $this->variant2->id,
            'sku' => $this->variant2->sku,
            'product_name' => 'Scanner v2',
            'requested_quantity' => 5,
            'estimated_unit_price' => 150.00,
            'estimated_subtotal' => 750.00,
            'uom' => 'pcs',
        ]);

        return $pr;
    }

    protected function createSamplePo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'organization_id' => $this->orgA->id,
            'po_number' => 'PO-2026-0001',
            'supplier_name' => 'Zebra Industrial Logistics',
            'warehouse_id' => $this->warehouseA->id,
            'created_by_user_id' => $this->managerA->id,
            'status' => 'issued',
            'order_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 1200.00,
            'total_amount' => 1200.00,
        ]);

        $po->items()->create([
            'product_variant_id' => $this->variant1->id,
            'sku' => $this->variant1->sku,
            'product_name' => 'Scanner v1',
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 120.00,
            'total_cost' => 1200.00,
            'uom' => 'pcs',
        ]);

        return $po;
    }
}
