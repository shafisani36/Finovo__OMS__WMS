<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Stock aging: how long the units on hand have been in the warehouse (the oldest units leave first, so what is left
 * is the latest stock received), when the product last sold, and whether it moves fast, slowly or not at all.
 */
class StockAgingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 12:00:00');
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->warehouse = Warehouse::create(['organization_id' => $this->orgId(), 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
    }

    public function test_units_on_hand_are_aged_from_the_receipts_still_in_stock(): void
    {
        $serum = $this->variant('SER-01');
        $this->movement($serum, 'receipt', 10, 120);
        $this->movement($serum, 'receipt', 10, 45);
        $this->movement($serum, 'shipment', -12, 20);
        $this->movement($serum, 'receipt', 5, 10);
        $this->onHand($serum, 13);

        $lipBalm = $this->variant('LIP-01');
        $this->movement($lipBalm, 'receipt', 8, 200);
        $this->onHand($lipBalm, 8);

        $this->actingAs($this->admin)->getJson('/api/v1/inventory/aging')
            ->assertOk()
            ->assertJsonPath('totals.on_hand', 21)
            ->assertJsonPath('totals.buckets', ['0_30' => 5, '31_60' => 8, '61_90' => 0, 'over_90' => 8])
            ->assertJsonPath('data.0.sku', 'LIP-01')
            ->assertJsonPath('data.0.oldest_days', 200)
            ->assertJsonPath('data.0.movement', 'non_moving')
            ->assertJsonPath('data.0.last_sold_at', null)
            ->assertJsonPath('data.1.sku', 'SER-01')
            ->assertJsonPath('data.1.buckets', ['0_30' => 5, '31_60' => 8, '61_90' => 0, 'over_90' => 0])
            ->assertJsonPath('data.1.sold_90_days', 12)
            ->assertJsonPath('data.1.movement', 'slow');
    }

    public function test_a_product_that_sells_its_stock_within_the_period_is_fast_moving(): void
    {
        $face = $this->variant('FW-01');
        $this->movement($face, 'receipt', 30, 40);
        $this->movement($face, 'shipment', -20, 5);
        $this->onHand($face, 10);

        $this->actingAs($this->admin)->getJson('/api/v1/inventory/aging?movement=fast')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.movement', 'fast');
    }

    private function variant(string $sku): ProductVariant
    {
        return Product::create(['organization_id' => $this->orgId(), 'name' => $sku, 'sku' => $sku])
            ->variants()->create(['organization_id' => $this->orgId(), 'sku' => $sku, 'price' => 10]);
    }

    private function movement(ProductVariant $variant, string $type, int $quantity, int $daysAgo): void
    {
        InventoryLedgerEntry::create([
            'organization_id' => $this->orgId(), 'warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id,
            'movement_type' => $type, 'quantity' => $quantity, 'balance_before' => 0, 'balance_after' => 0,
            'reference_number' => "{$type}-{$daysAgo}", 'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function onHand(ProductVariant $variant, int $quantity): void
    {
        InventoryBalance::create(['organization_id' => $this->orgId(), 'warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => $quantity, 'available' => $quantity]);
    }

    private function orgId(): int
    {
        return $this->admin->currentOrganization()->id;
    }
}
