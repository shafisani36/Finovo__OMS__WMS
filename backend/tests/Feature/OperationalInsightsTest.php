<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Insights worked out from the records (no AI service needed): likely duplicate orders and unusual stock events.
 */
class OperationalInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_orders_and_stock_anomalies_are_flagged(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 10]);

        $first = $this->importOrder($store, $variant, 2, 10, ['customer_email' => 'sara@example.com', 'total_amount' => 20]);
        $second = $this->importOrder($store, $variant, 2, 10, ['customer_email' => 'SARA@example.com ', 'total_amount' => 20]);
        $this->importOrder($store, $variant, 1, 10, ['customer_email' => 'other@example.com', 'total_amount' => 10]);

        InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => -3, 'available' => -3]);
        foreach ([2, 3, 2, 80] as $units) {
            InventoryLedgerEntry::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'movement_type' => 'adjustment', 'quantity' => -$units, 'balance_before' => 0, 'balance_after' => 0, 'reference_number' => "ADJ-{$units}"]);
        }

        $this->actingAs($admin)->getJson('/api/v1/insights')
            ->assertOk()
            ->assertJsonCount(1, 'data.duplicate_orders')
            ->assertJsonPath('data.duplicate_orders.0.order_numbers', [$first->order_number, $second->order_number])
            ->assertJsonPath('data.anomalies.0.type', 'negative_stock')
            ->assertJsonPath('data.anomalies.1.type', 'large_adjustment')
            ->assertJsonPath('data.anomalies.1.reference', 'ADJ-80')
            ->assertJsonCount(2, 'data.anomalies');
    }
}
