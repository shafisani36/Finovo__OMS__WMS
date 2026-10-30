<?php

namespace App\Services;

use App\Models\InventoryLocationBalance;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ABC slotting and putaway suggestions for one warehouse.
 *
 * ABC: products ranked by sales value in the warehouse over a period. The ones making up the first 80% of the
 * value are A, the next 15% B, the rest (and products in stock that did not sell) C.
 *
 * Bins are grouped by the area (top-level zone) they are in: the picking area is next to packing, so A items
 * belong there; bulk storage is for C items and overstock. Receiving, packing, dispatch and quarantine areas
 * never take stock. Bins without an area are general storage. Within an area, bins are taken in their sort
 * order and code (aisle 1 first), so the first bins are treated as the nearest to packing.
 */
class SlottingService
{
    public const LOOKBACK_DAYS = 90;

    public const A_SHARE = 0.80;

    public const B_SHARE = 0.95;

    /** Areas a bin can belong to, from the type of its top-level zone. */
    private const AREA_PICK = 'picking';

    private const AREA_BULK = 'bulk';

    private const AREA_GENERAL = 'general';

    /** Top-level zone types that never hold stored stock. */
    private const NO_STORAGE_AREAS = ['receiving', 'packing', 'dispatch', 'quarantine'];

    /** Areas each class should be stored in, best first. */
    private const AREA_PREFERENCE = [
        'A' => [self::AREA_PICK, self::AREA_GENERAL, self::AREA_BULK],
        'B' => [self::AREA_GENERAL, self::AREA_PICK, self::AREA_BULK],
        'C' => [self::AREA_BULK, self::AREA_GENERAL, self::AREA_PICK],
    ];

    /** @var array<int, Collection<int, array<string, mixed>>> storage bins per warehouse, cached for one request */
    private array $bins = [];

    /**
     * Every product sold from or stored in the warehouse, with its ABC class, where its stock is and whether
     * it sits in the right area.
     *
     * @return array{days: int, items: list<array<string, mixed>>, moves: list<array<string, mixed>>, summary: array<string, int|float>}
     */
    public function analyse(Warehouse $warehouse, int $days = self::LOOKBACK_DAYS): array
    {
        $classes = $this->classes($warehouse, $days);
        $bins = $this->storageBins($warehouse);
        $stock = InventoryLocationBalance::where('warehouse_id', $warehouse->id)
            ->where('condition', 'available')
            ->where('quantity', '>', 0)
            ->whereIn('warehouse_location_id', $bins->keys())
            ->get(['warehouse_location_id', 'product_variant_id', 'lot_number', 'quantity']);

        $variantIds = $stock->pluck('product_variant_id')->merge(array_keys($classes))->unique();
        $variants = ProductVariant::with('product:id,name')->whereIn('id', $variantIds)->get()->keyBy('id');

        $items = $variantIds->map(function (int $variantId) use ($classes, $stock, $bins, $variants) {
            $class = $classes[$variantId] ?? ['class' => 'C', 'sold' => 0, 'value' => 0.0, 'share' => 0.0];
            $held = $stock->where('product_variant_id', $variantId)->map(fn ($row) => [
                'location_id' => $row->warehouse_location_id,
                'code' => $bins[$row->warehouse_location_id]['code'],
                'area' => $bins[$row->warehouse_location_id]['area'],
                'lot_number' => $row->lot_number,
                'quantity' => (int) $row->quantity,
            ])->values();
            $preferred = self::AREA_PREFERENCE[$class['class']][0];
            $variant = $variants[$variantId] ?? null;

            return [
                'product_variant_id' => $variantId,
                'sku' => $variant?->sku,
                'name' => $variant?->product?->name,
                'variant' => $variant?->option_value,
                'abc_class' => $class['class'],
                'units_sold' => $class['sold'],
                'sales_value' => round($class['value'], 2),
                'value_share' => round($class['share'] * 100, 2),
                'on_hand' => (int) $held->sum('quantity'),
                'bins' => $held,
                'preferred_area' => $preferred,
                // Stock out of place: A items outside the picking area, C items taking picking space.
                'misplaced_units' => (int) $held->filter(fn (array $bin) => $this->misplaced($class['class'], $bin['area'], $bins))->sum('quantity'),
            ];
        })->sortBy([['abc_class', 'asc'], ['sales_value', 'desc']])->values();

        return [
            'days' => $days,
            'items' => $items->all(),
            'moves' => $this->moves($warehouse, $items, $bins),
            'summary' => [
                'a_items' => $items->where('abc_class', 'A')->count(),
                'b_items' => $items->where('abc_class', 'B')->count(),
                'c_items' => $items->where('abc_class', 'C')->count(),
                'misplaced_units' => (int) $items->sum('misplaced_units'),
                'picking_bins' => $bins->where('area', self::AREA_PICK)->count(),
                'bulk_bins' => $bins->where('area', self::AREA_BULK)->count(),
            ],
        ];
    }

