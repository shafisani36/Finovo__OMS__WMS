<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryLocationBalance;
use App\Models\Order;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use App\Models\WarehouseAuditLog;
use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures for the warehouse detail page: the overview cards, storage by zone, the bin map
 * and the activity log. Everything is worked out from stock balances, locations and history rows,
 * so the page always matches the inventory ledger.
 */
class WarehouseInsights
{
    /** Categories shown by name in the stock chart; the rest are added up as "Other". */
    private const CHART_CATEGORIES = 5;

    public const ACTIVITY_TYPES = ['all', 'stock', 'orders', 'setup'];

    /** Ledger movements that belong to customer orders rather than stock handling. */
    private const ORDER_MOVEMENTS = ['reservation', 'release', 'shipment'];

    /**
     * @return array<string, mixed>
     */
    public function overview(Warehouse $warehouse): array
    {
        $storage = $this->storage($warehouse);

        return [
            'kpis' => $this->kpis($warehouse, $storage['bins']),
            'categories' => $this->stockByCategory($warehouse),
            'capacity' => $storage['bins'],
            'zones' => $storage['zones'],
        ];
    }

    /**
     * Stock movements and setup changes of the warehouse, newest first, as one list.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function activity(Warehouse $warehouse, string $type = 'all', int $page = 1, int $perPage = 20): array
    {
        $take = $page * $perPage;

        $ledger = InventoryLedgerEntry::where('warehouse_id', $warehouse->id)
            ->when($type === 'stock', fn ($q) => $q->whereNotIn('movement_type', self::ORDER_MOVEMENTS))
            ->when($type === 'orders', fn ($q) => $q->whereIn('movement_type', self::ORDER_MOVEMENTS));
        $audit = WarehouseAuditLog::where('warehouse_id', $warehouse->id);

        $useLedger = $type !== 'setup';
        $useAudit = in_array($type, ['all', 'setup'], true);

        $entries = $useLedger
            ? (clone $ledger)->with(['user:id,name', 'variant.product:id,name', 'location:id,code', 'destinationLocation:id,code'])
                ->latest('created_at')->latest('id')->limit($take)->get()
            : collect();
        $orderNumbers = Order::whereIn('id', $entries->where('reference_type', 'Order')->pluck('reference_id')->unique())->pluck('order_number', 'id');

        $rows = $entries->map(fn (InventoryLedgerEntry $entry) => $this->describeMovement($entry, $orderNumbers))
            ->concat($useAudit
                ? (clone $audit)->with('user:id,name')->latest('created_at')->latest('id')->limit($take)->get()->map(fn (WarehouseAuditLog $log) => $this->describeAudit($log))
                : [])
            ->sortByDesc('at')
            ->values();

        $total = ($useLedger ? $ledger->count() : 0) + ($useAudit ? $audit->count() : 0);

        return [
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param  array{total: int, occupied: int, empty: int, percent: int}  $bins
     * @return array<string, int>
     */
    private function kpis(Warehouse $warehouse, array $bins): array
    {
        $totals = InventoryBalance::where('warehouse_id', $warehouse->id)
            ->selectRaw('COUNT(CASE WHEN on_hand > 0 THEN 1 END) as skus, COALESCE(SUM(on_hand), 0) as on_hand, COALESCE(SUM(reserved), 0) as reserved, COALESCE(SUM(available), 0) as available')
            ->first();

        // Same rule as the stock alerts bell: at or below the reorder point, or the default threshold.
        $lowStock = DB::table('inventory_balances as b')
            ->join('product_variants as v', fn ($join) => $join->on('v.id', '=', 'b.product_variant_id')->where('v.is_active', true))
            ->leftJoin('reorder_rules as r', fn ($join) => $join
                ->on('r.warehouse_id', '=', 'b.warehouse_id')
                ->on('r.product_variant_id', '=', 'b.product_variant_id')
                ->where('r.is_active', true))
            ->where('b.warehouse_id', $warehouse->id)
            ->whereRaw('b.available <= COALESCE(r.reorder_point, ?)', [ReorderRule::DEFAULT_LOW_STOCK_THRESHOLD])
            ->selectRaw('COUNT(CASE WHEN b.available <= 0 THEN 1 END) as out_of_stock, COUNT(CASE WHEN b.available > 0 THEN 1 END) as low_stock')
            ->first();

        return [
            'total_skus' => (int) $totals->skus,
            'on_hand' => (int) $totals->on_hand,
            'reserved' => (int) $totals->reserved,
            'available' => (int) $totals->available,
            'low_stock' => (int) $lowStock->low_stock,
            'out_of_stock' => (int) $lowStock->out_of_stock,
            'capacity_percent' => $bins['percent'],
            'empty_bins' => $bins['empty'],
        ];
    }

