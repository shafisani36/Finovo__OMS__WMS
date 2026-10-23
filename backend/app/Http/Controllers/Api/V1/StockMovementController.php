<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List recent inventory movements with KPIs, search, filters, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        // With ?store_id= only movements of products sold in that store are listed and counted.
        $storeId = $request->integer('store_id');
        $inStore = fn ($q) => $q->when($storeId, fn ($q) => $q->whereHas('variant.skuMappings', fn ($m) => $m->where('store_id', $storeId)));

        $query = InventoryLedgerEntry::where('organization_id', $org->id)
            ->tap($inStore)
            ->with(['warehouse', 'variant.product', 'user']);

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('variant.product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    })
                    ->orWhereHas('warehouse', function ($wq) use ($search) {
                        $wq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by movement type
        if ($type = $request->query('type')) {
            // "transfer" covers both legs of a transfer between warehouses.
            $type === 'transfer'
                ? $query->whereIn('movement_type', ['transfer', 'transfer_in', 'transfer_out'])
                : $query->where('movement_type', $type);
        }

        // Filter by warehouse
        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        $movements = $query->latest('id')->paginate($this->perPage($request, 10));

        // Transform for UI table matching Screenshot 4
        $items = collect($movements->items())->map(function ($m) {
            return [
                'id' => $m->id,
                'reference' => $m->reference_number ?: ('MOV-'.str_pad($m->id, 6, '0', STR_PAD_LEFT)),
                'type' => ucfirst(str_replace('_', ' ', $m->movement_type)),
                'product' => $m->variant && $m->variant->product ? $m->variant->product->name : 'N/A',
                'sku' => $m->variant ? $m->variant->sku : 'N/A',
                'warehouse' => $m->warehouse ? $m->warehouse->name : 'N/A',
                'quantity' => abs($m->quantity),
                'direction' => $m->quantity >= 0 ? 'in' : 'out',
                'before' => $m->balance_before,
                'after' => $m->balance_after,
                'user' => $m->user ? $m->user->name : 'System',
                'date' => $m->created_at ? $m->created_at->format('d M Y h:i A') : '',
                'status' => 'Completed',
                'reason' => $m->reason,
            ];
        });

        // KPI Counts matching Screenshot 4
        $totalWarehouses = Warehouse::where('organization_id', $org->id)->where('is_active', true)->count();
        $totalProducts = Product::where('organization_id', $org->id)
            ->where('is_active', true)
            ->when($storeId, fn ($q) => $q->whereHas('variants.skuMappings', fn ($m) => $m->where('store_id', $storeId)))
            ->count();
        $movementsThisMonth = InventoryLedgerEntry::where('organization_id', $org->id)
            ->tap($inStore)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
            'kpis' => [
                'total_warehouses' => $totalWarehouses,
                'total_products' => $totalProducts,
                'movements_this_month' => $movementsThisMonth,
            ],
        ]);
    }

    /**
     * Transfer stock between warehouses.
     */
    public function transfer(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'from_warehouse_id' => ['required', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::where('organization_id', $org->id)->findOrFail($validated['product_id']);
        $variant = $product->variants()->first();

        if (! $variant) {
            $variant = $product->variants()->create([
                'organization_id' => $org->id,
                'sku' => $product->sku,
                'price' => 0.00,
            ]);
        }

        try {
            $transfer = $this->inventoryEngine->transferStock(
                organizationId: $org->id,
                fromWarehouseId: (int) $validated['from_warehouse_id'],
                toWarehouseId: (int) $validated['to_warehouse_id'],
                productVariantId: $variant->id,
                quantity: (int) $validated['quantity'],
                notes: $validated['notes'] ?? null,
                userId: $request->user()->id
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Stock transferred successfully.',
                'data' => $transfer,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Adjust stock (add, subtract, recount).
     */
    public function adjust(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'adjustment_type' => ['required', 'string', 'in:add,subtract,recount'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::where('organization_id', $org->id)->findOrFail($validated['product_id']);
        $variant = $product->variants()->first();

        if (! $variant) {
            $variant = $product->variants()->create([
                'organization_id' => $org->id,
                'sku' => $product->sku,
                'price' => 0.00,
            ]);
        }

        try {
            $adjustment = $this->inventoryEngine->adjustStock(
                organizationId: $org->id,
                warehouseId: (int) $validated['warehouse_id'],
                productVariantId: $variant->id,
                adjustmentType: $validated['adjustment_type'],
                quantity: (int) $validated['quantity'],
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                userId: $request->user()->id
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjusted successfully.',
                'data' => $adjustment,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get available stock for a product across warehouses.
     */
    public function availability(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $productId = $request->query('product_id');
        $warehouseId = $request->query('warehouse_id');

        if (! $productId) {
            return response()->json(['available' => 0, 'on_hand' => 0, 'reserved' => 0]);
        }

        $product = Product::where('organization_id', $org->id)->find($productId);
        if (! $product) {
            return response()->json(['available' => 0, 'on_hand' => 0, 'reserved' => 0]);
        }

        $variant = $product->variants()->first();
        if (! $variant) {
            return response()->json(['available' => 0, 'on_hand' => 0, 'reserved' => 0]);
        }

        if ($warehouseId) {
            $avail = $this->inventoryEngine->getAvailability($org->id, (int) $warehouseId, $variant->id);

            return response()->json($avail);
        }

        // Sum across all warehouses
        $balances = InventoryBalance::where('organization_id', $org->id)
            ->where('product_variant_id', $variant->id)
            ->get();

        return response()->json([
            'available' => (int) $balances->sum('available'),
            'on_hand' => (int) $balances->sum('on_hand'),
            'reserved' => (int) $balances->sum('reserved'),
            'damaged' => (int) $balances->sum('damaged'),
        ]);
    }
}