    /**
     * The bin to put received units in: a bin already holding the same product and lot with room, otherwise
     * the first bin with room in the best area for the product's ABC class.
     *
     * @return array{abc_class: string, location: array<string, mixed>|null, reason: string, alternatives: list<array<string, mixed>>}
     */
    public function suggestPutaway(Warehouse $warehouse, int $variantId, int $quantity = 1, ?string $lotNumber = null): array
    {
        $class = $this->classes($warehouse, self::LOOKBACK_DAYS)[$variantId]['class'] ?? 'C';
        $bins = $this->storageBins($warehouse);
        $used = $this->usedByBin($warehouse);

        $sameStock = InventoryLocationBalance::where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variantId)
            ->where('condition', 'available')
            ->where('quantity', '>', 0)
            ->where('lot_number', $lotNumber)
            ->pluck('warehouse_location_id')
            ->filter(fn (int $id) => isset($bins[$id]) && $bins[$id]['putaway'] && $this->fits($bins[$id], $used, $quantity))
            ->sortBy(fn (int $id) => array_search($bins[$id]['area'], self::AREA_PREFERENCE[$class], true))
            ->values();

        $ranked = $this->binsWithRoom($bins, $used, $quantity, self::AREA_PREFERENCE[$class], putaway: true);
        $best = $sameStock->isNotEmpty() ? $bins[$sameStock->first()] : $ranked->first();

