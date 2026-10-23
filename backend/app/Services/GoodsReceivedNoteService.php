<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class GoodsReceivedNoteService
{
    public function __construct(
        protected InventoryEngine $inventoryEngine,
        protected ?ProcurementPdfService $pdfService = null
    ) {}

    /**
     * Create a new Goods Received Note against a Purchase Order.
     */
    public function createGrn(PurchaseOrder $purchaseOrder, array $data, User $receivedBy): GoodsReceivedNote
    {
        if (in_array($purchaseOrder->status, ['cancelled', 'received'])) {
            throw new InvalidArgumentException("Cannot create GRN for Purchase Order #{$purchaseOrder->po_number} because it is {$purchaseOrder->status}.");
        }

        return DB::transaction(function () use ($purchaseOrder, $data, $receivedBy) {
            $orgId = $purchaseOrder->organization_id;
            $grnNumber = $this->generateGrnNumber($orgId);

            $grn = GoodsReceivedNote::create([
                'organization_id' => $orgId,
                'grn_number' => $grnNumber,
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $data['warehouse_id'] ?? $purchaseOrder->warehouse_id,
                'receiving_location_id' => $data['receiving_location_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? $purchaseOrder->supplier_name,
                'supplier_delivery_note' => $data['supplier_delivery_note'] ?? null,
                'carrier' => $data['carrier'] ?? null,
                'tracking_or_vehicle_number' => $data['tracking_or_vehicle_number'] ?? null,
                'received_by_user_id' => $receivedBy->id,
                'inspected_by_user_id' => ! empty($data['inspected_by_user_id']) ? $data['inspected_by_user_id'] : null,
                'status' => 'draft',
                'received_at' => $data['received_at'] ?? now(),
                'inspected_at' => ! empty($data['inspected_at']) ? $data['inspected_at'] : null,
                'notes' => $data['notes'] ?? null,
                'discrepancy_summary' => $data['discrepancy_summary'] ?? null,
            ]);

            $totalReceived = 0;
            $totalAccepted = 0;
            $totalDamaged = 0;
            $totalQuarantined = 0;
            $totalRejected = 0;

            $itemsInput = $data['items'] ?? [];
            if (empty($itemsInput)) {
                // If items not explicitly provided, populate from PO items with outstanding quantities
                foreach ($purchaseOrder->items as $poItem) {
                    $itemsInput[] = [
                        'purchase_order_item_id' => $poItem->id,
                        'received_quantity' => $poItem->outstanding_quantity,
                        'accepted_quantity' => $poItem->outstanding_quantity,
                        'damaged_quantity' => 0,
                        'quarantined_quantity' => 0,
                        'rejected_quantity' => 0,
                        'pending_inspection_quantity' => 0,
                    ];
                }
            }

            foreach ($itemsInput as $itemData) {
                /** @var PurchaseOrderItem $poItem */
                $poItem = $purchaseOrder->items()->findOrFail($itemData['purchase_order_item_id']);

                // Calculate cumulative previously received quantity across posted GRNs for this PO item
                $previouslyReceived = (int) GoodsReceivedNoteItem::whereHas('goodsReceivedNote', function ($q) {
                    $q->where('status', 'posted');
                })
                    ->where('purchase_order_item_id', $poItem->id)
                    ->selectRaw('SUM(accepted_quantity + damaged_quantity + quarantined_quantity) as total_received')
                    ->value('total_received') ?? 0;

                $accepted = max(0, (int) ($itemData['accepted_quantity'] ?? 0));
                $damaged = max(0, (int) ($itemData['damaged_quantity'] ?? 0));
                $quarantined = max(0, (int) ($itemData['quarantined_quantity'] ?? 0));
                $rejected = max(0, (int) ($itemData['rejected_quantity'] ?? 0));
                $pending = max(0, (int) ($itemData['pending_inspection_quantity'] ?? 0));

                $receivedQty = isset($itemData['received_quantity']) && (int) $itemData['received_quantity'] > 0
                    ? (int) $itemData['received_quantity']
                    : ($accepted + $damaged + $quarantined + $rejected + $pending);

                // Reconcile condition sum
                $conditionSum = $accepted + $damaged + $quarantined + $rejected + $pending;
                if ($receivedQty > 0 && $conditionSum === 0) {
                    $accepted = $receivedQty;
                    $conditionSum = $receivedQty;
                } elseif ($conditionSum !== $receivedQty) {
                    throw ValidationException::withMessages([
                        'items' => "Item for SKU {$poItem->sku}: Total condition breakdown ({$conditionSum}) does not equal total received count ({$receivedQty}).",
                    ]);
                }

                // Over-receipt tolerance validation (10% tolerance by default)
                $allowOverReceipt = ! empty($data['allow_over_receipt']);
                $maxAllowed = (int) ceil($poItem->ordered_quantity * 1.10);
                if (! $allowOverReceipt && ($previouslyReceived + $receivedQty) > $maxAllowed) {
                    throw ValidationException::withMessages([
                        'items' => "Item SKU {$poItem->sku}: Total receiving ({$previouslyReceived} previous + {$receivedQty} current = ".($previouslyReceived + $receivedQty).") exceeds 10% over-receipt tolerance for ordered quantity ({$poItem->ordered_quantity}). Over-receipt override required.",
                    ]);
                }

                GoodsReceivedNoteItem::create([
                    'goods_received_note_id' => $grn->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_variant_id' => $poItem->product_variant_id,
                    'sku' => $poItem->sku,
                    'product_name' => $poItem->product_name,
                    'ordered_quantity' => $poItem->ordered_quantity,
                    'previously_received_quantity' => $previouslyReceived,
                    'received_quantity' => $receivedQty,
                    'accepted_quantity' => $accepted,
                    'damaged_quantity' => $damaged,
                    'quarantined_quantity' => $quarantined,
                    'rejected_quantity' => $rejected,
                    'pending_inspection_quantity' => $pending,
                    'destination_location_id' => $itemData['destination_location_id'] ?? $grn->receiving_location_id,
                    'lot_number' => $itemData['lot_number'] ?? null,
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                    'manufacturing_date' => $itemData['manufacturing_date'] ?? null,
                    'discrepancy_reason' => $itemData['discrepancy_reason'] ?? null,
                    'notes' => $itemData['notes'] ?? null,
                    'status' => 'draft',
                ]);

                $totalReceived += $receivedQty;
                $totalAccepted += $accepted;
                $totalDamaged += $damaged;
                $totalQuarantined += $quarantined;
                $totalRejected += $rejected;
            }

            $grn->update([
                'total_received_units' => $totalReceived,
                'total_accepted_units' => $totalAccepted,
                'total_damaged_units' => $totalDamaged,
                'total_quarantined_units' => $totalQuarantined,
                'total_rejected_units' => $totalRejected,
            ]);

            return $grn->load(['items.destinationLocation', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'receivedBy']);
        });
    }

    /**
     * Complete physical inspection and update condition distribution.
     */
    public function inspectGrn(GoodsReceivedNote $grn, array $inspectionData, User $inspector): GoodsReceivedNote
    {
        if ($grn->status === 'posted') {
            throw new InvalidArgumentException("GRN #{$grn->grn_number} is already posted to inventory and cannot be modified.");
        }

        return DB::transaction(function () use ($grn, $inspectionData, $inspector) {
            $totalAccepted = 0;
            $totalDamaged = 0;
            $totalQuarantined = 0;
            $totalRejected = 0;

            if (! empty($inspectionData['items'])) {
                foreach ($inspectionData['items'] as $itemUpdate) {
                    /** @var GoodsReceivedNoteItem $grnItem */
                    $grnItem = $grn->items()->findOrFail($itemUpdate['id']);

                    $accepted = max(0, (int) ($itemUpdate['accepted_quantity'] ?? $grnItem->accepted_quantity));
                    $damaged = max(0, (int) ($itemUpdate['damaged_quantity'] ?? $grnItem->damaged_quantity));
                    $quarantined = max(0, (int) ($itemUpdate['quarantined_quantity'] ?? $grnItem->quarantined_quantity));
                    $rejected = max(0, (int) ($itemUpdate['rejected_quantity'] ?? $grnItem->rejected_quantity));
                    $pending = max(0, (int) ($itemUpdate['pending_inspection_quantity'] ?? 0));

                    $sum = $accepted + $damaged + $quarantined + $rejected + $pending;
                    if ($sum !== $grnItem->received_quantity) {
                        throw ValidationException::withMessages([
                            'items' => "SKU {$grnItem->sku}: Breakdown sum ({$sum}) must equal received quantity ({$grnItem->received_quantity}).",
                        ]);
                    }

                    $grnItem->update([
                        'accepted_quantity' => $accepted,
                        'damaged_quantity' => $damaged,
                        'quarantined_quantity' => $quarantined,
                        'rejected_quantity' => $rejected,
                        'pending_inspection_quantity' => $pending,
                        'lot_number' => $itemUpdate['lot_number'] ?? $grnItem->lot_number,
                        'expiry_date' => $itemUpdate['expiry_date'] ?? $grnItem->expiry_date,
                        'discrepancy_reason' => $itemUpdate['discrepancy_reason'] ?? $grnItem->discrepancy_reason,
                        'status' => 'inspected',
                    ]);

                    $totalAccepted += $accepted;
                    $totalDamaged += $damaged;
                    $totalQuarantined += $quarantined;
                    $totalRejected += $rejected;
                }
            } else {
                $totalAccepted = $grn->items()->sum('accepted_quantity');
                $totalDamaged = $grn->items()->sum('damaged_quantity');
                $totalQuarantined = $grn->items()->sum('quarantined_quantity');
                $totalRejected = $grn->items()->sum('rejected_quantity');
            }

            $grn->update([
                'status' => 'inspected',
                'inspected_by_user_id' => $inspector->id,
                'inspected_at' => now(),
                'total_accepted_units' => $totalAccepted,
                'total_damaged_units' => $totalDamaged,
                'total_quarantined_units' => $totalQuarantined,
                'total_rejected_units' => $totalRejected,
                'notes' => $inspectionData['notes'] ?? $grn->notes,
                'discrepancy_summary' => $inspectionData['discrepancy_summary'] ?? $grn->discrepancy_summary,
            ]);

            return $grn->fresh(['items.destinationLocation', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'inspectedBy']);
        });
    }

    /**
     * Atomically post GRN quantities into authoritative inventory balances and ledger.
     */
    public function postToInventory(GoodsReceivedNote $grn, User $user): GoodsReceivedNote
    {
        if ($grn->status === 'posted') {
            throw new InvalidArgumentException("GRN #{$grn->grn_number} has already been posted to inventory.");
        }

        DB::transaction(function () use ($grn, $user) {
            $po = $grn->purchaseOrder()->lockForUpdate()->first();
            $orgId = $grn->organization_id;
            $whId = $grn->warehouse_id;

            foreach ($grn->items as $grnItem) {
                $variantId = $grnItem->product_variant_id;
                $destLocationId = $grnItem->destination_location_id ?: $grn->receiving_location_id;
                $lotNumber = $grnItem->lot_number;
                $expiryDate = $grnItem->expiry_date ? $grnItem->expiry_date->format('Y-m-d') : null;

                // 1. Post Accepted Quantities (condition = 'available')
                if ($grnItem->accepted_quantity > 0) {
                    $this->inventoryEngine->receiveStock(
                        organizationId: $orgId,
                        warehouseId: $whId,
                        productVariantId: $variantId,
                        quantity: $grnItem->accepted_quantity,
                        locationId: $destLocationId,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                        condition: 'available',
                        referenceDoc: "GRN: {$grn->grn_number} (PO: {$po->po_number})",
                        userId: $user->id,
                        idempotencyKey: "GRN-POST-{$grn->id}-ITEM-{$grnItem->id}-ACCEPTED"
                    );
                }

                // 2. Post Damaged Quantities (condition = 'damaged', isolated from sellable available balance)
                if ($grnItem->damaged_quantity > 0) {
                    $this->inventoryEngine->receiveStock(
                        organizationId: $orgId,
                        warehouseId: $whId,
                        productVariantId: $variantId,
                        quantity: $grnItem->damaged_quantity,
                        locationId: $destLocationId,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                        condition: 'damaged',
                        referenceDoc: "GRN: {$grn->grn_number} (Damaged)",
                        userId: $user->id,
                        idempotencyKey: "GRN-POST-{$grn->id}-ITEM-{$grnItem->id}-DAMAGED"
                    );
                }

                // 3. Post Quarantined Quantities (condition = 'quarantined', quarantined balance)
                if ($grnItem->quarantined_quantity > 0) {
                    $this->inventoryEngine->receiveStock(
                        organizationId: $orgId,
                        warehouseId: $whId,
                        productVariantId: $variantId,
                        quantity: $grnItem->quarantined_quantity,
                        locationId: $destLocationId,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                        condition: 'quarantined',
                        referenceDoc: "GRN: {$grn->grn_number} (Quarantined)",
                        userId: $user->id,
                        idempotencyKey: "GRN-POST-{$grn->id}-ITEM-{$grnItem->id}-QUARANTINE"
                    );
                }

                // (Rejected stock is returned to vendor, not placed in warehouse inventory)

                // 4. Update Purchase Order Item received quantity
                $physicalCustodyQty = $grnItem->accepted_quantity + $grnItem->damaged_quantity + $grnItem->quarantined_quantity;
                $poItem = $grnItem->purchaseOrderItem;
                if ($poItem) {
                    $poItem->received_quantity += $physicalCustodyQty;
                    $poItem->save();

                    // Stock is valued at what it was last bought for.
                    if ((float) $poItem->unit_cost > 0) {
                        ProductVariant::whereKey($variantId)->update(['cost_price' => $poItem->unit_cost]);
                    }
                }

                $grnItem->status = 'posted';
                $grnItem->save();
            }

            // 5. Update overall PO Status
            $allPoItems = $po->items()->get();
            $allReceived = true;
            $anyReceived = false;

            foreach ($allPoItems as $item) {
                if ($item->received_quantity >= $item->ordered_quantity) {
                    $anyReceived = true;
                } else {
                    $allReceived = false;
                    if ($item->received_quantity > 0) {
                        $anyReceived = true;
                    }
                }
            }

            if ($allReceived && $allPoItems->isNotEmpty()) {
                $po->status = 'received';
            } elseif ($anyReceived) {
                $po->status = 'partially_received';
            }
            $po->save();

            // 6. Update GRN status
            $grn->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by_user_id' => $user->id,
            ]);
        });

        // 7. Background/Safe PDF Generation (Guarded: PDF failure MUST NEVER rollback inventory posting)
        try {
            if ($this->pdfService) {
                $pdfPath = $this->pdfService->generateGrnPdf($grn);
                $grn->update(['pdf_path' => $pdfPath]);
            }
        } catch (\Throwable $e) {
            Log::warning("GRN #{$grn->grn_number} PDF generation failed after posting: ".$e->getMessage());
        }

        return $grn->fresh(['items.destinationLocation', 'purchaseOrder.items', 'warehouse', 'receivingLocation', 'postedBy']);
    }

    protected function generateGrnNumber(int $organizationId): string
    {
        $year = date('Y');
        $count = GoodsReceivedNote::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('GRN-%s-%04d', $year, $count);
    }
}
