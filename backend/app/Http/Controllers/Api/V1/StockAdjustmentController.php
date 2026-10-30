<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List stock adjustments with status, reasons, and user audit.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = $this->inUserWarehouses(StockAdjustment::where('organization_id', $org->id), $request)
            ->with(['warehouse', 'location', 'variant.product', 'user', 'approvedBy']);
        $this->forStoreProducts($query, $request);

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('adjustment_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('variant.product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $this->perPage($request, 15);
        $adjustments = $query->latest('id')->paginate($perPage);

        $items = collect($adjustments->items())->map(function ($a) {
            return [
                'id' => $a->id,
                'adjustment_number' => $a->adjustment_number ?: ('ADJ-'.str_pad($a->id, 6, '0', STR_PAD_LEFT)),
                'warehouse_id' => $a->warehouse_id,
                'warehouse_name' => $a->warehouse ? $a->warehouse->name : 'N/A',
                'location' => $a->location ? $a->location->code : 'General Warehouse',
                'product_name' => $a->variant && $a->variant->product ? $a->variant->product->name : 'N/A',
                'sku' => $a->variant ? $a->variant->sku : 'N/A',
                'adjustment_type' => $a->adjustment_type,
                'quantity' => $a->quantity,
                'current_quantity' => $a->current_quantity,
                'new_quantity' => $a->new_quantity,
                'status' => $a->status,
                'reason' => $a->reason,
                'notes' => $a->notes,
                'lot_number' => $a->lot_number,
                'requested_by' => $a->user ? $a->user->name : 'System',
                'approved_by' => $a->approvedBy ? $a->approvedBy->name : null,
                'approved_at' => $a->approved_at ? $a->approved_at->format('Y-m-d H:i') : null,
                'created_at' => $a->created_at->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $adjustments->currentPage(),
                'last_page' => $adjustments->lastPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
            ],
        ]);
    }

    /**
     * Submit stock adjustment request.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'warehouse_id' => ['required', $this->accessibleWarehouse($request)],
            'product_id' => ['nullable', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'warehouse_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'adjustment_type' => ['required', 'in:add,subtract,recount'],
            'quantity' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'require_approval' => ['nullable', 'boolean'],
        ]);

        $variantId = $validated['product_variant_id'] ?? null;
        if (! $variantId && ! empty($validated['product_id'])) {
            $product = Product::where('organization_id', $org->id)->findOrFail($validated['product_id']);
            $variant = $product->variants()->firstOrFail();
            $variantId = $variant->id;
        }

        if (! $variantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product variant is required.',
            ], 422);
        }

        Warehouse::where('organization_id', $org->id)->findOrFail($validated['warehouse_id']);

        // Check if user is requesting approval or auto-posting
        $needsApproval = $validated['require_approval'] ?? false;

        if ($needsApproval) {
            $avail = $this->inventoryEngine->getAvailability(
                $org->id,
                $validated['warehouse_id'],
                $variantId,
                $validated['warehouse_location_id'] ?? null,
                $validated['lot_number'] ?? null
            );

            $currentQty = $avail['on_hand'];
            $newQty = match ($validated['adjustment_type']) {
                'add' => $currentQty + $validated['quantity'],
                'subtract' => max(0, $currentQty - $validated['quantity']),
                'recount' => $validated['quantity'],
            };

            $adjustment = StockAdjustment::create([
                'organization_id' => $org->id,
                'adjustment_number' => 'ADJ-'.strtoupper(uniqid()),
                'warehouse_id' => $validated['warehouse_id'],
                'warehouse_location_id' => $validated['warehouse_location_id'] ?? null,
                'product_variant_id' => $variantId,
                'lot_number' => $validated['lot_number'] ?? null,
                'adjustment_type' => $validated['adjustment_type'],
                'quantity' => $validated['quantity'],
                'current_quantity' => $currentQty,
                'new_quantity' => $newQty,
                'status' => 'pending_approval',
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment submitted for supervisor approval.',
                'data' => $adjustment->load(['warehouse', 'variant.product']),
            ], 201);
        }

        // Direct posting through authoritative InventoryEngine
        try {
            $adjustment = $this->inventoryEngine->adjustStock(
                organizationId: $org->id,
                warehouseId: $validated['warehouse_id'],
                productVariantId: $variantId,
                adjustmentType: $validated['adjustment_type'],
                quantity: $validated['quantity'],
                reason: $validated['reason'],
                notes: $validated['notes'] ?? null,
                userId: $request->user()->id,
                locationId: $validated['warehouse_location_id'] ?? null,
                lotNumber: $validated['lot_number'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment successfully posted to ledger.',
                'data' => $adjustment->load(['warehouse', 'variant.product']),
            ], 200);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Approve pending stock adjustment (enforces separation of duties).
     */
    public function approve(Request $request, int $adjustmentId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $adjustment = $this->inUserWarehouses(StockAdjustment::where('organization_id', $org->id), $request)->findOrFail($adjustmentId);

        if ($adjustment->status !== 'pending_approval') {
            return response()->json([
                'status' => 'error',
                'message' => "Adjustment is already {$adjustment->status}.",
            ], 422);
        }

        // Enforce separation of duties: cannot approve own adjustment
        if ($adjustment->user_id === $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Separation of duties violation: You cannot approve your own adjustment request.',
            ], 403);
        }

        // Execute through authoritative InventoryEngine
        $this->inventoryEngine->adjustStock(
            organizationId: $org->id,
            warehouseId: $adjustment->warehouse_id,
            productVariantId: $adjustment->product_variant_id,
            adjustmentType: $adjustment->adjustment_type,
            quantity: $adjustment->quantity,
            reason: "Approved: {$adjustment->reason}",
            notes: $adjustment->notes,
            userId: $request->user()->id,
            idempotencyKey: "adj-approval-{$adjustment->id}",
            locationId: $adjustment->warehouse_location_id,
            lotNumber: $adjustment->lot_number
        );

        $adjustment->update([
            'status' => 'posted',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Stock adjustment '{$adjustment->adjustment_number}' approved and posted.",
            'data' => $adjustment->fresh(['warehouse', 'variant.product', 'approvedBy']),
        ]);
    }

    /**
     * Reject pending stock adjustment.
     */
    public function reject(Request $request, int $adjustmentId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $adjustment = $this->inUserWarehouses(StockAdjustment::where('organization_id', $org->id), $request)->findOrFail($adjustmentId);

        if ($adjustment->status !== 'pending_approval') {
            return response()->json([
                'status' => 'error',
                'message' => "Adjustment is already {$adjustment->status}.",
            ], 422);
        }

        $adjustment->update([
            'status' => 'rejected',
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Stock adjustment '{$adjustment->adjustment_number}' rejected.",
            'data' => $adjustment->fresh(['warehouse', 'variant.product', 'approvedBy']),
        ]);
    }
}