        return [
            'abc_class' => $class,
            'location' => $best ? $this->present($best, $used) : null,
            'reason' => match (true) {
                $best === null => 'No putaway bin has room for '.$quantity.' units. Add bins or set a larger capacity.',
                $sameStock->isNotEmpty() => 'This bin already holds the same product'.($lotNumber ? " and lot {$lotNumber}" : '').', so the stock stays together.',
                $best['area'] === self::AREA_PREFERENCE[$class][0] => "Class {$class} item: first bin with room in the ".$this->areaLabel($best['area']).'.',
                default => "Class {$class} item: the ".$this->areaLabel(self::AREA_PREFERENCE[$class][0]).' has no room, so the next best area is used.',
            },
            'alternatives' => $ranked->reject(fn (array $bin) => $best && $bin['id'] === $best['id'])->take(4)->map(fn (array $bin) => $this->present($bin, $used))->values()->all(),
        ];
    }

    /**
     * Sales units and value per variant for the warehouse in the period, with the ABC class.
     *
     * @return array<int, array{class: string, sold: int, value: float, share: float}>
     */
    private function classes(Warehouse $warehouse, int $days): array
    {
        $sales = OrderItem::query()
            ->from('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.organization_id', $warehouse->organization_id)
            ->where('o.order_status', '!=', 'cancelled')
            ->where('i.status', '!=', 'cancelled')
            ->where('i.allocated_warehouse_id', $warehouse->id)
            ->whereNotNull('i.product_variant_id')
            ->whereRaw('COALESCE(o.placed_at, o.created_at) >= ?', [now()->subDays($days)])
            ->groupBy('i.product_variant_id')
            ->get(['i.product_variant_id', DB::raw('SUM(i.quantity) as sold'), DB::raw('SUM(i.quantity * i.unit_price) as value')])
            // Items sold at no price still rank by units.
            ->map(fn ($row) => ['id' => (int) $row->product_variant_id, 'sold' => (int) $row->sold, 'value' => (float) $row->value ?: (float) $row->sold])
            ->sortByDesc('value')
            ->values();

        $total = $sales->sum('value');
        $running = 0.0;
        $classes = [];

        foreach ($sales as $row) {
            // The class is decided by where the item starts on the cumulative curve, so the item that crosses 80%
            // is still A.
            $class = $running < self::A_SHARE * $total ? 'A' : ($running < self::B_SHARE * $total ? 'B' : 'C');
            $running += $row['value'];
            $classes[$row['id']] = ['class' => $class, 'sold' => $row['sold'], 'value' => $row['value'], 'share' => $total > 0 ? $row['value'] / $total : 0.0];
        }

        return $classes;
    }

    /**
     * Moves that put stock in the right area: A items into picking bins, then C items out of picking bins.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  Collection<int, array<string, mixed>>  $bins
     * @return list<array<string, mixed>>
     */
    private function moves(Warehouse $warehouse, Collection $items, Collection $bins): array
    {
        $used = $this->usedByBin($warehouse);
        $moves = [];

        foreach ($items->whereIn('abc_class', ['A', 'C']) as $item) {
            $target = $item['abc_class'] === 'A' ? [self::AREA_PICK] : [self::AREA_BULK, self::AREA_GENERAL];

            foreach ($item['bins'] as $held) {
                if (! $this->misplaced($item['abc_class'], $held['area'], $bins)) {
                    continue;
                }

                // A bin that already holds the item is used first, then the first bin with room.
                $own = collect($item['bins'])->filter(fn (array $b) => in_array($b['area'], $target, true))->pluck('location_id')->all();
                $to = $this->binsWithRoom($bins, $used, 1, $target, putaway: false)
                    ->sortBy(fn (array $bin) => in_array($bin['id'], $own, true) ? 0 : 1)
                    ->first();

                if (! $to) {
                    continue;
                }

                $room = $to['capacity'] === null ? $held['quantity'] : (int) $to['capacity'] - ($used[$to['id']] ?? 0);
                $quantity = min($held['quantity'], $room);
                $used[$to['id']] = ($used[$to['id']] ?? 0) + $quantity;

                $moves[] = [
                    'product_variant_id' => $item['product_variant_id'],
                    'sku' => $item['sku'],
                    'name' => $item['name'],
                    'abc_class' => $item['abc_class'],
                    'lot_number' => $held['lot_number'],
                    'quantity' => $quantity,
                    'from' => ['id' => $held['location_id'], 'code' => $held['code'], 'area' => $held['area']],
                    'to' => ['id' => $to['id'], 'code' => $to['code'], 'area' => $to['area']],
                    'reason' => $item['abc_class'] === 'A'
                        ? 'Fast seller stored away from packing: move it to the picking area.'
                        : 'Slow seller taking picking space: move it to bulk storage.',
                ];
            }
        }

        return $moves;
    }

    /**
     * Whether stock of a class is in the wrong area. Only judged when the warehouse has the right area at all.
     *
     * @param  Collection<int, array<string, mixed>>  $bins
     */
    private function misplaced(string $class, string $area, Collection $bins): bool
    {
        return match ($class) {
            'A' => $area !== self::AREA_PICK && $bins->contains('area', self::AREA_PICK),
            'C' => $area === self::AREA_PICK && $bins->contains(fn (array $bin) => $bin['area'] !== self::AREA_PICK),
            default => false,
        };
    }

    /**
     * Bins with room for the quantity, in the given areas and their walking order.
     *
     * @param  Collection<int, array<string, mixed>>  $bins
     * @param  array<int, int>  $used
     * @param  list<string>  $areas
     * @return Collection<int, array<string, mixed>>
     */
    private function binsWithRoom(Collection $bins, array $used, int $quantity, array $areas, bool $putaway): Collection
    {
        return $bins
            ->filter(fn (array $bin) => in_array($bin['area'], $areas, true) && (! $putaway || $bin['putaway']) && $this->fits($bin, $used, $quantity))
            ->sortBy(fn (array $bin) => [array_search($bin['area'], $areas, true), $bin['sort_order'], $bin['code']])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $bin
     * @param  array<int, int>  $used
     */
    private function fits(array $bin, array $used, int $quantity): bool
    {
        return $bin['capacity'] === null || ($used[$bin['id']] ?? 0) + $quantity <= $bin['capacity'];
    }

    /**
     * Active bins that can hold stock (the lowest level of the layout, outside the no-storage areas), keyed by ID.
     *
     * @return Collection<int, array{id: int, code: string, area: string, capacity: int|null, sort_order: int, putaway: bool}>
     */
    private function storageBins(Warehouse $warehouse): Collection
    {
        if (isset($this->bins[$warehouse->id])) {
            return $this->bins[$warehouse->id];
        }

        $locations = WarehouseLocation::where('warehouse_id', $warehouse->id)
            ->get(['id', 'parent_id', 'code', 'location_type', 'capacity', 'capacity_unit', 'sort_order', 'putaway_eligible', 'is_active'])
            ->keyBy('id');
        $parents = $locations->pluck('parent_id')->filter()->flip();

        $areaOf = function (WarehouseLocation $location) use ($locations): string {
            $seen = [];
            while ($location->parent_id && isset($locations[$location->parent_id]) && ! isset($seen[$location->id])) {
                $seen[$location->id] = true;
                $location = $locations[$location->parent_id];
            }

            return match (true) {
                $location->location_type === 'picking' => self::AREA_PICK,
                $location->location_type === 'bulk_storage' => self::AREA_BULK,
                in_array($location->location_type, self::NO_STORAGE_AREAS, true) => 'none',
                default => self::AREA_GENERAL,
            };
        };

        return $this->bins[$warehouse->id] = $locations
            ->filter(fn (WarehouseLocation $location) => $location->is_active && ! isset($parents[$location->id]))
            ->map(fn (WarehouseLocation $location) => [
                'id' => $location->id,
                'code' => $location->code,
                'area' => $areaOf($location),
                // A capacity in another unit (pallets, kg) cannot be compared with units, so it does not limit.
                'capacity' => $location->capacity !== null && $location->capacity_unit === 'units' ? (int) $location->capacity : null,
                'sort_order' => (int) $location->sort_order,
                'putaway' => (bool) $location->putaway_eligible,
            ])
            ->reject(fn (array $bin) => $bin['area'] === 'none');
    }

    /**
     * Units in each bin of the warehouse, whatever the product.
     *
     * @return array<int, int>
     */
    private function usedByBin(Warehouse $warehouse): array
    {
        return InventoryLocationBalance::where('warehouse_id', $warehouse->id)
            ->where('quantity', '>', 0)
            ->groupBy('warehouse_location_id')
            ->selectRaw('warehouse_location_id, SUM(quantity) as units')
            ->pluck('units', 'warehouse_location_id')
            ->map(fn ($units) => (int) $units)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $bin
     * @param  array<int, int>  $used
     * @return array<string, mixed>
     */
    private function present(array $bin, array $used): array
    {
        $inBin = $used[$bin['id']] ?? 0;

        return [
            'id' => $bin['id'],
            'code' => $bin['code'],
            'area' => $bin['area'],
            'area_label' => $this->areaLabel($bin['area']),
            'units_in_bin' => $inBin,
            'room' => $bin['capacity'] === null ? null : max(0, $bin['capacity'] - $inBin),
        ];
    }

    private function areaLabel(string $area): string
    {
        return [self::AREA_PICK => 'picking area', self::AREA_BULK => 'bulk storage', self::AREA_GENERAL => 'general storage'][$area] ?? $area;
    }
}
