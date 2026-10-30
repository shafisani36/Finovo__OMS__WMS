<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryReceipt;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\InventoryEngine;
use App\Services\SlottingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReceiptController extends Controller
{
    public function __construct(
        protected InventoryEngine $inventoryEngine,
        protected SlottingService $slotting,
    ) {}

    /**
     * List inventory receipts with filtering and search.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = $this->inUserWarehouses(InventoryReceipt::where('organization_id', $org->id), $request)
            ->with(['warehouse', 'receivingLocation', 'receivedBy', 'items.variant.product']);
        $this->forStoreProducts($query, $request, 'items.variant');

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('purchase_order_number', 'like', "%{$search}%");
            });
        }

        $perPage = $this->perPage($request, 15);
        $receipts = $query->latest('id')->paginate($perPage);

        $items = collect($receipts->items())->map(function ($r) {
            $totalExpected = $r->items->sum('expected_quantity');
            $totalReceived = $r->items->sum('received_quantity');

            return [
                'id' => $r->id,
                'receipt_number' => $r->receipt_number,
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse ? $r->warehouse->name : 'N/A',
                'receiving_location' => $r->receivingLocation ? $r->receivingLocation->code : 'Dock',
                'supplier_name' => $r->supplier_name,
                'purchase_order_number' => $r->purchase_order_number,
                'status' => $r->status,
                'total_expected' => $totalExpected,
                'total_received' => $totalReceived,
                'items_count' => $r->items->count(),
                'received_by' => $r->receivedBy ? $r->receivedBy->name : null,
                'received_at' => $r->received_at ? $r->received_at->format('Y-m-d H:i') : null,
                'created_at' => $r->created_at->format('Y-m-d H:i'),
                'items' => $r->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'product_name' => $item->variant && $item->variant->product ? $item->variant->product->name : 'N/A',
                        'sku' => $item->variant ? $item->variant->sku : 'N/A',
                        'expected_quantity' => $item->expected_quantity,
                        'received_quantity' => $item->received_quantity,
                        'lot_number' => $item->lot_number,
                        'expiry_date' => $item->expiry_date ? $item->expiry_date->format('Y-m-d') : null,
                        'condition' => $item->condition,
                        'status' => $item->status,
                    ];
                }),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    /**
     * Create receipt with expected item lines.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'warehouse_id' => ['required', $this->accessibleWarehouse($request)],
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'purchase_order_number' => ['nullable', 'string', 'max:100'],
            'receiving_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.expected_quantity' => ['required', 'integer', 'min:1'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::where('organization_id', $org->id)->findOrFail($validated['warehouse_id']);

        $receiptNumber = 'REC-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));

        $receipt = DB::transaction(function () use ($org, $warehouse, $validated, $receiptNumber, $request) {
            $rec = InventoryReceipt::create([
                'organization_id' => $org->id,
                'warehouse_id' => $warehouse->id,
                'receipt_number' => $receiptNumber,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'purchase_order_number' => $validated['purchase_order_number'] ?? null,
                'receiving_location_id' => $validated['receiving_location_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'draft',
                'received_by_user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                $rec->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'expected_quantity' => $item['expected_quantity'],
                    'received_quantity' => 0,
                    'lot_number' => $item['lot_number'] ?? null,
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'unit_cost' => $item['unit_cost'] ?? 0.00,
                    'status' => 'pending',
                ]);
            }

            return $rec->load('items.variant.product');
        });

        return response()->json([
            'status' => 'success',
            'message' => "Inbound receipt '{$receiptNumber}' created.",
            'data' => $receipt,
        ], 201);
    }

    /**
     * Confirm receipt: posts received quantities into InventoryEngine.
     */
    public function confirm(Request $request, int $receiptId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $receipt = $this->inUserWarehouses(InventoryReceipt::where('organization_id', $org->id), $request)
            ->with('items')
            ->findOrFail($receiptId);

        if ($receipt->status === 'completed' || $receipt->status === 'received') {
            return response()->json([
                'status' => 'error',
                'message' => 'Receipt has already been confirmed.',
            ], 422);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'exists:inventory_receipt_items,id'],
            'items.*.received_quantity' => ['required', 'integer', 'min:1'],
            'items.*.location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'items.*.lot_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.condition' => ['nullable', 'string', 'in:available,quarantined,damaged'],
        ]);

        DB::transaction(function () use ($receipt, $validated, $request) {
            foreach ($validated['items'] as $itemInput) {
                $item = $receipt->items()->findOrFail($itemInput['item_id']);
                $receivedQty = (int) $itemInput['received_quantity'];
                $locId = $itemInput['location_id'] ?? $receipt->receiving_location_id;
                if (! $locId) {
                    $dockLoc = WarehouseLocation::firstOrCreate(
                        [
                            'organization_id' => $receipt->organization_id,
                            'warehouse_id' => $receipt->warehouse_id,
                            'code' => 'RECEIVING-DOCK',
                        ],
                        [
                            'location_type' => 'zone',
                            'putaway_eligible' => true,
                            'picking_eligible' => true,
                            'is_active' => true,
                            'sort_order' => 0,
                        ]
                    );
                    $locId = $dockLoc->id;
                }
                $lotNumber = $itemInput['lot_number'] ?? $item->lot_number;
                $expiryDate = $itemInput['expiry_date'] ?? $item->expiry_date;
                $condition = $itemInput['condition'] ?? 'available';

                $this->inventoryEngine->receiveStock(
                    organizationId: $receipt->organization_id,
                    warehouseId: $receipt->warehouse_id,
                    productVariantId: $item->product_variant_id,
                    quantity: $receivedQty,
                    locationId: $locId,
                    lotNumber: $lotNumber,
                    expiryDate: $expiryDate,
                    condition: $condition,
                    referenceDoc: $receipt->receipt_number,
                    userId: $request->user()->id,
                    idempotencyKey: "receipt-{$receipt->id}-item-{$item->id}"
                );

                $item->update([
                    'received_quantity' => $receivedQty,
                    'location_id' => $locId,
                    'lot_number' => $lotNumber,
                    'expiry_date' => $expiryDate,
                    'condition' => $condition,
                    'status' => 'received',
                ]);
            }

            $receipt->update([
                'status' => 'received',
                'received_at' => now(),
                'received_by_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => "Receipt '{$receipt->receipt_number}' successfully confirmed into inventory.",
            'data' => $receipt->fresh('items.variant.product'),
        ]);
    }

    /**
     * Putaway: Move received item from dock to final destination storage bin.
     */
    public function putaway(Request $request, int $receiptId): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $receipt = $this->inUserWarehouses(InventoryReceipt::where('organization_id', $org->id), $request)
            ->with('items')
            ->findOrFail($receiptId);

        $validated = $request->validate([
            'item_id' => ['required', 'exists:inventory_receipt_items,id'],
            'destination_location_id' => ['nullable', 'exists:warehouse_locations,id'],
            'destination_location_code' => ['nullable', 'string', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $destLocId = $validated['destination_location_id'] ?? null;
        if (! $destLocId && ! empty($validated['destination_location_code'])) {
            $destLoc = WarehouseLocation::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'code' => trim($validated['destination_location_code']),
                ],
                [
                    'location_type' => 'bin',
                    'putaway_eligible' => true,
                    'picking_eligible' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );
            $destLocId = $destLoc->id;
        }

        $item = $receipt->items()->findOrFail($validated['item_id']);

        // Without a destination, the units go to the suggested bin (same stock first, then the right area for
        // the product's ABC class).
        if (! $destLocId && $item->product_variant_id) {
            $destLocId = $this->slotting->suggestPutaway(
                Warehouse::findOrFail($receipt->warehouse_id),
                (int) $item->product_variant_id,
                (int) $validated['quantity'],
                $item->lot_number,
            )['location']['id'] ?? null;
        }

        if (! $destLocId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Choose the bin to put the units in; no putaway bin has room for them.',
            ], 422);
        }

        $fromLocId = $item->location_id ?: $receipt->receiving_location_id;

        if (! $fromLocId) {
            $dockLoc = WarehouseLocation::firstOrCreate(
                [
                    'organization_id' => $org->id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'code' => 'RECEIVING-DOCK',
                ],
                [
                    'location_type' => 'zone',
                    'putaway_eligible' => true,
                    'picking_eligible' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );
            $fromLocId = $dockLoc->id;
        }

        $this->inventoryEngine->putawayStock(
            organizationId: $receipt->organization_id,
            warehouseId: $receipt->warehouse_id,
            productVariantId: $item->product_variant_id,
            fromLocationId: $fromLocId,
            toLocationId: $destLocId,
            quantity: $validated['quantity'],
            lotNumber: $item->lot_number,
            userId: $request->user()->id
        );

        $item->update([
            'destination_location_id' => $destLocId,
            'status' => 'putaway_completed',
        ]);

        // Check if all items putaway
        if ($receipt->items()->where('status', '!=', 'putaway_completed')->count() === 0) {
            $receipt->update(['status' => 'completed']);
        } else {
            $receipt->update(['status' => 'putaway_in_progress']);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Item successfully put away into destination bin.',
            'data' => $receipt->fresh('items.variant.product'),
        ]);
    }
}
