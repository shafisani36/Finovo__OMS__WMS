<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Finovo Logistics Org',
            'slug' => 'finovo-logistics-org',
        ]);

        $this->adminUser = User::create([
            'name' => 'Warehouse Admin',
            'email' => 'admin.wh@finovo.com',
            'password' => Hash::make('Password1234!'),
            'role' => UserRole::ADMIN,
            'status' => 'active',
            'current_organization_id' => $this->org->id,
            'otp_enabled' => true,
        ]);

        $this->adminUser->memberships()->create([
            'organization_id' => $this->org->id,
            'role' => 'admin',
        ]);

        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_create_warehouse_with_wizard_data(): void
    {
        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Apex Distribution Center',
            'code' => 'wh-apex-01',
            'warehouse_type' => 'distribution_center',
            'timezone' => 'UTC',
            'city' => 'Dubai',
            'country' => 'United Arab Emirates',
            'status' => 'draft',
            'inventory_policies' => [
                'allow_negative_inventory' => false,
                'picking_strategy' => 'FIFO',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'name' => 'Apex Distribution Center',
                    'code' => 'WH-APEX-01',
                    'status' => 'draft',
                    'timezone' => 'UTC',
                ],
            ]);

        $this->assertDatabaseHas('warehouses', [
            'organization_id' => $this->org->id,
            'code' => 'WH-APEX-01',
        ]);
    }

    public function test_duplicate_warehouse_code_within_same_org_is_rejected(): void
    {
        Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Original Hub',
            'code' => 'WH-UNIQUE',
            'timezone' => 'UTC',
            'status' => 'draft',
        ]);

        $response = $this->postJson('/api/v1/warehouses', [
            'name' => 'Second Hub',
            'code' => 'wh-unique',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_cross_tenant_access_is_blocked(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Competitor Org',
            'slug' => 'competitor-org',
        ]);

        $foreignWarehouse = Warehouse::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Competitor Warehouse',
            'code' => 'WH-FOREIGN',
            'timezone' => 'UTC',
            'status' => 'active',
        ]);

        // Attempt read
        $readResponse = $this->getJson("/api/v1/warehouses/{$foreignWarehouse->id}");
        $readResponse->assertStatus(403);

        // Attempt update
        $updateResponse = $this->putJson("/api/v1/warehouses/{$foreignWarehouse->id}", [
            'name' => 'Hijacked Name',
        ]);
        $updateResponse->assertStatus(403);
    }

    public function test_physical_layout_hierarchy_and_circular_reference_prevention(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Layout Test Facility',
            'code' => 'WH-LAYOUT',
            'timezone' => 'UTC',
            'status' => 'draft',
        ]);

        // 1. Create Zone (Parent)
        $zoneRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/locations", [
            'code' => 'ZONE-A',
            'location_type' => 'picking',
            'picking_eligible' => true,
            'putaway_eligible' => true,
        ]);
        $zoneRes->assertStatus(201);
        $zoneId = $zoneRes->json('data.id');

        // 2. Create Aisle (Child)
        $aisleRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/locations", [
            'code' => 'AISLE-01',
            'parent_id' => $zoneId,
            'location_type' => 'aisle',
        ]);
        $aisleRes->assertStatus(201);
        $aisleId = $aisleRes->json('data.id');

        // 3. Attempt to set Zone parent to Aisle (Circular reference)
        $circularRes = $this->putJson("/api/v1/warehouses/{$warehouse->id}/locations/{$zoneId}", [
            'parent_id' => $aisleId,
        ]);
        $circularRes->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_starter_layout_generation(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Starter Facility',
            'code' => 'WH-STARTER',
            'timezone' => 'UTC',
            'status' => 'draft',
        ]);

        // 1. Preview
        $previewRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/locations/starter-layout", [
            'preview' => true,
            'aisles_count' => 2,
            'racks_per_aisle' => 2,
        ]);
        $previewRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'preview' => true,
                ],
            ]);

        $this->assertEquals(0, WarehouseLocation::where('warehouse_id', $warehouse->id)->count());

        // 2. Commit
        $commitRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/locations/starter-layout", [
            'preview' => false,
            'aisles_count' => 1,
            'racks_per_aisle' => 1,
            'shelves_per_rack' => 1,
            'bins_per_shelf' => 2,
        ]);
        $commitRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'preview' => false,
                ],
            ]);

        $this->assertGreaterThan(0, WarehouseLocation::where('warehouse_id', $warehouse->id)->count());
    }

    public function test_activation_blocked_when_readiness_checks_fail_and_passes_when_complete(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Readiness Test Facility',
            'code' => 'WH-READY-TEST',
            'timezone' => 'UTC',
            'status' => 'draft',
        ]);

        // Attempt activation with 0 locations
        $activateFail = $this->postJson("/api/v1/warehouses/{$warehouse->id}/activate");
        $activateFail->assertStatus(422)
            ->assertJsonValidationErrors(['readiness']);

        // Add picking and putaway locations
        WarehouseLocation::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'LOC-01',
            'picking_eligible' => true,
            'putaway_eligible' => true,
            'is_active' => true,
        ]);

        // Now activate succeeds
        $activateSuccess = $this->postJson("/api/v1/warehouses/{$warehouse->id}/activate");
        $activateSuccess->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'status' => 'active',
                    'is_active' => true,
                ],
            ]);
    }

    public function test_staff_assignment_and_removal(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Staff Test Warehouse',
            'code' => 'WH-STAFF',
            'timezone' => 'UTC',
            'status' => 'draft',
        ]);

        $staffUser = User::create([
            'name' => 'Floor Operator',
            'email' => 'operator.staff@finovo.com',
            'password' => Hash::make('Password1234!'),
            'role' => UserRole::OPERATOR,
            'status' => 'active',
            'current_organization_id' => $this->org->id,
            'otp_enabled' => true,
        ]);

        $staffUser->memberships()->create([
            'organization_id' => $this->org->id,
            'role' => 'operator',
        ]);

        // Assign staff
        $assignRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/staff", [
            'user_id' => $staffUser->id,
            'role' => 'picker',
        ]);
        $assignRes->assertStatus(201);
        $membershipId = $assignRes->json('data.id');

        $this->assertDatabaseHas('warehouse_memberships', [
            'warehouse_id' => $warehouse->id,
            'user_id' => $staffUser->id,
            'role' => 'picker',
        ]);

        // Remove staff
        $removeRes = $this->deleteJson("/api/v1/warehouses/{$warehouse->id}/staff/{$membershipId}");
        $removeRes->assertStatus(200);

        $this->assertDatabaseMissing('warehouse_memberships', [
            'id' => $membershipId,
        ]);
    }

    public function test_opening_stock_preview_and_atomic_commit(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->org->id,
            'name' => 'Opening Stock Facility',
            'code' => 'WH-STOCK',
            'timezone' => 'UTC',
            'status' => 'active',
            'is_active' => true,
        ]);

        $location = WarehouseLocation::create([
            'organization_id' => $this->org->id,
            'warehouse_id' => $warehouse->id,
            'code' => 'BIN-A1',
            'picking_eligible' => true,
            'putaway_eligible' => true,
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'PROD-KB-ERG',
            'name' => 'Ergonomic Keyboard',
            'slug' => 'ergonomic-keyboard',
            'status' => 'active',
        ]);

        $variant = ProductVariant::create([
            'organization_id' => $this->org->id,
            'product_id' => $product->id,
            'sku' => 'KB-ERG-BLK',
            'title' => 'Black Ergonomic Keyboard',
            'price' => 129.99,
        ]);

        // 1. Preview
        $previewRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/opening-stock/preview", [
            'items' => [
                [
                    'sku' => 'KB-ERG-BLK',
                    'location_code' => 'BIN-A1',
                    'quantity' => 50,
                    'lot_number' => 'LOT-2026-001',
                ],
            ],
        ]);
        $previewRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'can_commit' => true,
                    'total_units' => 50,
                    'error_count' => 0,
                ],
            ]);

        // 2. Commit
        $commitRes = $this->postJson("/api/v1/warehouses/{$warehouse->id}/opening-stock/commit", [
            'idempotency_key' => 'IDEM-OPN-STK-001',
            'items' => [
                [
                    'sku' => 'KB-ERG-BLK',
                    'location_code' => 'BIN-A1',
                    'quantity' => 50,
                    'lot_number' => 'LOT-2026-001',
                ],
            ],
        ]);
        $commitRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'committed_lines' => 1,
                    'total_units' => 50,
                ],
            ]);

        // Verify balance in inventory_balances
        $this->assertDatabaseHas('inventory_balances', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'on_hand' => 50,
            'available' => 50,
        ]);

        // Verify double-entry ledger entry
        $this->assertDatabaseHas('inventory_ledger_entries', [
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
            'movement_type' => 'adjustment',
            'quantity' => 50,
        ]);
    }
}
