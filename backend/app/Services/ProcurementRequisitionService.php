<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionApproval;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProcurementRequisitionService
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * Create a new purchase requisition.
     */
    public function createRequisition(array $data, User $requester): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $requester) {
            $orgId = $requester->currentOrganization()->id;
            $prNumber = $this->generatePrNumber($orgId);

            $subtotal = 0.00;
            $itemsData = $data['items'] ?? [];

            if (empty($itemsData)) {
                throw ValidationException::withMessages([
                    'items' => 'At least one line item is required for a purchase requisition.',
                ]);
            }

            foreach ($itemsData as $item) {
                $qty = (int) ($item['requested_quantity'] ?? 1);
                $unitPrice = (float) ($item['estimated_unit_price'] ?? 0.00);
                $subtotal += ($qty * $unitPrice);
            }

            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : 0.00;
            $taxAmount = $subtotal * ($taxRate / 100);
            $totalAmount = $subtotal + $taxAmount;

            $requisition = PurchaseRequisition::create([
                'organization_id' => $orgId,
                'pr_number' => $prNumber,
                'requester_id' => $requester->id,
                'department' => $data['department'] ?? 'General Procurement',
                'warehouse_id' => $data['warehouse_id'],
                'delivery_location_id' => $data['delivery_location_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'draft',
                'required_by_date' => $data['required_by_date'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'justification' => $data['justification'] ?? null,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?? 'USD',
            ]);

            foreach ($itemsData as $item) {
                $qty = max(1, (int) ($item['requested_quantity'] ?? 1));
                $unitPrice = (float) ($item['estimated_unit_price'] ?? 0.00);
                $lineSubtotal = $qty * $unitPrice;

                PurchaseRequisitionItem::create([
                    'purchase_requisition_id' => $requisition->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'],
                    'sku' => $item['sku'] ?? 'SKU',
                    'product_name' => $item['product_name'] ?? 'Product Item',
                    'requested_quantity' => $qty,
                    'approved_quantity' => 0,
                    'ordered_quantity' => 0,
                    'estimated_unit_price' => $unitPrice,
                    'estimated_subtotal' => $lineSubtotal,
                    'uom' => $item['uom'] ?? 'pcs',
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            // Record initial audit entry
            PurchaseRequisitionApproval::create([
                'purchase_requisition_id' => $requisition->id,
                'user_id' => $requester->id,
                'action' => 'created',
                'comments' => 'Purchase requisition created as draft.',
                'created_at' => now(),
            ]);

            return $requisition->load(['items.variant.product', 'requester', 'warehouse', 'approvals.user']);
        });
    }

    /**
     * Submit purchase requisition for management review.
     */
    public function submitRequisition(PurchaseRequisition $requisition, User $user, ?string $comments = null): PurchaseRequisition
    {
        if ($requisition->status !== 'draft') {
            throw new InvalidArgumentException("Requisition #{$requisition->pr_number} cannot be submitted because it is currently {$requisition->status}.");
        }

        if ($requisition->items()->count() === 0) {
            throw new InvalidArgumentException("Cannot submit an empty requisition.");
        }

        return DB::transaction(function () use ($requisition, $user, $comments) {
            $requisition->status = 'submitted';
            $requisition->submitted_at = now();
            $requisition->save();

            PurchaseRequisitionApproval::create([
                'purchase_requisition_id' => $requisition->id,
                'user_id' => $user->id,
                'action' => 'submitted',
                'comments' => $comments ?: 'Submitted for management authorization.',
                'created_at' => now(),
            ]);

            return $requisition->fresh(['items.variant.product', 'requester', 'approver', 'warehouse', 'approvals.user']);
        });
    }

    /**
     * Approve purchase requisition with strict Separation of Duties.
     */
    public function approveRequisition(
        PurchaseRequisition $requisition,
        User $approver,
        ?array $approvedItemQuantities = null,
        ?string $comments = null
    ): PurchaseRequisition {
        // Enforce Separation of Duties: Requester cannot approve their own requisition!
        if ($requisition->requester_id === $approver->id) {
            throw ValidationException::withMessages([
                'approver' => 'Separation of duties violation: You cannot approve your own purchase requisition. An independent authorized manager must review and approve.',
            ]);
        }

        if (! in_array($requisition->status, ['submitted', 'under_review'])) {
            throw new InvalidArgumentException("Requisition #{$requisition->pr_number} is not pending approval (current status: {$requisition->status}).");
        }

        return DB::transaction(function () use ($requisition, $approver, $approvedItemQuantities, $comments) {
            $subtotal = 0.00;

            foreach ($requisition->items as $item) {
                // If specific quantities provided, use them; otherwise default to requested quantity
                $approvedQty = isset($approvedItemQuantities[$item->id])
                    ? (int) $approvedItemQuantities[$item->id]
                    : $item->requested_quantity;

                $item->approved_quantity = max(0, $approvedQty);
                $item->estimated_subtotal = $item->approved_quantity * $item->estimated_unit_price;
                $item->save();

                $subtotal += $item->estimated_subtotal;
            }

            $taxAmount = $subtotal * 0.00; // Recalculate if needed
            $requisition->subtotal = $subtotal;
            $requisition->tax_amount = $taxAmount;
            $requisition->total_amount = $subtotal + $taxAmount;
            $requisition->status = 'approved';
            $requisition->approved_at = now();
            $requisition->approved_by = $approver->id;
            $requisition->save();

            PurchaseRequisitionApproval::create([
                'purchase_requisition_id' => $requisition->id,
                'user_id' => $approver->id,
                'action' => 'approved',
                'comments' => $comments ?: 'Approved for procurement conversion.',
                'created_at' => now(),
            ]);

            return $requisition->fresh(['items.variant.product', 'requester', 'approver', 'warehouse', 'approvals.user']);
        });
    }

    /**
     * Reject purchase requisition.
     */
    public function rejectRequisition(PurchaseRequisition $requisition, User $rejecter, string $reason, ?string $comments = null): PurchaseRequisition
    {
        if (! in_array($requisition->status, ['submitted', 'under_review', 'draft'])) {
            throw new InvalidArgumentException("Requisition #{$requisition->pr_number} cannot be rejected from status {$requisition->status}.");
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'A specific rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($requisition, $rejecter, $reason, $comments) {
            $requisition->status = 'rejected';
            $requisition->rejected_at = now();
            $requisition->rejected_by = $rejecter->id;
            $requisition->rejection_reason = $reason;
            $requisition->save();

            PurchaseRequisitionApproval::create([
                'purchase_requisition_id' => $requisition->id,
                'user_id' => $rejecter->id,
                'action' => 'rejected',
                'comments' => "Rejected: {$reason}".($comments ? " | Notes: {$comments}" : ''),
                'created_at' => now(),
            ]);

            return $requisition->fresh(['items.variant.product', 'requester', 'rejecter', 'warehouse', 'approvals.user']);
        });
    }

    /**
     * Convert an approved Purchase Requisition to a Purchase Order.
     */
    public function convertToPurchaseOrder(PurchaseRequisition $requisition, array $data, User $user): PurchaseOrder
    {
        if (! in_array($requisition->status, ['approved', 'partially_ordered'])) {
            throw new InvalidArgumentException("Only approved or partially ordered requisitions can be converted into purchase orders (current status: {$requisition->status}).");
        }

        return DB::transaction(function () use ($requisition, $data, $user) {
            $orgId = $requisition->organization_id;
            $poNumber = $this->generatePoNumber($orgId);

            $po = PurchaseOrder::create([
                'organization_id' => $orgId,
                'po_number' => $poNumber,
                'purchase_requisition_id' => $requisition->id,
                'supplier_name' => $data['supplier_name'],
                'supplier_email' => $data['supplier_email'] ?? null,
                'supplier_contact' => $data['supplier_contact'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? $requisition->warehouse_id,
                'created_by_user_id' => $user->id,
                'status' => 'issued',
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'currency' => $data['currency'] ?? $requisition->currency,
                'notes' => $data['notes'] ?? "Generated from Requisition #{$requisition->pr_number}",
                'issued_at' => now(),
            ]);

            $subtotal = 0.00;
            $selectedItems = $data['items'] ?? [];

            // If no specific item mapping provided, default to ordering all remaining approved quantities
            if (empty($selectedItems)) {
                foreach ($requisition->items as $prItem) {
                    $remaining = $prItem->remaining_quantity;
                    if ($remaining > 0) {
                        $selectedItems[] = [
                            'purchase_requisition_item_id' => $prItem->id,
                            'ordered_quantity' => $remaining,
                            'unit_cost' => $prItem->estimated_unit_price,
                        ];
                    }
                }
            }

            if (empty($selectedItems)) {
                throw ValidationException::withMessages([
                    'items' => 'No remaining approved quantities are available to convert into a purchase order.',
                ]);
            }

            foreach ($selectedItems as $itemInput) {
                /** @var PurchaseRequisitionItem $prItem */
                $prItem = $requisition->items()->findOrFail($itemInput['purchase_requisition_item_id']);
                $orderQty = (int) $itemInput['ordered_quantity'];
                $remaining = $prItem->remaining_quantity;

                if ($orderQty <= 0) {
                    continue;
                }

                if ($orderQty > $remaining) {
                    throw ValidationException::withMessages([
                        'items' => "Requested order quantity ({$orderQty}) for SKU {$prItem->sku} exceeds remaining approved quantity ({$remaining}).",
                    ]);
                }

                $unitCost = isset($itemInput['unit_cost']) ? (float) $itemInput['unit_cost'] : (float) $prItem->estimated_unit_price;
                $lineTotal = $orderQty * $unitCost;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'purchase_requisition_item_id' => $prItem->id,
                    'product_variant_id' => $prItem->product_variant_id,
                    'sku' => $prItem->sku,
                    'product_name' => $prItem->product_name,
                    'ordered_quantity' => $orderQty,
                    'received_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineTotal,
                    'uom' => $prItem->uom,
                    'notes' => $itemInput['notes'] ?? null,
                ]);

                // Increment ordered quantity on PR item
                $prItem->ordered_quantity += $orderQty;
                $prItem->save();

                $subtotal += $lineTotal;
            }

            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : 0.00;
            $taxAmount = $subtotal * ($taxRate / 100);
            $po->subtotal = $subtotal;
            $po->tax_amount = $taxAmount;
            $po->total_amount = $subtotal + $taxAmount;
            $po->save();

            // Check if PR is fully ordered or partially ordered
            $allFulfilled = true;
            foreach ($requisition->items()->get() as $item) {
                if ($item->remaining_quantity > 0) {
                    $allFulfilled = false;
                    break;
                }
            }

            $requisition->status = $allFulfilled ? 'ordered' : 'partially_ordered';
            $requisition->save();

            PurchaseRequisitionApproval::create([
                'purchase_requisition_id' => $requisition->id,
                'user_id' => $user->id,
                'action' => 'converted_to_po',
                'comments' => "Converted to Purchase Order #{$po->po_number}".($allFulfilled ? ' (Fully Ordered)' : ' (Partially Ordered)'),
                'created_at' => now(),
            ]);

            return $po->load(['items.variant.product', 'requisition', 'warehouse', 'creator']);
        });
    }

    /**
     * Enrich requisition items with real-time stock planning context from inventory engine.
     */
    public function getStockPlanningContext(int $organizationId, int $warehouseId, int $productVariantId): array
    {
        $avail = $this->inventoryEngine->getAvailability($organizationId, $warehouseId, $productVariantId);

        // Calculate on-order quantity from open POs
        $onOrder = (int) PurchaseOrderItem::whereHas('purchaseOrder', function ($q) use ($organizationId, $warehouseId) {
            $q->where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('status', ['draft', 'issued', 'confirmed', 'partially_received']);
        })
            ->where('product_variant_id', $productVariantId)
            ->selectRaw('SUM(ordered_quantity - received_quantity) as total_on_order')
            ->value('total_on_order') ?? 0;

        return [
            'on_hand' => $avail['on_hand'],
            'reserved' => $avail['reserved'],
            'available' => $avail['available'],
            'damaged' => $avail['damaged'],
            'quarantined' => $avail['quarantined'],
            'on_order' => max(0, $onOrder),
        ];
    }

    protected function generatePrNumber(int $organizationId): string
    {
        $year = date('Y');
        $count = PurchaseRequisition::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('PR-%s-%04d', $year, $count);
    }

    protected function generatePoNumber(int $organizationId): string
    {
        $year = date('Y');
        $count = PurchaseOrder::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('PO-%s-%04d', $year, $count);
    }
}
