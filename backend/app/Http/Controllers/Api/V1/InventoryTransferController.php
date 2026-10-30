<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\AccessControl;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryTransferController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * List inventory transfers.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $ids = app(AccessControl::class)->warehouseIds($request->user());
        $query = InventoryTransfer::where('organization_id', $org->id)
            ->when($ids !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids)))
            ->with(['fromWarehouse', 'toWarehouse', 'fromLocation', 'toLocation', 'variant.product', 'user', 'receivedBy']);
        $this->forStoreProducts($query, $request);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', "%{$search}%")
                    ->orWhereHas('variant.product', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $this->perPage($request, 15);
        $transfers = $query->latest('id')->paginate($perPage);

        $items = collect($transfers->items())->map(function ($t) {
            return [
                'id' => $t->id,
                'transfer_number' => $t->transfer_number,
                'product_name' => $t->variant && $t->variant->product ? $t->variant->product->name : 'N/A',
                'sku' => $t->variant ? $t->variant->sku : 'N/A',
                'from_warehouse_id' => $t->from_warehouse_id,
                'from_warehouse' => $t->fromWarehouse ? $t->fromWarehouse->name : 'N/A',
                'from_location' => $t->fromLocation ? $t->fromLocation->code : 'General',
                'to_warehouse_id' => $t->to_warehouse_id,
                'to_warehouse' => $t->toWarehouse ? $t->toWarehouse->name : 'N/A',
                'to_location' => $t->toLocation ? $t->toLocation->code : 'General',
                'quantity' => $t->quantity,
                'received_quantity' => $t->received_quantity,
                'discrepancy_quantity' => $t->discrepancy_quantity,
                'discrepancy_reason' => $t->discrepancy_reason,
                'lot_number' => $t->lot_number,
                'status' => $t->status,
                'notes' => $t->notes,
                'dispatched_at' => $t->dispatched_at ? $t->dispatched_at->format('Y-m-d H:i') : null,
                'received_at' => $t->received_at ? $t->received_at->format('Y-m-d H:i') : null,
                'created_by' => $t->user ? $t->user->name : 'System',
                'received_by' => $t->receivedBy ? $t->receivedBy->name : null,
                'created_at' => $t->created_at->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
            ],
        ]);
    }

    /**
     * Create and optionally dispatch transfer.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'from_warehouse_id' => ['required', $this->accessibleWarehouse($request)],
            'to_warehouse_id' => ['required', $this->accessibleWarehouse($request), 'different:from_warehouse_id'],
            'product_id' => ['nullable', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'from_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'to_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'auto_dispatch' => ['nullable', 'boolean'],
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

        // Authorize warehouses
        Warehouse::where('organization_id', $org->id)->findOrFail($validated['from_warehouse_id']);
        Warehouse::where('organization_id', $org->id)->findOrFail($validated['to_warehouse_id']);

        // Check if caller requests two-phase in-transit dispatch vs instant transfer
        if ($request->boolean('in_transit_only', false)) {
            $transfer = $this->inventoryEngine->dispatchTransfer(
                organizationId: $org->id,
                fromWarehouseId: $validated['from_warehouse_id'],
                toWarehouseId: $validated['to_warehouse_id'],
                productVariantId: $variantId,
                quantity: $validated['quantity'],
                fromLocationId: $validated['from_location_id'] ?? null,
                toLocationId: $validated['to_location_id'] ?? null,
                lotNumber: $validated['lot_number'] ?? null,
                notes: $validated['notes'] ?? null,
                userId: $request->user()->id
            );

            return response()->json([
                'status' => 'success',
                'message' => "Transfer '{$transfer->transfer_number}' dispatched and in-transit.",
                'data' => $transfer->load(['fromWarehouse', 'toWarehouse', 'variant.product']),
            ], 201);
        }

        $transfer = $this->inventoryEngine->transferStock(
            organizationId: $org->id,
            fromWarehouseId: $validated['from_warehouse_id'],
            toWarehouseId: $validated['to_warehouse_id'],
            productVariantId: $variantId,
            quantity: $validated['quantity'],
            notes: $validated['notes'] ?? null,
            userId: $request->user()->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Stock transferred successfully.',
            'data' => $transfer->load(['fromWarehouse', 'toWarehouse', 'variant.product']),
        ], 200);
    }

    /**
     * Receive in-transit transfer at destination.
     */
    public function receive(Request $request, int $transferId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $transfer = InventoryTransfer::where('organization_id', $org->id)->findOrFail($transferId);
        // Received by staff of the destination warehouse.
        abort_unless(app(AccessControl::class)->canAccessWarehouse($request->user(), (int) $transfer->to_warehouse_id), 403, 'You are not assigned to the receiving warehouse.');

        $validated = $request->validate([
            'received_quantity' => ['required', 'integer', 'min:0', "max:{$transfer->quantity}"],
            'destination_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'discrepancy_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $updatedTransfer = $this->inventoryEngine->receiveTransfer(
            transferId: $transfer->id,
            receivedQuantity: $validated['received_quantity'],
            destinationLocationId: $validated['destination_location_id'] ?? null,
            userId: $request->user()->id,
            discrepancyReason: $validated['discrepancy_reason'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => "Transfer '{$updatedTransfer->transfer_number}' completed and received.",
            'data' => $updatedTransfer->fresh(['fromWarehouse', 'toWarehouse', 'variant.product']),
        ]);
    }
}
