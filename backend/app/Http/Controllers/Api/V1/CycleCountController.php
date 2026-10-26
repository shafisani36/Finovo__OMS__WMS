<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CycleCount;
use App\Models\InventoryBalance;
use App\Models\InventoryLocationBalance;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CycleCountController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List cycle counts with stats and status.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = $this->inUserWarehouses(CycleCount::where('organization_id', $org->id), $request)
            ->with(['warehouse', 'createdBy', 'approvedBy', 'items.variant.product', 'items.location']);

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 15);
        $counts = $query->latest('id')->paginate($perPage);

        $items = collect($counts->items())->map(function ($c) {
            $totalItems = $c->items->count();
            $countedItems = $c->items->whereNotNull('counted_quantity')->count();
            $varianceCount = $c->items->where('variance_quantity', '!=', 0)->count();

            return [
                'id' => $c->id,
                'count_number' => $c->count_number,
                'title' => $c->title,
                'warehouse_id' => $c->warehouse_id,
                'warehouse_name' => $c->warehouse ? $c->warehouse->name : 'N/A',
                'status' => $c->status,
                'scope_type' => $c->scope_type,
                'scope_value' => $c->scope_value,
                'total_items' => $totalItems,
                'counted_items' => $countedItems,
                'variance_count' => $varianceCount,
                'notes' => $c->notes,
                'created_by' => $c->createdBy ? $c->createdBy->name : 'System',
                'approved_by' => $c->approvedBy ? $c->approvedBy->name : null,
                'posted_at' => $c->posted_at ? $c->posted_at->format('Y-m-d H:i') : null,
                'created_at' => $c->created_at->format('Y-m-d H:i'),
                'items' => $c->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'product_name' => $item->variant && $item->variant->product ? $item->variant->product->name : 'N/A',
                        'sku' => $item->variant ? $item->variant->sku : 'N/A',
                        'location_code' => $item->location ? $item->location->code : 'General',
                        'lot_number' => $item->lot_number,
                        'snapshot_quantity' => $item->snapshot_quantity,
                        'counted_quantity' => $item->counted_quantity,
                        'variance_quantity' => $item->variance_quantity,
                        'status' => $item->status,
                        'notes' => $item->notes,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $counts->currentPage(),
                'last_page' => $counts->lastPage(),
                'per_page' => $counts->perPage(),
                'total' => $counts->total(),
            ],
        ]);
    }

    /**
     * Create cycle count session with baseline snapshot freeze.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'warehouse_id' => ['required', $this->accessibleWarehouse($request)],
            'title' => ['required', 'string', 'max:150'],
            'scope_type' => ['required', 'in:full,zone,location'],
            'scope_value' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $warehouse = Warehouse::where('organization_id', $org->id)->findOrFail($validated['warehouse_id']);

        $countNumber = 'CYC-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));

        $count = DB::transaction(function () use ($org, $warehouse, $validated, $countNumber, $request) {
            $cycle = CycleCount::create([
                'organization_id' => $org->id,
                'warehouse_id' => $warehouse->id,
                'count_number' => $countNumber,
                'title' => $validated['title'],
                'scope_type' => $validated['scope_type'],
                'scope_value' => $validated['scope_value'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'in_progress',
                'created_by_user_id' => $request->user()->id,
            ]);

            // Capture snapshot of location balances
            $locBalances = $this->inUserWarehouses(InventoryLocationBalance::where('organization_id', $org->id), $request)
                ->where('warehouse_id', $warehouse->id)
                ->with(['location', 'variant'])
                ->get();

            if ($locBalances->isNotEmpty()) {
                foreach ($locBalances as $lb) {
                    $cycle->items()->create([
                        'product_variant_id' => $lb->product_variant_id,
                        'warehouse_location_id' => $lb->warehouse_location_id,
                        'lot_number' => $lb->lot_number,
                        'snapshot_quantity' => $lb->quantity,
                        'counted_quantity' => null,
                        'variance_quantity' => 0,
                        'status' => 'pending',
                    ]);
                }
            } else {
                // Fallback to warehouse-level balances if no physical locations stocked yet
                $whBalances = $this->inUserWarehouses(InventoryBalance::where('organization_id', $org->id), $request)
                    ->where('warehouse_id', $warehouse->id)
                    ->get();

                foreach ($whBalances as $wb) {
                    $cycle->items()->create([
                        'product_variant_id' => $wb->product_variant_id,
                        'warehouse_location_id' => null,
                        'lot_number' => null,
                        'snapshot_quantity' => $wb->on_hand,
                        'counted_quantity' => null,
                        'variance_quantity' => 0,
                        'status' => 'pending',
                    ]);
                }
            }

            return $cycle->load('items.variant.product');
        });

        return response()->json([
            'status' => 'success',
            'message' => "Cycle count session '{$countNumber}' created with baseline snapshot.",
            'data' => $count,
        ], 201);
    }

    /**
     * Record physical counted quantities.
     */
    public function recordCounts(Request $request, int $countId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $cycle = $this->inUserWarehouses(CycleCount::where('organization_id', $org->id), $request)
            ->with('items')
            ->findOrFail($countId);

        $validated = $request->validate([
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.item_id' => ['required', 'exists:cycle_count_items,id'],
            'counts.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'counts.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($cycle, $validated) {
            foreach ($validated['counts'] as $c) {
                $item = $cycle->items()->findOrFail($c['item_id']);
                $countedQty = (int) $c['counted_quantity'];
                $variance = $countedQty - $item->snapshot_quantity;

                $item->update([
                    'counted_quantity' => $countedQty,
                    'variance_quantity' => $variance,
                    'status' => 'counted',
                    'notes' => $c['notes'] ?? $item->notes,
                ]);
            }

            $cycle->update(['status' => 'review']);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Counts recorded and variances computed.',
            'data' => $cycle->fresh('items.variant.product'),
        ]);
    }

    /**
     * Approve and post variances to inventory ledger and balances.
     */
    public function approveAndPost(Request $request, int $countId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $cycle = $this->inUserWarehouses(CycleCount::where('organization_id', $org->id), $request)
            ->with('items')
            ->findOrFail($countId);

        if ($cycle->status === 'posted') {
            return response()->json([
                'status' => 'error',
                'message' => 'Cycle count has already been posted.',
            ], 422);
        }

        DB::transaction(function () use ($cycle, $org, $request) {
            foreach ($cycle->items as $item) {
                if ($item->counted_quantity === null) {
                    continue;
                }

                $variance = $item->variance_quantity;
                if ($variance === 0) {
                    $item->update(['status' => 'approved']);

                    continue;
                }

                $adjustmentType = $variance > 0 ? 'add' : 'subtract';
                $absQty = abs($variance);

                // Post variance movement through authoritative InventoryEngine
                $this->inventoryEngine->adjustStock(
                    organizationId: $org->id,
                    warehouseId: $cycle->warehouse_id,
                    productVariantId: $item->product_variant_id,
                    adjustmentType: $adjustmentType,
                    quantity: $absQty,
                    reason: "Cycle count variance: {$cycle->count_number}",
                    notes: "Counted {$item->counted_quantity} vs Snapshot {$item->snapshot_quantity}",
                    userId: $request->user()->id,
                    idempotencyKey: "cc-{$cycle->id}-item-{$item->id}",
                    locationId: $item->warehouse_location_id,
                    lotNumber: $item->lot_number
                );

                $item->update(['status' => 'approved']);
            }

            $cycle->update([
                'status' => 'posted',
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
                'posted_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => "Cycle count '{$cycle->count_number}' approved and variances posted to ledger.",
            'data' => $cycle->fresh('items.variant.product'),
        ]);
    }
}