    /**
     * Units on hand per product category, largest first.
     *
     * @return array{total: int, items: list<array{name: string, units: int, percent: int}>}
     */
    private function stockByCategory(Warehouse $warehouse): array
    {
        $rows = DB::table('inventory_balances as b')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('b.warehouse_id', $warehouse->id)
            ->where('b.on_hand', '>', 0)
            ->groupBy('p.category')
            ->selectRaw("COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorized') as name, SUM(b.on_hand) as units")
            ->orderByDesc('units')
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'units' => (int) $row->units]);

        $shown = $rows->take(self::CHART_CATEGORIES);
        $rest = $rows->slice(self::CHART_CATEGORIES)->sum('units');
        if ($rest > 0) {
            $shown->push(['name' => 'Other', 'units' => $rest]);
        }

        $total = (int) $rows->sum('units');

        return [
            'total' => $total,
            'items' => $shown->map(fn (array $row) => [...$row, 'percent' => $this->percent($row['units'], $total)])->values()->all(),
        ];
    }

    /**
     * Bin usage for the capacity card, and each zone (a top-level location such as receiving, picking or
     * bulk storage) with its stock and bins for the storage table and the map. A bin is in use when it
     * holds any stock, and full when it holds at least its capacity.
     *
     * @return array{bins: array{total: int, occupied: int, empty: int, percent: int}, zones: list<array<string, mixed>>}
     */
    private function storage(Warehouse $warehouse): array
    {
        /** @var Collection<int, WarehouseLocation> $locations */
        $locations = WarehouseLocation::where('warehouse_id', $warehouse->id)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'parent_id', 'code', 'location_type', 'capacity', 'picking_eligible', 'putaway_eligible', 'is_active'])
            ->keyBy('id');

        $stock = InventoryLocationBalance::query()
            ->from('inventory_location_balances as lb')
            ->join('product_variants as v', 'v.id', '=', 'lb.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('lb.warehouse_id', $warehouse->id)
            ->where('lb.quantity', '>', 0)
            ->groupBy('lb.warehouse_location_id', 'p.category')
            ->selectRaw("lb.warehouse_location_id as location_id, COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorized') as category, SUM(lb.quantity) as units")
            ->toBase()
            ->get();

        $unitsAt = $stock->groupBy('location_id')->map(fn (Collection $rows) => (int) $rows->sum('units'));
        $rootOf = $locations->map(fn (WarehouseLocation $location) => $this->root($location, $locations)->id);

        $bins = $locations->filter(fn (WarehouseLocation $l) => $l->location_type === 'bin' && $l->is_active)
            ->map(function (WarehouseLocation $bin) use ($unitsAt, $locations) {
                $units = $unitsAt->get($bin->id, 0);
                $capacity = $bin->capacity !== null ? (float) $bin->capacity : null;
                // Bins are listed under their rack (or their parent) so long codes stay readable on the map.
                $parent = $locations->get($bin->parent_id);
                $group = $parent?->location_type === 'shelf' ? ($locations->get($parent->parent_id) ?? $parent) : $parent;

                return [
                    'id' => $bin->id,
                    'code' => $bin->code,
                    'label' => $group && str_starts_with($bin->code, $group->code.'-') ? substr($bin->code, strlen($group->code) + 1) : $bin->code,
                    'group' => $group?->code,
                    'units' => $units,
                    'capacity' => $capacity,
                    'state' => $units <= 0 ? 'empty' : ($capacity && $units >= $capacity ? 'full' : 'partial'),
                    'picking' => (bool) $bin->picking_eligible,
                    'putaway' => (bool) $bin->putaway_eligible,
                ];
            });

        $zones = $locations->filter(fn (WarehouseLocation $l) => $l->parent_id === null || ! $locations->has($l->parent_id))
            ->reject(fn (WarehouseLocation $l) => $l->location_type === 'bin')
            ->map(function (WarehouseLocation $zone) use ($locations, $rootOf, $bins, $stock) {
                $memberIds = $rootOf->filter(fn (int $rootId) => $rootId === $zone->id)->keys();
                $zoneBins = $bins->only($memberIds->all());
                $occupied = $zoneBins->where('state', '!=', 'empty')->count();
                $zoneStock = $stock->whereIn('location_id', $memberIds->all());
                $aisles = $locations->only($memberIds->all())->where('location_type', 'aisle')->pluck('code')->values();

                return [
                    'id' => $zone->id,
                    'code' => $zone->code,
                    'type' => $zone->location_type,
                    'aisles' => match ($aisles->count()) {
                        0 => null,
                        1 => $aisles->first(),
                        default => $aisles->first().' – '.$aisles->last(),
                    },
                    'main_category' => $zoneStock->groupBy('category')->map(fn (Collection $rows) => $rows->sum('units'))->sortDesc()->keys()->first(),
                    'units' => (int) $zoneStock->sum('units'),
                    'bins_total' => $zoneBins->count(),
                    'bins_free' => $zoneBins->count() - $occupied,
                    'percent' => $zoneBins->isEmpty() ? null : $this->percent($occupied, $zoneBins->count()),
                    'bins' => $zoneBins->values()->all(),
                ];
            })
            ->values();

        // Bins placed directly at the top level have no zone of their own.
        $loose = $bins->filter(fn (array $bin) => $rootOf->get($bin['id']) === $bin['id']);
        if ($loose->isNotEmpty()) {
            $occupied = $loose->where('state', '!=', 'empty')->count();
            $zones->push([
                'id' => null, 'code' => 'Bins without a zone', 'type' => null, 'aisles' => null, 'main_category' => null,
                'units' => (int) $loose->sum('units'), 'bins_total' => $loose->count(), 'bins_free' => $loose->count() - $occupied,
                'percent' => $this->percent($occupied, $loose->count()), 'bins' => $loose->values()->all(),
            ]);
        }

        $occupied = $bins->where('state', '!=', 'empty')->count();

        return [
            'bins' => [
                'total' => $bins->count(),
                'occupied' => $occupied,
                'empty' => $bins->count() - $occupied,
                'percent' => $this->percent($occupied, $bins->count()),
            ],
            'zones' => $zones->all(),
        ];
    }

    /**
     * The top-level location a location sits under (itself when it has no parent).
     *
     * @param  Collection<int, WarehouseLocation>  $locations
     */
    private function root(WarehouseLocation $location, Collection $locations): WarehouseLocation
    {
        // The depth guard stops a damaged parent chain from looping forever.
        for ($depth = 0; $depth < 10 && $location->parent_id && $locations->has($location->parent_id); $depth++) {
            $location = $locations->get($location->parent_id);
        }

        return $location;
    }

    /**
     * @param  Collection<int, string>  $orderNumbers  order numbers by order ID
     * @return array<string, mixed>
     */
    private function describeMovement(InventoryLedgerEntry $entry, Collection $orderNumbers): array
    {
        $quantity = abs((int) $entry->quantity);
        // Reservations do not change on-hand stock, so their rows record the quantity only in the reason.
        if ($quantity === 0 && preg_match('/(\d+) units/', (string) $entry->reason, $match)) {
            $quantity = (int) $match[1];
        }
        $order = $entry->reference_type === 'Order' ? $orderNumbers->get($entry->reference_id) : null;
        $item = trim(($entry->variant?->product?->name ?? 'Unknown item').($entry->variant?->option_value ? ' · '.$entry->variant->option_value : ''));
        $bin = $entry->location?->code;
        $reference = $entry->reference_number ? " ({$entry->reference_number})" : '';
        $units = $quantity === 1 ? '1 unit' : "{$quantity} units";

        $text = match ($entry->movement_type) {
            'receipt' => "Received {$units} of {$item}".($bin ? " into {$bin}" : '').$reference,
            'putaway' => "Put away {$units} of {$item}".($entry->destinationLocation ? " to {$entry->destinationLocation->code}" : '').$reference,
            'adjustment' => 'Adjusted '.$item.' by '.((int) $entry->quantity > 0 ? '+' : '−').$quantity.($entry->reason ? " ({$entry->reason})" : '').$reference,
            'reservation' => "Reserved {$units} of {$item}".($order ? " for order {$order}" : $reference),
            'release' => "Released {$units} of {$item} back to stock".($order ? " from order {$order}" : $reference),
            'shipment' => "Shipped {$units} of {$item}".($order ? " for order {$order}" : $reference),
            'transfer_out' => "Sent {$units} of {$item} to another warehouse{$reference}",
            'transfer_in' => "Received {$units} of {$item} by transfer{$reference}",
            'return' => "Returned {$units} of {$item} to stock{$reference}",
            'quarantine' => "Moved {$units} of {$item} to quarantine{$reference}",
            'release_quarantine' => "Released {$units} of {$item} from quarantine{$reference}",
            default => ucfirst(str_replace('_', ' ', (string) $entry->movement_type))." of {$units} of {$item}{$reference}",
        };

        return $this->movementRow($entry, $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(InventoryLedgerEntry $entry, string $text): array
    {
        return [
            'id' => 'movement-'.$entry->id,
            'kind' => in_array($entry->movement_type, self::ORDER_MOVEMENTS, true) ? 'orders' : 'stock',
            'who' => $entry->user?->name ?? 'System',
            'text' => $text.'.',
            'at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeAudit(WarehouseAuditLog $log): array
    {
        return [
            'id' => 'audit-'.$log->id,
            'kind' => str_starts_with((string) $log->action, 'staff') ? 'staff' : 'setup',
            'who' => $log->user?->name ?? 'System',
            'text' => rtrim((string) $log->description, '.').'.',
            'at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function percent(int|float $part, int|float $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }
}
