<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Shipment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the analytics page: sales, orders, stores, products, shipping, returns and stock over a period,
 * each compared with the period before it where that makes sense.
 *
 * Everything is summed in the database. Days are calendar days in the business time zone (config
 * app.business_timezone), so an order placed late in the evening counts on the day the team saw it. The report
 * is cached until an order of the company changes (the orders version) and for at most ten minutes, so stock
 * movements show up soon after they happen.
 */
class AnalyticsService
{
    public const PERIODS = [7, 30, 90, 365];

    private const TOP_PRODUCTS = 10;

    private const LOW_STOCK_UNITS = 10;

    private const CACHE_SECONDS = 600;

    /** Ledger movements that put stock into or take it out of a warehouse (not reservations or moves between bins). */
    private const STOCK_FLOW_MOVEMENTS = ['receipt', 'return', 'adjustment', 'transfer_in', 'transfer_out', 'shipment', 'shipment_cancelled'];

    public function __construct(
        protected AccessControl $access
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(User $user, int $days, ?int $storeId, ?int $warehouseId): array
    {
        $org = $user->currentOrganization();
        $warehouseIds = $this->access->warehouseIds($user);
        $timezone = (string) config('app.business_timezone');
        $today = CarbonImmutable::now($timezone);
        $key = 'analytics:'.md5(json_encode([$org->id, Order::listsVersion($org->id), $days, $storeId, $warehouseId, $warehouseIds, $user->hasPermission('inventory.view'), $today->toDateString()]));

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($org, $user, $days, $storeId, $warehouseId, $warehouseIds, $today) {
            $end = $today->endOfDay();
            $start = $end->subDays($days - 1)->startOfDay();
            $previousStart = $start->subDays($days);

            $orders = fn () => Order::where('orders.organization_id', $org->id)
                ->when($storeId, fn ($q) => $q->where('orders.store_id', $storeId))
                ->when($warehouseId, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('allocated_warehouse_id', $warehouseId)));

            $report = [
                'period' => ['days' => $days, 'from' => $start->toDateString(), 'to' => $end->toDateString()],
                'currency' => $orders()->whereNotNull('currency')->groupBy('currency')->orderByRaw('COUNT(*) DESC')->value('currency') ?? 'PKR',
                'kpis' => $this->kpis($orders, $start, $end, $previousStart),
                'daily' => $this->daily($orders, $start, $end, $days),
                'statuses' => $this->statuses($orders, $start, $end),
                'stores' => $this->stores($orders, $start, $end),
                'top_products' => $this->topProducts($orders, $start, $end),
                'carriers' => $this->carriers($orders, $start, $end),
                'return_reasons' => $this->returnReasons($orders, $start, $end),
                'warehouses' => [],
                'stock_flow' => [],
            ];

            if ($user->hasPermission('inventory.view')) {
                $scope = fn (Builder $query, string $column = 'warehouse_id') => $query
                    ->when($warehouseIds !== null, fn ($q) => $q->whereIn($column, $warehouseIds))
                    ->when($warehouseId, fn ($q) => $q->where($column, $warehouseId));
                $report['warehouses'] = $this->warehouses($org->id, $scope, $start, $end);
                $report['stock_flow'] = $this->stockFlow($org->id, $scope, $start, $end, $days);
            }

            return $report;
        });
    }

    /**
     * Headline figures for the period, each with the same figure for the period before.
     *
     * @return array<string, array{value: float, previous: float}>
     */
    private function kpis(callable $orders, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart): array
    {
        $figures = function (CarbonImmutable $from, CarbonImmutable $to) use ($orders) {
            $totals = $this->placedBetween($orders(), $from, $to)->toBase()->selectRaw(
                "COUNT(*) AS orders,
                 COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS revenue,
                 SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                 SUM(CASE WHEN order_status = 'delivered' THEN 1 ELSE 0 END) AS delivered"
            )->first();
            $units = (int) OrderItem::whereIn('order_id', $this->placedBetween($orders(), $from, $to)->where('order_status', '<>', 'cancelled')->select('orders.id'))->sum('quantity');
            $returns = OrderReturn::whereIn('order_id', $this->placedBetween($orders(), $from, $to)->select('orders.id'))->count();
            $minutes = Shipment::whereIn('shipments.order_id', $this->placedBetween($orders(), $from, $to)->select('orders.id'))
                ->whereNotNull('shipments.shipped_at')
                ->join('orders', 'orders.id', '=', 'shipments.order_id')
                ->toBase()
                ->selectRaw('AVG('.$this->minutesBetween('COALESCE(orders.placed_at, orders.created_at)', 'shipments.shipped_at').') AS minutes')
                ->value('minutes');
            $count = (int) $totals->orders;
            $kept = $count - (int) $totals->cancelled;

            return [
                'revenue' => round((float) $totals->revenue, 2),
                'orders' => $count,
                'average_order_value' => $kept > 0 ? round((float) $totals->revenue / $kept, 2) : 0,
                'units_sold' => $units,
                'cancellation_rate' => $count > 0 ? round((int) $totals->cancelled * 100 / $count, 1) : 0,
                'return_rate' => $count > 0 ? round($returns * 100 / $count, 1) : 0,
                'hours_to_ship' => $minutes === null ? 0 : round((float) $minutes / 60, 1),
                'delivered' => (int) $totals->delivered,
            ];
        };

        $current = $figures($start, $end);
        $previous = $figures($previousStart, $start->subSecond());

        return collect($current)->map(fn ($value, $name) => ['value' => $value, 'previous' => $previous[$name]])->all();
    }

    /**
     * One row per day of the period (days without orders included), for the trend charts.
     *
     * @return list<array<string, mixed>>
     */
    private function daily(callable $orders, CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $day = $this->localDay('COALESCE(orders.placed_at, orders.created_at)', $start);
        $placed = $this->placedBetween($orders(), $start, $end)->toBase()
            ->selectRaw("{$day} AS day, COUNT(*) AS orders, COALESCE(SUM(CASE WHEN order_status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS revenue")
            ->groupByRaw($day)
            ->get()
            ->keyBy('day');
        $shipped = Shipment::whereIn('order_id', $orders()->select('orders.id'))
            ->whereBetween('shipped_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw($this->localDay('shipped_at', $start).' AS day, COUNT(*) AS shipped')
            ->groupByRaw($this->localDay('shipped_at', $start))
            ->pluck('shipped', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $placed, $shipped) {
            $date = $start->addDays($offset)->toDateString();

            return [
                'date' => $date,
                'orders' => (int) ($placed[$date]->orders ?? 0),
                'revenue' => round((float) ($placed[$date]->revenue ?? 0), 2),
                'shipped' => (int) ($shipped[$date] ?? 0),
            ];
        })->all();
    }

    /**
     * @return list<array{status: string, count: int}>
     */
    private function statuses(callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->placedBetween($orders(), $start, $end)->toBase()
            ->selectRaw('order_status AS status, COUNT(*) AS count')
            ->groupBy('order_status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['status' => (string) $row->status, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Orders and sales per store (orders typed into Finovo count as "Finovo").
     *
     * @return list<array{name: string, platform: string, orders: int, revenue: float}>
     */
    private function stores(callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->placedBetween($orders(), $start, $end)
            ->leftJoin('stores', 'stores.id', '=', 'orders.store_id')
            ->toBase()
            ->selectRaw("COALESCE(stores.name, 'Finovo') AS name, COALESCE(stores.platform, 'manual') AS platform, COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN orders.order_status <> 'cancelled' THEN orders.total_amount ELSE 0 END), 0) AS revenue")
            ->groupBy('stores.name', 'stores.platform')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'platform' => (string) $row->platform, 'orders' => (int) $row->orders, 'revenue' => round((float) $row->revenue, 2)])
            ->all();
    }

    /**
     * @return list<array{name: string, sku: string, quantity: int, revenue: float}>
     */
    private function topProducts(callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return OrderItem::whereIn('order_id', $this->placedBetween($orders(), $start, $end)->where('order_status', '<>', 'cancelled')->select('orders.id'))
            ->toBase()
            ->selectRaw('MAX(product_name) AS name, external_sku AS sku, SUM(quantity) AS quantity, COALESCE(SUM(total_amount), 0) AS revenue')
            ->groupBy('external_sku')
            ->orderByDesc('quantity')
            ->limit(self::TOP_PRODUCTS)
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'sku' => (string) $row->sku, 'quantity' => (int) $row->quantity, 'revenue' => round((float) $row->revenue, 2)])
            ->all();
    }

    /**
     * Parcels sent in the period by courier, and how many of them arrived.
     *
     * @return list<array{carrier: string, shipments: int, delivered: int}>
     */
    private function carriers(callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Shipment::whereIn('order_id', $orders()->select('orders.id'))
            ->whereBetween('shipped_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw("COALESCE(carrier, 'Unknown') AS carrier, COUNT(*) AS shipments, SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered")
            ->groupBy('carrier')
            ->orderByDesc('shipments')
            ->get()
            ->map(fn ($row) => ['carrier' => (string) $row->carrier, 'shipments' => (int) $row->shipments, 'delivered' => (int) $row->delivered])
            ->all();
    }

    /**
     * @return list<array{reason: string, count: int}>
     */
    private function returnReasons(callable $orders, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return OrderReturn::whereIn('order_id', $orders()->select('orders.id'))
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(reason, ''), 'No reason given') AS reason, COUNT(*) AS count")
            ->groupByRaw("COALESCE(NULLIF(reason, ''), 'No reason given')")
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['reason' => (string) $row->reason, 'count' => (int) $row->count])
            ->all();
    }

    /**
     * Stock in each warehouse now, its value at cost, and the units that left it in the period.
     *
     * @return list<array<string, mixed>>
     */
    private function warehouses(int $orgId, callable $scope, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $stock = $scope(InventoryBalance::where('inventory_balances.organization_id', $orgId), 'inventory_balances.warehouse_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventory_balances.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->toBase()
            ->selectRaw('warehouses.id, warehouses.name, COALESCE(SUM(inventory_balances.on_hand), 0) AS on_hand,
                COALESCE(SUM(inventory_balances.reserved), 0) AS reserved, COALESCE(SUM(inventory_balances.available), 0) AS available,
                COALESCE(SUM(inventory_balances.on_hand * product_variants.cost_price), 0) AS stock_value,
                SUM(CASE WHEN inventory_balances.available <= ? THEN 1 ELSE 0 END) AS low_stock', [self::LOW_STOCK_UNITS])
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->get();

        $shipped = $scope(Shipment::where('organization_id', $orgId))
            ->whereBetween('shipped_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw('warehouse_id, COUNT(*) AS shipments')
            ->groupBy('warehouse_id')
            ->pluck('shipments', 'warehouse_id');

        return $stock->map(fn ($row) => [
            'name' => (string) $row->name,
            'on_hand' => (int) $row->on_hand,
            'reserved' => (int) $row->reserved,
            'available' => (int) $row->available,
            'stock_value' => round((float) $row->stock_value, 2),
            'low_stock' => (int) $row->low_stock,
            'shipments' => (int) ($shipped[$row->id] ?? 0),
        ])->all();
    }

    /**
     * Units that came into and went out of stock each day (receipts, returns, adjustments, transfers, sales).
     *
     * @return list<array{date: string, inbound: int, outbound: int}>
     */
    private function stockFlow(int $orgId, callable $scope, CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $change = '(balance_after - balance_before)';
        $rows = $scope(InventoryLedgerEntry::where('organization_id', $orgId))
            ->whereIn('movement_type', self::STOCK_FLOW_MOVEMENTS)
            ->whereBetween('created_at', [$start->utc(), $end->utc()])
            ->toBase()
            ->selectRaw($this->localDay('created_at', $start)." AS day, SUM(CASE WHEN {$change} > 0 THEN {$change} ELSE 0 END) AS inbound, SUM(CASE WHEN {$change} < 0 THEN -{$change} ELSE 0 END) AS outbound")
            ->groupByRaw($this->localDay('created_at', $start))
            ->get()
            ->keyBy('day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $rows) {
            $date = $start->addDays($offset)->toDateString();

            return ['date' => $date, 'inbound' => (int) ($rows[$date]->inbound ?? 0), 'outbound' => (int) ($rows[$date]->outbound ?? 0)];
        })->all();
    }

    /**
     * Orders placed in the period (orders without a placing time count from when they were saved). Written as two
     * ranges on placed_at so the database uses the (organization_id, placed_at) index.
     */
    private function placedBetween(Builder $orders, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $range = [$from->utc(), $to->utc()];

        return $orders->where(fn (Builder $q) => $q->whereBetween('orders.placed_at', $range)
            ->orWhere(fn (Builder $q) => $q->whereNull('orders.placed_at')->whereBetween('orders.created_at', $range)));
    }

    /**
     * SQL for the business-time-zone calendar day of a UTC timestamp column. The zone's offset on the first day
     * of the period is used for the whole period (exact for zones without daylight saving, such as Pakistan).
     */
    private function localDay(string $column, CarbonImmutable $start): string
    {
        $minutes = (int) $start->utcOffset();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "DATE({$column}, '{$minutes} minutes')"
            : "DATE(DATE_ADD({$column}, INTERVAL {$minutes} MINUTE))";
    }

    /**
     * SQL for the minutes from one timestamp column to another, for the database in use.
     */
    private function minutesBetween(string $from, string $to): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "(julianday({$to}) - julianday({$from})) * 1440"
            : "TIMESTAMPDIFF(MINUTE, {$from}, {$to})";
    }
}
