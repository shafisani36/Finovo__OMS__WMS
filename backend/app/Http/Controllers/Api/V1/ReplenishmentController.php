<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReplenishmentController extends Controller
{
    /**
     * List configured reorder rules.
     */
    public function rules(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $rules = $this->inUserWarehouses(ReorderRule::where('organization_id', $org->id), $request)
            ->with(['warehouse', 'variant.product'])
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'warehouse_id' => $r->warehouse_id,
                    'warehouse_name' => $r->warehouse ? $r->warehouse->name : 'N/A',
                    'product_variant_id' => $r->product_variant_id,
                    'product_name' => $r->variant && $r->variant->product ? $r->variant->product->name : 'N/A',
                    'sku' => $r->variant ? $r->variant->sku : 'N/A',
                    'min_quantity' => $r->min_quantity,
                    'reorder_point' => $r->reorder_point,
                    'reorder_quantity' => $r->reorder_quantity,
                    'safety_stock' => $r->safety_stock,
                    'lead_time_days' => $r->lead_time_days,
                    'preferred_supplier' => $r->preferred_supplier,
                    'is_active' => $r->is_active,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $rules,
        ]);
    }

    /**
     * Create or update reorder rule.
     */
    public function saveRule(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'warehouse_id' => ['required', $this->accessibleWarehouse($request)],
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'min_quantity' => ['required', 'integer', 'min:0'],
            'reorder_point' => ['required', 'integer', 'min:1'],
            'reorder_quantity' => ['required', 'integer', 'min:1'],
            'safety_stock' => ['nullable', 'integer', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:1'],
            'preferred_supplier' => ['nullable', 'string', 'max:150'],
        ]);

        Warehouse::where('organization_id', $org->id)->findOrFail($validated['warehouse_id']);
        ProductVariant::where('organization_id', $org->id)->findOrFail($validated['product_variant_id']);

        $rule = ReorderRule::updateOrCreate(
            [
                'organization_id' => $org->id,
                'warehouse_id' => $validated['warehouse_id'],
                'product_variant_id' => $validated['product_variant_id'],
            ],
            [
                'min_quantity' => $validated['min_quantity'],
                'reorder_point' => $validated['reorder_point'],
                'reorder_quantity' => $validated['reorder_quantity'],
                'safety_stock' => $validated['safety_stock'] ?? 5,
                'lead_time_days' => $validated['lead_time_days'] ?? 7,
                'preferred_supplier' => $validated['preferred_supplier'] ?? null,
                'is_active' => true,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Reorder rule configured successfully.',
            'data' => $rule->load(['warehouse', 'variant.product']),
        ]);
    }

    /**
     * Stock alerts for the notification bell: items that ran out or are at or below their reorder point
     * (or the default threshold when they have no reorder rule), worst first.
     */
    public function alerts(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $limit = 50;

        $lowBalances = $this->inUserWarehouses(InventoryBalance::query()->from('inventory_balances as b'), $request, 'b.warehouse_id')
            ->leftJoin('reorder_rules as r', fn ($join) => $join
                ->on('r.warehouse_id', '=', 'b.warehouse_id')
                ->on('r.product_variant_id', '=', 'b.product_variant_id')
                ->where('r.is_active', true))
            // Archived products are not reordered, so they do not raise alerts.
            ->join('product_variants as v', fn ($join) => $join->on('v.id', '=', 'b.product_variant_id')->where('v.is_active', true))
            ->where('b.organization_id', $orgId)
            ->whereRaw('b.available <= COALESCE(r.reorder_point, ?)', [ReorderRule::DEFAULT_LOW_STOCK_THRESHOLD])
            ->select('b.warehouse_id', 'b.product_variant_id', 'b.available', 'r.reorder_point');

        // A reorder rule for an item the warehouse has never held is an empty shelf too.
        $neverStocked = $this->inUserWarehouses(ReorderRule::where('organization_id', $orgId), $request)
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from('inventory_balances')
                ->whereColumn('inventory_balances.warehouse_id', 'reorder_rules.warehouse_id')
                ->whereColumn('inventory_balances.product_variant_id', 'reorder_rules.product_variant_id'))
            ->get(['warehouse_id', 'product_variant_id', 'reorder_point'])
            ->map(fn (ReorderRule $rule) => (object) [...$rule->only(['warehouse_id', 'product_variant_id', 'reorder_point']), 'available' => 0]);

        /** @var Collection<int, object> $rows */
        $rows = $lowBalances->get()->map(fn ($row) => (object) $row->getAttributes())->concat($neverStocked)->sortBy('available')->values();
        $shown = $rows->take($limit);

        $variants = ProductVariant::with('product')->whereIn('id', $shown->pluck('product_variant_id'))->get()->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $shown->pluck('warehouse_id'))->pluck('name', 'id');

        return response()->json([
            'status' => 'success',
            'data' => $shown->map(function ($row) use ($variants, $warehouses) {
                $variant = $variants->get($row->product_variant_id);

                return [
                    'warehouse_id' => (int) $row->warehouse_id,
                    'warehouse_name' => $warehouses->get($row->warehouse_id),
                    'product_variant_id' => (int) $row->product_variant_id,
                    'product_name' => $variant?->product?->name,
                    'variant' => $variant?->option_value,
                    'sku' => $variant?->sku,
                    'image_url' => $variant?->product?->image_url,
                    'available' => (int) $row->available,
                    'threshold' => (int) ($row->reorder_point ?? ReorderRule::DEFAULT_LOW_STOCK_THRESHOLD),
                    'has_rule' => $row->reorder_point !== null,
                    'status' => $row->available <= 0 ? 'out_of_stock' : 'low_stock',
                ];
            }),
            'meta' => [
                'total' => $rows->count(),
                'out_of_stock' => $rows->where('available', '<=', 0)->count(),
                'low_stock' => $rows->where('available', '>', 0)->count(),
                'default_threshold' => ReorderRule::DEFAULT_LOW_STOCK_THRESHOLD,
            ],
        ]);
    }

    /**
     * Generate automated replenishment recommendations.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $warehouseId = $request->query('warehouse_id');

        $rulesQuery = $this->inUserWarehouses(ReorderRule::where('organization_id', $org->id), $request)->where('is_active', true)->with(['warehouse', 'variant.product']);
        if ($warehouseId) {
            $rulesQuery->where('warehouse_id', $warehouseId);
        }
        $rules = $rulesQuery->get();

        $recommendations = [];

        foreach ($rules as $r) {
            $balance = $this->inUserWarehouses(InventoryBalance::where('organization_id', $org->id), $request)
                ->where('warehouse_id', $r->warehouse_id)
                ->where('product_variant_id', $r->product_variant_id)
                ->first();

            $onHand = $balance ? $balance->on_hand : 0;
            $reserved = $balance ? $balance->reserved : 0;
            $inTransit = $balance ? ($balance->in_transit ?? 0) : 0;

            // Effective Available Position = On-Hand + In-Transit - Reserved
            $effectivePosition = ($onHand + $inTransit) - $reserved;

            if ($effectivePosition <= $r->reorder_point) {
                $deficit = ($r->reorder_point + $r->safety_stock) - $effectivePosition;
                $suggestedQty = max($r->reorder_quantity, $deficit);

                $recommendations[] = [
                    'rule_id' => $r->id,
                    'warehouse_id' => $r->warehouse_id,
                    'warehouse_name' => $r->warehouse ? $r->warehouse->name : 'N/A',
                    'product_variant_id' => $r->product_variant_id,
                    'product_name' => $r->variant && $r->variant->product ? $r->variant->product->name : 'N/A',
                    'sku' => $r->variant ? $r->variant->sku : 'N/A',
                    'on_hand' => $onHand,
                    'reserved' => $reserved,
                    'in_transit' => $inTransit,
                    'effective_position' => $effectivePosition,
                    'reorder_point' => $r->reorder_point,
                    'safety_stock' => $r->safety_stock,
                    'suggested_quantity' => $suggestedQty,
                    'lead_time_days' => $r->lead_time_days,
                    'preferred_supplier' => $r->preferred_supplier ?: 'Default Supplier',
                    'urgency' => $effectivePosition <= 0 ? 'critical' : ($effectivePosition <= $r->safety_stock ? 'high' : 'medium'),
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => $recommendations,
            'count' => count($recommendations),
        ]);
    }

    /**
     * Demand forecast: how fast each item sells in each warehouse, how many days the stock lasts, the day it
     * runs out, and how much to order so it lasts through the supplier's lead time plus the cover period.
     *
     * The daily rate is the average of the units shipped from the warehouse over the last `days` days
     * (cancelled orders and lines left out). Items that did not sell in that period are not listed.
     */
    public function forecast(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'in:7,14,30,60,90'],
            'cover_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $coverDays = (int) ($validated['cover_days'] ?? 30);

        $sales = $this->inUserWarehouses(OrderItem::query()->from('order_items as i'), $request, 'i.allocated_warehouse_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.organization_id', $orgId)
            ->where('o.order_status', '!=', 'cancelled')
            ->where('i.status', '!=', 'cancelled')
            ->whereNotNull('i.allocated_warehouse_id')
            ->whereNotNull('i.product_variant_id')
            ->when($validated['warehouse_id'] ?? null, fn ($q, $id) => $q->where('i.allocated_warehouse_id', $id))
            ->whereRaw('COALESCE(o.placed_at, o.created_at) >= ?', [now()->subDays($days)])
            ->groupBy('i.allocated_warehouse_id', 'i.product_variant_id')
            ->get(['i.allocated_warehouse_id as warehouse_id', 'i.product_variant_id', DB::raw('SUM(i.quantity) as sold')]);

        $key = fn ($warehouseId, $variantId) => $warehouseId.'-'.$variantId;
        $balances = InventoryBalance::where('organization_id', $orgId)
            ->whereIn('product_variant_id', $sales->pluck('product_variant_id')->unique())
            ->get()
            ->keyBy(fn (InventoryBalance $b) => $key($b->warehouse_id, $b->product_variant_id));
        $rules = ReorderRule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereIn('product_variant_id', $sales->pluck('product_variant_id')->unique())
            ->get()
            ->keyBy(fn (ReorderRule $r) => $key($r->warehouse_id, $r->product_variant_id));
        $variants = ProductVariant::with('product')->whereIn('id', $sales->pluck('product_variant_id'))->get()->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $sales->pluck('warehouse_id'))->pluck('name', 'id');

        $rows = $sales->map(function ($row) use ($key, $balances, $rules, $variants, $warehouses, $days, $coverDays) {
            $balance = $balances->get($key($row->warehouse_id, $row->product_variant_id));
            $rule = $rules->get($key($row->warehouse_id, $row->product_variant_id));
            $variant = $variants->get($row->product_variant_id);

            $dailyRate = (int) $row->sold / $days;
            $available = (int) ($balance?->available ?? 0);
            $inTransit = (int) ($balance?->in_transit ?? 0);
            $leadTime = (int) ($rule?->lead_time_days ?? 7);
            $safetyStock = (int) ($rule?->safety_stock ?? 0);
            $daysOfCover = $available > 0 ? round($available / $dailyRate, 1) : 0.0;
            $needed = (int) ceil($dailyRate * ($leadTime + $coverDays)) + $safetyStock - $available - $inTransit;

            return [
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => $warehouses->get($row->warehouse_id),
                'product_variant_id' => (int) $row->product_variant_id,
                'product_name' => $variant?->product?->name,
                'variant' => $variant?->option_value,
                'sku' => $variant?->sku,
                'sold' => (int) $row->sold,
                'daily_rate' => round($dailyRate, 2),
                'available' => $available,
                'in_transit' => $inTransit,
                'days_of_cover' => $daysOfCover,
                'stockout_date' => now()->addDays((int) floor($daysOfCover))->toDateString(),
                'lead_time_days' => $leadTime,
                'suggested_quantity' => max(0, $needed),
                // Stock that runs out before a new order could arrive must be ordered now.
                'status' => match (true) {
                    $daysOfCover <= $leadTime => 'order_now',
                    $daysOfCover <= $leadTime + 14 => 'order_soon',
                    default => 'healthy',
                },
            ];
        })->sortBy('days_of_cover')->values();

        return response()->json([
            'status' => 'success',
            'data' => $rows,
            'meta' => [
                'days' => $days,
                'cover_days' => $coverDays,
                'order_now' => $rows->where('status', 'order_now')->count(),
                'order_soon' => $rows->where('status', 'order_soon')->count(),
            ],
        ]);
    }
}
