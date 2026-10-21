<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReplenishmentController extends Controller
{
    /**
     * List configured reorder rules.
     */
    public function rules(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $rules = ReorderRule::where('organization_id', $org->id)
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
            'warehouse_id' => ['required', 'exists:warehouses,id'],
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

        $lowBalances = InventoryBalance::query()
            ->from('inventory_balances as b')
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
        $neverStocked = ReorderRule::where('organization_id', $orgId)
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

        $rulesQuery = ReorderRule::where('organization_id', $org->id)->where('is_active', true)->with(['warehouse', 'variant.product']);
        if ($warehouseId) {
            $rulesQuery->where('warehouse_id', $warehouseId);
        }
        $rules = $rulesQuery->get();

        $recommendations = [];

        foreach ($rules as $r) {
            $balance = InventoryBalance::where('organization_id', $org->id)
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
}
