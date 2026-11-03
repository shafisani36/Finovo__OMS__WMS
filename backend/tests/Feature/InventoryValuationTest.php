<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use App\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inventory valuation with FIFO, LIFO and weighted average cost (AVCO), perpetual (cost taken at every issue) and
 * periodic (cost worked out at the end of the period).
 *
 * Textbook case used below, one warehouse, one product:
 *   1 Jan  buy 100 @ 10     5 Jan  buy 100 @ 12     10 Jan sell 150     15 Jan buy 100 @ 14     20 Jan sell 80
 * Purchases 3,600; 70 units left.
 */
class InventoryValuationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-02-10 12:00:00');

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 25, 'cost_price' => 9]);
    }

    /**
     * @return array<string, array{string, float, float}>
     */
    public static function perpetualCases(): array
    {
        return [
            'FIFO: oldest units leave first' => ['fifo', 2620.0, 980.0],
            'LIFO: newest units leave first' => ['lifo', 2820.0, 780.0],
            'AVCO: moving average at every sale' => ['avco', 2690.0, 910.0],
        ];
    }

    #[DataProvider('perpetualCases')]
    public function test_perpetual_valuation(string $method, float $cogs, float $closing): void
    {
        $this->textbookMovements();

        $row = $this->onlyRow(app(InventoryValuationService::class)->report($this->orgId(), $method, 'perpetual', Carbon::parse('2026-01-01'), now()));

        $this->assertSame([0, 0.0, 300, 3600.0, 230, $cogs, 70, $closing], [
            $row['opening_quantity'], $row['opening_value'], $row['in_quantity'], $row['in_value'],
            $row['out_quantity'], $row['cost_of_goods_sold'], $row['closing_quantity'], $row['closing_value'],
        ]);
    }

    /**
     * @return array<string, array{string, float, float}>
     */
    public static function periodicCases(): array
    {
        return [
            'FIFO: closing stock at the latest costs' => ['fifo', 2620.0, 980.0],
            'LIFO: closing stock at the earliest costs' => ['lifo', 2900.0, 700.0],
            'Weighted average over the period' => ['avco', 2760.0, 840.0],
        ];
    }

    #[DataProvider('periodicCases')]
    public function test_periodic_valuation(string $method, float $cogs, float $closing): void
    {
        $this->textbookMovements();

        $row = $this->onlyRow(app(InventoryValuationService::class)->report($this->orgId(), $method, 'periodic', Carbon::parse('2026-01-01'), now()));

        $this->assertSame([70, $cogs, $closing], [$row['closing_quantity'], $row['cost_of_goods_sold'], $row['closing_value']]);
    }

    public function test_a_period_starts_from_the_stock_left_at_its_start(): void
    {
        $this->textbookMovements();
        // February: sell 20 more.
        $this->movement('shipment', -20, '2026-02-03');

        $row = $this->onlyRow(app(InventoryValuationService::class)->report($this->orgId(), 'fifo', 'perpetual', Carbon::parse('2026-02-01'), now()));

        $this->assertSame([70, 980.0, 0, 20, 280.0, 50, 700.0], [
            $row['opening_quantity'], $row['opening_value'], $row['in_quantity'], $row['out_quantity'],
            $row['cost_of_goods_sold'], $row['closing_quantity'], $row['closing_value'],
        ]);
    }

    public function test_a_cancelled_dispatch_comes_back_at_the_cost_it_left_with(): void
    {
        $this->movement('receipt', 10, '2026-01-01', 10);
        $this->movement('receipt', 10, '2026-01-02', 20);
        $this->movement('shipment', -15, '2026-01-03', reference: 'SHP-7');
        $this->movement('shipment_cancelled', 15, '2026-01-04', reference: 'SHP-7');

        $row = $this->onlyRow(app(InventoryValuationService::class)->report($this->orgId(), 'fifo', 'perpetual', Carbon::parse('2026-01-01'), now()));

        $this->assertSame([20, 300.0], [$row['closing_quantity'], $row['closing_value']]);
    }

    public function test_stock_received_without_a_cost_uses_the_product_cost_price(): void
    {
        // The engine's ledger rows take the database clock.
        Carbon::setTestNow();
        app(InventoryEngine::class)->receiveStock(organizationId: $this->orgId(), warehouseId: $this->warehouse->id, productVariantId: $this->variant->id, quantity: 4);
        app(InventoryEngine::class)->receiveStock(organizationId: $this->orgId(), warehouseId: $this->warehouse->id, productVariantId: $this->variant->id, quantity: 6, unitCost: 11.5);

        $this->assertSame([null, '11.5000'], InventoryLedgerEntry::orderBy('id')->pluck('unit_cost')->all());
        $row = $this->onlyRow(app(InventoryValuationService::class)->report($this->orgId(), 'avco', 'perpetual', now()->subMonth(), now()));
        $this->assertSame([10, 105.0, 10.5], [$row['closing_quantity'], $row['closing_value'], $row['unit_cost']]);
    }

    public function test_the_valuation_api_uses_the_saved_method_and_period(): void
    {
        $this->textbookMovements();
        $this->actingAs($this->admin);

        $this->getJson('/api/v1/inventory/valuation?months=3')
            ->assertOk()
            ->assertJsonPath('method', 'fifo')
            ->assertJsonPath('mode', 'perpetual')
            ->assertJsonPath('from', '2025-11-10')
            ->assertJsonPath('totals.closing_value', 980)
            ->assertJsonPath('data.0.sku', 'SER-01');

        $this->putJson('/api/v1/inventory/valuation/method', ['method' => 'lifo'])->assertOk()->assertJsonPath('method', 'lifo');
        $this->getJson('/api/v1/inventory/valuation?mode=periodic&months=3')
            ->assertOk()
            ->assertJsonPath('method', 'lifo')
            ->assertJsonPath('totals.cost_of_goods_sold', 2900)
            ->assertJsonPath('totals.closing_value', 700);
        $this->getJson('/api/v1/inventory/kpis')->assertOk()->assertJsonPath('data.total_valuation', 780);

        $this->getJson('/api/v1/inventory/valuation?months=2')->assertUnprocessable()->assertJsonValidationErrors('months');
        $this->putJson('/api/v1/inventory/valuation/method', ['method' => 'hifo'])->assertUnprocessable();
    }

    private function textbookMovements(): void
    {
        $this->movement('receipt', 100, '2026-01-01', 10);
        $this->movement('receipt', 100, '2026-01-05', 12);
        $this->movement('shipment', -150, '2026-01-10');
        $this->movement('receipt', 100, '2026-01-15', 14);
        $this->movement('shipment', -80, '2026-01-20');
    }

    private function movement(string $type, int $quantity, string $date, ?float $unitCost = null, ?string $reference = null): void
    {
        InventoryLedgerEntry::create([
            'organization_id' => $this->orgId(),
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $this->variant->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'balance_before' => 0,
            'balance_after' => 0,
            'reference_number' => $reference ?? strtoupper($type).'-'.$date,
            'unit_cost' => $unitCost,
            'created_at' => Carbon::parse($date.' 09:00:00'),
        ]);
    }

    /**
     * @param  array{data: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function onlyRow(array $report): array
    {
        $this->assertCount(1, $report['data']);

        return $report['data'][0];
    }

    private function orgId(): int
    {
        return $this->admin->currentOrganization()->id;
    }
}
