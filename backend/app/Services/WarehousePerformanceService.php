<?php

namespace App\Services;

use App\Models\CycleCountItem;
use App\Models\GoodsReceivedNote;
use App\Models\InventoryLocationBalance;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\PickTask;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Warehouse performance measured the way the industry does (WERC DC Measures), from records Finovo already
 * keeps: scans, goods received notes, pick tasks, shipments, cycle counts and returns. Each measure carries the
 * best-in-class value, so a manager sees at a glance where the warehouse stands. A measure without data in the
 * period has no value rather than a made-up one.
 */
class WarehousePerformanceService
{
    /** Orders have no promised ship date, so an order is on time when it ships within this many hours. */
    public const ON_TIME_HOURS = 48;

    /** WERC 2025 best-in-class values. */
    public const BENCHMARKS = [
        'picking_accuracy' => 99.68,
        'on_time_shipments' => 99.5,
        'count_accuracy' => 99.5,
        'dock_to_stock' => 3.5,
        'internal_cycle' => 3.36,
        'total_cycle' => 6.0,
        'units_per_hour' => 70.0,
        'capacity_used' => 90.0,
        'damage_free_receipts' => 99.07,
    ];

    /**
     * The warehouse's measures for a period (the last 30 days by default).
     *
     * @return array{period: array{from: string, to: string}, measures: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function dashboard(Warehouse $warehouse, ?string $from = null, ?string $to = null): array
    {
        $from = ($from ? CarbonImmutable::parse($from) : CarbonImmutable::now()->subDays(29))->startOfDay();
        $to = ($to ? CarbonImmutable::parse($to) : CarbonImmutable::now())->endOfDay();

        $packed = PickTask::where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['packed', 'shipped'])
            ->whereBetween('completed_at', [$from, $to])
            ->get(['id', 'items_count', 'scan_progress', 'pick_errors', 'pack_errors', 'created_at', 'started_at', 'completed_at']);
        // Accuracy is only known for orders checked by scanning at packing.
        $scanPacked = $packed->filter(fn (PickTask $task) => ! empty($task->scan_progress['pack']));
        $timed = $packed->filter(fn (PickTask $task) => $task->started_at && $task->completed_at?->gt($task->started_at));
        $pickingHours = $timed->sum(fn (PickTask $task) => $task->started_at->diffInSeconds($task->completed_at) / 3600);

        $shipments = Shipment::where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['shipped', 'delivered'])
            ->whereBetween('shipped_at', [$from, $to])
            ->with('order:id,placed_at,created_at')
            ->get(['id', 'order_id', 'shipped_at', 'created_at']);
        $orderIds = $shipments->pluck('order_id')->unique()->values();
        $orderToShip = $shipments->map(fn (Shipment $s) => $this->hours($s->order?->placed_at ?? $s->order?->created_at, $s->shipped_at))->filter(fn ($h) => $h !== null);
        // Internal cycle: from the order reaching the warehouse (its pick task) to packed (its shipment waiting).
        $taskStart = PickTask::whereIn('order_id', $orderIds)->pluck('created_at', 'order_id');
        $internal = $shipments->map(fn (Shipment $s) => $this->hours($taskStart[$s->order_id] ?? null, $s->created_at))->filter(fn ($h) => $h !== null);

        $receipts = GoodsReceivedNote::where('warehouse_id', $warehouse->id)
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$from, $to])
            ->get(['received_at', 'posted_at', 'total_damaged_units', 'total_rejected_units']);

        $counted = CycleCountItem::query()
            ->whereHas('cycleCount', fn ($q) => $q->where('warehouse_id', $warehouse->id)->whereNotNull('posted_at')->whereBetween('posted_at', [$from, $to]))
            ->whereNotNull('counted_quantity')
            ->get(['snapshot_quantity', 'counted_quantity']);

        $lines = $orderIds->isEmpty() ? collect() : OrderItem::whereIn('order_id', $orderIds)->get(['quantity', 'fulfilled_quantity']);
        $returns = OrderReturn::where('warehouse_id', $warehouse->id)->whereBetween('created_at', [$from, $to])->count();
        [$stored, $capacity] = $this->capacity($warehouse);

        $measures = [
            $this->measure('picking_accuracy', 'Order picking accuracy', 'Orders packed with no wrong unit found at the packing scan.', $this->share($scanPacked, fn (PickTask $t) => $t->pack_errors === 0), '%', 'higher', $scanPacked->count(), 'orders scanned at packing'),
            $this->measure('on_time_shipments', 'On-time shipments', 'Orders shipped within '.self::ON_TIME_HOURS.' hours of being placed.', $this->share($orderToShip, fn (float $h) => $h <= self::ON_TIME_HOURS), '%', 'higher', $orderToShip->count(), 'orders shipped'),
            $this->measure('count_accuracy', 'Inventory count accuracy', 'Counted lines whose count matched the system, in posted cycle counts.', $this->share($counted, fn ($i) => (int) $i->counted_quantity === (int) $i->snapshot_quantity), '%', 'higher', $counted->count(), 'lines counted'),
            $this->measure('dock_to_stock', 'Dock-to-stock time', 'Average time from goods arriving to being posted into stock (goods received notes).', $this->average($receipts->map(fn ($r) => $this->hours($r->received_at, $r->posted_at))), 'hours', 'lower', $receipts->count(), 'receipts posted'),
            $this->measure('internal_cycle', 'Internal order cycle time', 'Average time from an order reaching the warehouse to being packed and ready to ship.', $this->average($internal), 'hours', 'lower', $internal->count(), 'orders shipped'),
            $this->measure('total_cycle', 'Total order cycle time', 'Average time from the customer placing the order to it being shipped.', $this->average($orderToShip), 'hours', 'lower', $orderToShip->count(), 'orders shipped'),
            $this->measure('units_per_hour', 'Units picked per hour', 'Units picked per hour of picking (first scan to picked).', $pickingHours > 0 ? round($timed->sum('items_count') / $pickingHours, 1) : null, 'units/hour', 'higher', $timed->count(), 'orders timed'),
            $this->measure('capacity_used', 'Storage capacity used', 'Units in stock against the capacity of bins that have a capacity set.', $capacity > 0 ? round($stored / $capacity * 100, 2) : null, '%', 'higher', (int) $capacity, 'units of capacity'),
            $this->measure('damage_free_receipts', 'Damage-free receipts', 'Supplier deliveries received with no damaged or rejected units.', $this->share($receipts, fn ($r) => (int) $r->total_damaged_units === 0 && (int) $r->total_rejected_units === 0), '%', 'higher', $receipts->count(), 'receipts posted'),
            $this->measure('fill_rate', 'Fill rate', 'Units shipped against units ordered, for the orders shipped.', $lines->sum('quantity') > 0 ? round($lines->sum('fulfilled_quantity') / $lines->sum('quantity') * 100, 2) : null, '%', 'higher', $lines->count(), 'order lines'),
            $this->measure('return_rate', 'Return rate', 'Returns opened against orders shipped.', $orderIds->isNotEmpty() ? round($returns / $orderIds->count() * 100, 2) : null, '%', 'lower', $orderIds->count(), 'orders shipped'),
            $this->measure('wrong_units_stopped', 'Wrong units stopped by scanning', 'Wrong or extra units refused at the shelf or at packing, before they reached a customer.', (float) $packed->sum(fn (PickTask $t) => $t->pick_errors + $t->pack_errors), 'units', 'lower', $packed->count(), 'orders packed'),
        ];

        $rated = collect($measures)->whereNotNull('meets');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'measures' => $measures,
            'summary' => [
                'meeting_benchmark' => $rated->where('meets', true)->count(),
                'below_benchmark' => $rated->where('meets', false)->count(),
                'no_data' => collect($measures)->whereNull('value')->count(),
            ],
        ];
    }

    /**
     * Units stored in bins with a capacity, and that capacity.
     *
     * @return array{0: float, 1: float}
     */
    private function capacity(Warehouse $warehouse): array
    {
        $bins = WarehouseLocation::where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->where('capacity', '>', 0)
            ->where('capacity_unit', 'units')
            ->pluck('capacity', 'id');

        $stored = $bins->isEmpty() ? 0 : InventoryLocationBalance::whereIn('warehouse_location_id', $bins->keys())->where('quantity', '>', 0)->sum('quantity');

        return [(float) $stored, (float) $bins->sum()];
    }

