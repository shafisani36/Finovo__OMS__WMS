<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OrganizationMembership;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMembership;
use App\Services\InventoryEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_sums_sales_stores_products_shipping_and_stock_for_the_period(): void
    {
        Carbon::setTestNow('2026-11-06 12:00:00');
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $admin->currentOrganization();
        $warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI']);
        $shop = Store::create(['organization_id' => $org->id, 'name' => 'Glow Shop', 'code' => 'GLOW', 'platform' => 'shopify', 'status' => 'active']);
        $variant = Product::create(['organization_id' => $org->id, 'name' => 'Face Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $org->id, 'sku' => 'SER-01', 'price' => 25, 'cost_price' => 10]);
        app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 50, reason: 'Opening');

        $first = $this->importOrder($shop, $variant, 2, 25, ['placed_at' => '2026-11-05 10:00:00']);
        $this->importOrder($shop, $variant, 4, 25, ['placed_at' => '2026-11-06 09:00:00']);
        $this->importOrder($shop, $variant, 1, 25, ['placed_at' => '2026-11-06 09:30:00'])->update(['order_status' => 'cancelled']);
        $this->importOrder($shop, $variant, 3, 25, ['placed_at' => '2026-10-20 09:00:00']);
        // 21:00 UTC on Nov 5 is 02:00 on Nov 6 in Karachi: it counts on Nov 6.
        $this->importOrder($shop, $variant, 1, 10, ['placed_at' => '2026-11-05 21:00:00']);
        Shipment::create(['organization_id' => $org->id, 'order_id' => $first->id, 'warehouse_id' => $warehouse->id, 'carrier' => 'TCS', 'tracking_number' => 'TCS-1', 'status' => 'delivered', 'shipped_at' => '2026-11-05 16:00:00']);

        $data = $this->actingAs($admin)->getJson('/api/v1/analytics?days=7')->assertOk()->json('data');

        $this->assertSame(['value' => 160, 'previous' => 0], array_map('intval', $data['kpis']['revenue']));
        $this->assertSame(4, $data['kpis']['orders']['value']);
        $this->assertEquals(53.33, $data['kpis']['average_order_value']['value']);
        $this->assertSame(7, $data['kpis']['units_sold']['value']);
        $this->assertEquals(25, $data['kpis']['cancellation_rate']['value']);
        $this->assertEquals(6, $data['kpis']['hours_to_ship']['value']);
        $this->assertCount(7, $data['daily']);
        $this->assertSame(['date' => '2026-11-06', 'orders' => 3, 'revenue' => 110, 'shipped' => 0], array_map(fn ($v) => is_float($v) ? (int) $v : $v, end($data['daily'])));
        $this->assertSame([['name' => 'Glow Shop', 'platform' => 'shopify', 'orders' => 4, 'revenue' => 160]], array_map(fn ($row) => [...$row, 'revenue' => (int) $row['revenue']], $data['stores']));
        $this->assertSame(['Face Serum', 7], [$data['top_products'][0]['name'], $data['top_products'][0]['quantity']]);
        $this->assertSame([['carrier' => 'TCS', 'shipments' => 1, 'delivered' => 1]], $data['carriers']);
        $this->assertSame(['Karachi DC', 50, 500, 1], [$data['warehouses'][0]['name'], $data['warehouses'][0]['on_hand'], (int) $data['warehouses'][0]['stock_value'], $data['warehouses'][0]['shipments']]);
        $this->assertSame(50, array_sum(array_column($data['stock_flow'], 'inbound')));

        // A longer period takes in the earlier order; the period must be one of the offered ones.
        $this->assertSame(5, $this->getJson('/api/v1/analytics?days=30')->json('data.kpis.orders.value'));
        $this->getJson('/api/v1/analytics?days=12')->assertUnprocessable();
        Carbon::setTestNow();
    }

    public function test_an_operator_sees_only_the_warehouses_they_work_in(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $admin->currentOrganization();
        $karachi = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI']);
        Warehouse::create(['organization_id' => $org->id, 'name' => 'Lahore DC', 'code' => 'LHR']);
        $variant = Product::create(['organization_id' => $org->id, 'name' => 'Face Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $org->id, 'sku' => 'SER-01', 'price' => 25]);
        foreach (Warehouse::all() as $warehouse) {
            app(InventoryEngine::class)->adjustStock(organizationId: $org->id, warehouseId: $warehouse->id, productVariantId: $variant->id, adjustmentType: 'add', quantity: 5, reason: 'Opening');
        }
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR, 'current_organization_id' => $org->id, 'warehouse_access' => User::WAREHOUSE_ACCESS_ASSIGNED]);
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $operator->id, 'role' => UserRole::OPERATOR->value]);
        WarehouseMembership::create(['organization_id' => $org->id, 'warehouse_id' => $karachi->id, 'user_id' => $operator->id, 'role' => 'operator', 'status' => WarehouseMembership::STATUS_ACTIVE]);

        $this->assertCount(2, $this->actingAs($admin)->getJson('/api/v1/analytics')->json('data.warehouses'));
        $this->assertSame(['Karachi DC'], array_column($this->actingAs($operator)->getJson('/api/v1/analytics')->json('data.warehouses'), 'name'));
        $this->getJson('/api/v1/analytics?warehouse_id='.Warehouse::where('code', 'LHR')->value('id'))->assertUnprocessable();
    }
}
