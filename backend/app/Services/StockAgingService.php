<?php

namespace App\Services;

use App\Models\InventoryBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock aging: how long the units on hand have been in the warehouse and how fast each product sells.
 *
 * Stock leaves oldest first, so the units still on hand are the latest ones that came in; each is aged from the
 * day its stock arrived. A product is fast moving when it sold at least its stock on hand in the last 90 days,
 * slow when it sold less, and non-moving when it did not sell at all.
 */
class StockAgingService
{
    public const BUCKETS = ['0_30' => 30, '31_60' => 60, '61_90' => 90, 'over_90' => PHP_INT_MAX];

    public const MOVEMENTS = ['fast', 'slow', 'non_moving'];

    /** Sales window used to judge how fast a product moves. */
    public const SALES_DAYS = 90;

    private const SALES = ['shipment'];

    /**
     * @param  list<int>|null  $warehouseIds  only these warehouses (null: all)
     * @param  list<int>|null  $variantIds  only these products (null: all)
     * @return array{data: list<array<string, mixed>>, totals: array{on_hand: int, buckets: array<string, int>}}
     */
    public function report(int $organizationId, ?array $warehouseIds = null, ?array $variantIds = null, ?string $movement = null): array
    {
        $balances = InventoryBalance::with(['variant:id,sku,product_id', 'variant.product:id,name', 'warehouse:id,name'])
            ->where('organization_id', $organizationId)
            ->where('on_hand', '>', 0)
            ->when($warehouseIds !== null, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->when($variantIds !== null, fn ($q) => $q->whereIn('product_variant_id', $variantIds))
            ->get();

        $ledger = fn () => DB::table('inventory_ledger_entries')
            ->where('organization_id', $organizationId)
            ->whereIn('warehouse_id', $balances->pluck('warehouse_id')->unique())
            ->whereIn('product_variant_id', $balances->pluck('product_variant_id')->unique());

        $arrivals = $ledger()->whereIn('movement_type', InventoryEngine::ON_HAND_MOVEMENTS)->where('quantity', '>', 0)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['warehouse_id', 'product_variant_id', 'quantity', 'created_at'])
            ->groupBy(fn ($entry) => "{$entry->warehouse_id}:{$entry->product_variant_id}");
        $sales = $ledger()->whereIn('movement_type', self::SALES)
            ->selectRaw('warehouse_id, product_variant_id, MAX(created_at) AS last_sold_at, -SUM(CASE WHEN created_at >= ? THEN quantity ELSE 0 END) AS sold', [now()->subDays(self::SALES_DAYS)])
            ->groupBy('warehouse_id', 'product_variant_id')
            ->get()
            ->keyBy(fn ($row) => "{$row->warehouse_id}:{$row->product_variant_id}");

        $rows = [];
        foreach ($balances as $balance) {
            $key = "{$balance->warehouse_id}:{$balance->product_variant_id}";
            $sold = (int) ($sales[$key]->sold ?? 0);
            $row = [
                'product_variant_id' => $balance->product_variant_id,
                'sku' => $balance->variant?->sku,
                'product_name' => $balance->variant?->product?->name,
                'warehouse' => $balance->warehouse?->name,
                'on_hand' => $balance->on_hand,
                ...$this->age($balance->on_hand, $arrivals[$key] ?? collect()),
                'last_sold_at' => isset($sales[$key]) ? Carbon::parse($sales[$key]->last_sold_at)->toDateString() : null,
                'sold_90_days' => $sold,
                'movement' => match (true) {
                    $sold <= 0 => 'non_moving',
                    $sold >= $balance->on_hand => 'fast',
                    default => 'slow',
                },
            ];
            if ($movement === null || $row['movement'] === $movement) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b) => $b['oldest_days'] <=> $a['oldest_days'] ?: $b['on_hand'] <=> $a['on_hand']);

        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        foreach ($rows as $row) {
            foreach ($row['buckets'] as $bucket => $units) {
                $buckets[$bucket] += $units;
            }
        }

        return ['data' => $rows, 'totals' => ['on_hand' => array_sum(array_column($rows, 'on_hand')), 'buckets' => $buckets]];
    }

    /**
     * Spreads the units on hand over the latest arrivals, newest first.
     *
     * @return array{buckets: array<string, int>, oldest_days: int}
     */
    private function age(int $onHand, iterable $arrivals): array
    {
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $oldest = 0;
        $left = $onHand;

        foreach ($arrivals as $arrival) {
            if ($left <= 0) {
                break;
            }
            $units = min($left, (int) $arrival->quantity);
            $days = (int) Carbon::parse($arrival->created_at)->startOfDay()->diffInDays(today());
            $buckets[$this->bucket($days)] += $units;
            $oldest = max($oldest, $days);
            $left -= $units;
        }

        // Units older than the ledger (e.g. stock loaded before it was kept) count as the oldest.
        if ($left > 0) {
            $buckets['over_90'] += $left;
        }

        return ['buckets' => $buckets, 'oldest_days' => $oldest];
    }

    private function bucket(int $days): string
    {
        foreach (self::BUCKETS as $bucket => $limit) {
            if ($days <= $limit) {
                return $bucket;
            }
        }

        return 'over_90';
    }
}