    /**
     * @return array{key: string, label: string, description: string, value: float|null, unit: string, benchmark: float|null, better: string, meets: bool|null, sample: int, sample_label: string}
     */
    private function measure(string $key, string $label, string $description, ?float $value, string $unit, string $better, int $sample, string $sampleLabel): array
    {
        $benchmark = self::BENCHMARKS[$key] ?? null;
        $meets = $value === null || $benchmark === null ? null : ($better === 'higher' ? $value >= $benchmark : $value <= $benchmark);

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'value' => $value,
            'unit' => $unit,
            'benchmark' => $benchmark,
            'better' => $better,
            'meets' => $meets,
            'sample' => $sample,
            'sample_label' => $sampleLabel,
        ];
    }

    /**
     * Percentage of the rows that pass the check; null without rows.
     */
    private function share(Collection $rows, callable $passes): ?float
    {
        return $rows->isEmpty() ? null : round($rows->filter($passes)->count() / $rows->count() * 100, 2);
    }

    private function average(Collection $hours): ?float
    {
        $hours = $hours->filter(fn ($h) => $h !== null);

        return $hours->isEmpty() ? null : round($hours->avg(), 2);
    }

    private function hours(?DateTimeInterface $start, ?DateTimeInterface $end): ?float
    {
        if (! $start || ! $end || $end < $start) {
            return null;
        }

        return round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2);
    }
}
