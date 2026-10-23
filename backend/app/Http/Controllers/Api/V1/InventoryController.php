<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List inventory balances with warehouse and SKU breakdown, search, filters, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = InventoryBalance::where('organization_id', $org->id)
            ->with(['warehouse', 'variant.product']);

        // Search by SKU, product name, or barcode
        if ($search = $request->query('search')) {
            $query->whereHas('variant', function ($vq) use ($search) {
                $vq->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('category', 'like', "%{$search}%");
                    });
            });
        }

        // Warehouse Filter
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Store filter: stock of products sold in the store being managed
        if ($storeId = $request->query('store_id')) {
            $query->whereHas('variant.skuMappings', fn ($q) => $q->where('store_id', $storeId));
        }

        // Stock Status Filter (in_stock, low_stock, out_of_stock)
        if ($status = $request->query('status')) {
            if ($status === 'in_stock') {
                $query->where('available', '>', 0);
            } elseif ($status === 'out_of_stock') {
                $query->where('available', '<=', 0);
            } elseif ($status === 'low_stock') {
                $query->where('available', '>', 0)->where('available', '<=', 10);
            }
        }

        $perPage = (int) $request->query('per_page', 15);
        $balances = $query->orderBy('warehouse_id')->orderBy('available', 'desc')->paginate($perPage);

        // Fetch location breakdowns for displayed product variants & warehouses
        $variantIds = collect($balances->items())->pluck('product_variant_id')->unique()->toArray();
        $warehouseIds = collect($balances->items())->pluck('warehouse_id')->unique()->toArray();

        $locationBalances = InventoryLocationBalance::where('organization_id', $org->id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_variant_id', $variantIds)
            ->with('location')
            ->get()
            ->groupBy(function ($item) {
                return "{$item->warehouse_id}-{$item->product_variant_id}";
            });

        $items = collect($balances->items())->map(function ($b) use ($locationBalances) {
            $key = "{$b->warehouse_id}-{$b->product_variant_id}";
            $locs = $locationBalances->get($key, collect())->map(function ($l) {
                return [
                    'location_id' => $l->warehouse_location_id,
                    'location_code' => $l->location ? $l->location->code : 'Unknown',
                    'location_type' => $l->location ? $l->location->location_type : 'bin',
                    'lot_number' => $l->lot_number,
                    'expiry_date' => $l->expiry_date ? $l->expiry_date->format('Y-m-d') : null,
                    'condition' => $l->condition,
                    'quantity' => $l->quantity,
                    'reserved' => $l->reserved,
                    'available' => max(0, $l->quantity - $l->reserved),
                ];
            });

            return [
                'id' => $b->id,
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $b->warehouse ? $b->warehouse->name : 'N/A',
                'warehouse_code' => $b->warehouse ? $b->warehouse->code : 'N/A',
                'product_id' => $b->variant && $b->variant->product ? $b->variant->product->id : null,
                'product_variant_id' => $b->product_variant_id,
                'product_name' => $b->variant && $b->variant->product ? $b->variant->product->name : 'N/A',
                'sku' => $b->variant ? $b->variant->sku : 'N/A',
                'barcode' => $b->variant ? $b->variant->barcode : null,
                'category' => $b->variant && $b->variant->product ? $b->variant->product->category : 'General',
                'base_uom' => $b->variant && $b->variant->product ? ($b->variant->product->base_uom ?? 'pcs') : 'pcs',
                'tracking_type' => $b->variant && $b->variant->product ? ($b->variant->product->tracking_type ?? 'untracked') : 'untracked',
                'price' => $b->variant ? (float) $b->variant->price : 0.0,
                'cost_price' => $b->variant ? (float) $b->variant->cost_price : 0.0,
                'on_hand' => $b->on_hand,
                'reserved' => $b->reserved,
                'available' => $b->available,
                'damaged' => $b->damaged,
                'quarantined' => $b->quarantined ?? 0,
                'in_transit' => $b->in_transit ?? 0,
                'picked' => $b->picked ?? 0,
                'locations' => $locs,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $balances->currentPage(),
                'last_page' => $balances->lastPage(),
                'per_page' => $balances->perPage(),
                'total' => $balances->total(),
            ],
        ]);
    }

    /**
     * Authoritative backend-calculated inventory KPIs.
     */
    public function kpis(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $warehouseId = $request->query('warehouse_id');

        // With ?store_id= the figures cover only products sold in that store, like the stock table.
        $storeId = $request->integer('store_id');

        $query = InventoryBalance::where('organization_id', $org->id)
            ->when($storeId, fn ($q) => $q->whereHas('variant.skuMappings', fn ($m) => $m->where('store_id', $storeId)));
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $balances = $query->with('variant')->get();

        $totalSkus = ProductVariant::where('organization_id', $org->id)
            ->when($storeId, fn ($q) => $q->whereHas('skuMappings', fn ($m) => $m->where('store_id', $storeId)))
            ->count();
        $totalOnHand = $balances->sum('on_hand');
        $totalReserved = $balances->sum('reserved');
        $totalAvailable = $balances->sum('available');
        $totalQuarantined = $balances->sum('quarantined');
        $totalDamaged = $balances->sum('damaged');
        $totalInTransit = $balances->sum('in_transit');

        $lowStockCount = 0;
        $outOfStockCount = 0;
        $totalValuation = 0.0;

        foreach ($balances as $b) {
            if ($b->available <= 0) {
                $outOfStockCount++;
            } elseif ($b->available <= 10) {
                $lowStockCount++;
            }
            if ($b->variant) {
                $totalValuation += $b->on_hand * (float) $b->variant->cost_price;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_skus' => $totalSkus,
                'total_on_hand' => $totalOnHand,
                'total_reserved' => $totalReserved,
                'total_available' => $totalAvailable,
                'total_quarantined' => $totalQuarantined,
                'total_damaged' => $totalDamaged,
                'total_in_transit' => $totalInTransit,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'total_valuation' => round($totalValuation, 2),
            ],
        ]);
    }

    /**
     * Product/SKU deep inventory detail across all warehouses and storage locations.
     */
    public function skuDetail(Request $request, int $variantId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $variant = ProductVariant::where('organization_id', $org->id)
            ->with(['product', 'balances.warehouse', 'locationBalances.location'])
            ->findOrFail($variantId);

        $balances = $variant->balances->map(function ($b) {
            return [
                'warehouse_id' => $b->warehouse_id,
                'warehouse_name' => $b->warehouse ? $b->warehouse->name : 'N/A',
                'warehouse_code' => $b->warehouse ? $b->warehouse->code : 'N/A',
                'on_hand' => $b->on_hand,
                'reserved' => $b->reserved,
                'available' => $b->available,
                'damaged' => $b->damaged,
                'quarantined' => $b->quarantined ?? 0,
                'in_transit' => $b->in_transit ?? 0,
            ];
        });

        $locations = $variant->locationBalances->map(function ($l) {
            return [
                'warehouse_id' => $l->warehouse_id,
                'location_id' => $l->warehouse_location_id,
                'location_code' => $l->location ? $l->location->code : 'Unknown',
                'location_type' => $l->location ? $l->location->location_type : 'bin',
                'lot_number' => $l->lot_number,
                'expiry_date' => $l->expiry_date ? $l->expiry_date->format('Y-m-d') : null,
                'condition' => $l->condition,
                'quantity' => $l->quantity,
                'reserved' => $l->reserved,
                'available' => max(0, $l->quantity - $l->reserved),
            ];
        });

        $recentMovements = InventoryLedgerEntry::where('organization_id', $org->id)
            ->where('product_variant_id', $variantId)
            ->with(['warehouse', 'location', 'user'])
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'reference' => $m->reference_number ?: ('MOV-'.str_pad($m->id, 6, '0', STR_PAD_LEFT)),
                    'type' => ucfirst(str_replace('_', ' ', $m->movement_type)),
                    'warehouse' => $m->warehouse ? $m->warehouse->name : 'N/A',
                    'location' => $m->location ? $m->location->code : 'General',
                    'quantity' => $m->quantity,
                    'before' => $m->balance_before,
                    'after' => $m->balance_after,
                    'reason' => $m->reason,
                    'user' => $m->user ? $m->user->name : 'System',
                    'date' => $m->created_at->format('Y-m-d H:i'),
                ];
            });

        $reservations = InventoryReservation::where('organization_id', $org->id)
            ->where('product_variant_id', $variantId)
            ->where('status', 'active')
            ->with('warehouse')
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'order_id' => $r->order_id,
                    'warehouse_name' => $r->warehouse ? $r->warehouse->name : 'N/A',
                    'quantity' => $r->quantity,
                    'status' => $r->status,
                    'created_at' => $r->created_at->format('Y-m-d H:i'),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'variant_id' => $variant->id,
                'product_id' => $variant->product->id,
                'product_name' => $variant->product->name,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'category' => $variant->product->category,
                'base_uom' => $variant->product->base_uom ?? 'pcs',
                'tracking_type' => $variant->product->tracking_type ?? 'untracked',
                'price' => (float) $variant->price,
                'cost_price' => (float) $variant->cost_price,
                'total_on_hand' => $variant->balances->sum('on_hand'),
                'total_reserved' => $variant->balances->sum('reserved'),
                'total_available' => $variant->balances->sum('available'),
                'warehouse_balances' => $balances,
                'location_balances' => $locations,
                'recent_movements' => $recentMovements,
                'active_reservations' => $reservations,
            ],
        ]);
    }

    /**
     * Ledger vs Materialized Balance Reconciliation.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $warehouseId = $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null;

        $result = $this->inventoryEngine->reconcileLedgerWithBalances($org->id, $warehouseId);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
