<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase requisitions: draft → submitted → approved or rejected → (partially) ordered through purchase orders.
 * Every step is recorded in the requisition's approval history.
 */
class ProcurementRequisitionService
{
    private const RELATIONS = ['items.variant.product', 'requester', 'approver', 'rejecter', 'warehouse', 'approvals.user'];

    public function __construct(
        protected InventoryEngine $inventoryEngine,
        protected PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * Create a draft requisition.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function createRequisition(array $data, User $requester): PurchaseRequisition
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'At least one line item is required for a purchase requisition.']);
        }

        return DB::transaction(function () use ($data, $requester) {
            $orgId = $requester->currentOrganization()->id;
            $lines = array_map(fn (array $item) => $item + [
                'estimated_subtotal' => round((int) $item['requested_quantity'] * (float) ($item['estimated_unit_price'] ?? 0), 2),
            ], $data['items']);
            $subtotal = array_sum(array_column($lines, 'estimated_subtotal'));
            $taxAmount = round($subtotal * (float) ($data['tax_rate'] ?? 0) / 100, 2);

            $requisition = PurchaseRequisition::create([
                'organization_id' => $orgId,
                'pr_number' => PurchaseRequisition::nextNumber($orgId),
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
                'total_amount' => $subtotal + $taxAmount,
                'currency' => $data['currency'] ?? 'USD',
            ]);

            foreach ($lines as $line) {
                $requisition->items()->create([
                    'product_id' => $line['product_id'] ?? null,
                    'product_variant_id' => $line['product_variant_id'],
                    'sku' => $line['sku'],
                    'product_name' => $line['product_name'],
                    'requested_quantity' => (int) $line['requested_quantity'],
                    'approved_quantity' => 0,
                    'ordered_quantity' => 0,
                    'estimated_unit_price' => (float) ($line['estimated_unit_price'] ?? 0),
                    'estimated_subtotal' => $line['estimated_subtotal'],
                    'uom' => $line['uom'] ?? 'pcs',
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->record($requisition, $requester, 'created', 'Purchase requisition created as draft.');

            return $requisition->load(self::RELATIONS);
        });
    }

    /**
     * Send a draft requisition for management approval.
     */
    public function submitRequisition(PurchaseRequisition $requisition, User $user, ?string $comments = null): PurchaseRequisition
    {
        $this->ensureStatus($requisition, ['draft'], 'submitted');

        return DB::transaction(function () use ($requisition, $user, $comments) {
            $requisition->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->record($requisition, $user, 'submitted', $comments ?: 'Submitted for management authorization.');

            return $requisition->fresh(self::RELATIONS);
        });
    }

    /**
     * Approve a submitted requisition, optionally for smaller quantities than requested ([item ID => quantity]).
     * The requester cannot approve their own requisition (separation of duties).
     *
     * @param  array<int|string, int>|null  $approvedItemQuantities
     */
    public function approveRequisition(PurchaseRequisition $requisition, User $approver, ?array $approvedItemQuantities = null, ?string $comments = null): PurchaseRequisition
    {
        if ($requisition->requester_id === $approver->id) {
            throw ValidationException::withMessages([
                'approver' => 'Separation of duties violation: You cannot approve your own purchase requisition. An independent authorized manager must review and approve.',
            ]);
        }

        $this->ensureStatus($requisition, ['submitted', 'under_review'], 'approved');

        return DB::transaction(function () use ($requisition, $approver, $approvedItemQuantities, $comments) {
            // Keep the tax rate the requisition was raised with when the amounts change.
            $taxRate = (float) $requisition->subtotal > 0 ? (float) $requisition->tax_amount / (float) $requisition->subtotal : 0.0;
            $subtotal = 0.0;

            foreach ($requisition->items as $item) {
                // An approver can reduce a quantity, never raise it above what was requested.
                $approved = min($item->requested_quantity, max(0, (int) ($approvedItemQuantities[$item->id] ?? $item->requested_quantity)));
                $item->update(['approved_quantity' => $approved, 'estimated_subtotal' => round($approved * (float) $item->estimated_unit_price, 2)]);
                $subtotal += (float) $item->estimated_subtotal;
            }

            if ($requisition->items->every(fn ($item) => $item->approved_quantity === 0)) {
                throw ValidationException::withMessages(['approved_quantities' => 'Approve at least one item, or reject the requisition instead.']);
            }

            $taxAmount = round($subtotal * $taxRate, 2);
            $requisition->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $approver->id,
            ]);
            $this->record($requisition, $approver, 'approved', $comments ?: 'Approved for procurement conversion.');

            return $requisition->fresh(self::RELATIONS);
        });
    }

    public function rejectRequisition(PurchaseRequisition $requisition, User $rejecter, string $reason, ?string $comments = null): PurchaseRequisition
    {
        $this->ensureStatus($requisition, ['draft', 'submitted', 'under_review'], 'rejected');

        return DB::transaction(function () use ($requisition, $rejecter, $reason, $comments) {
            $requisition->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $rejecter->id,
                'rejection_reason' => $reason,
            ]);
            $this->record($requisition, $rejecter, 'rejected', "Rejected: {$reason}".($comments ? " | Notes: {$comments}" : ''));

            return $requisition->fresh(self::RELATIONS);
        });
    }

    /**
     * Raise a purchase order for an approved requisition. Without item choices, everything approved and not yet
     * ordered goes on the order; with them ([purchase_requisition_item_id, ordered_quantity, unit_cost?]),
     * each quantity may not exceed what is still left to order.
     *
     * @param  array<string, mixed>  $data  validated input (supplier and order details, optional items)
     */
    public function convertToPurchaseOrder(PurchaseRequisition $requisition, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($requisition, $data, $user) {
            // Locked so two people converting at once cannot order the same quantities twice.
            $requisition = PurchaseRequisition::with('items')->lockForUpdate()->findOrFail($requisition->id);
            $this->ensureStatus($requisition, ['approved', 'partially_ordered'], 'converted into a purchase order');

            $choices = collect($data['items'] ?? [])->keyBy('purchase_requisition_item_id');
            $lines = [];

            foreach ($requisition->items as $item) {
                $choice = $choices->get($item->id);
                $quantity = $choices->isEmpty() ? $item->remaining_quantity : (int) ($choice['ordered_quantity'] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                if ($quantity > $item->remaining_quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Requested order quantity ({$quantity}) for SKU {$item->sku} exceeds remaining approved quantity ({$item->remaining_quantity}).",
                    ]);
                }

                $lines[] = [
                    'purchase_requisition_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $item->sku,
                    'product_name' => $item->product_name,
                    'ordered_quantity' => $quantity,
                    'unit_cost' => $choice['unit_cost'] ?? $item->estimated_unit_price,
                    'uom' => $item->uom,
                ];
                $item->update(['ordered_quantity' => $item->ordered_quantity + $quantity]);
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'No remaining approved quantities are available to convert into a purchase order.']);
            }

            $po = $this->purchaseOrders->createPurchaseOrder([
                ...$data,
                'purchase_requisition_id' => $requisition->id,
                'warehouse_id' => $data['warehouse_id'] ?? $requisition->warehouse_id,
                'currency' => $data['currency'] ?? $requisition->currency,
                'notes' => $data['notes'] ?? "Generated from Requisition #{$requisition->pr_number}",
                'items' => $lines,
            ], $user);

            $requisition->refreshOrderStatus();
            $this->record($requisition, $user, 'converted_to_po', "Converted to Purchase Order #{$po->po_number}".($requisition->status === 'ordered' ? ' (Fully Ordered)' : ' (Partially Ordered)'));

            return $po->load(['items.variant.product', 'requisition', 'warehouse', 'creator']);
        });
    }

    /**
     * Stock of a variant in a warehouse, plus what is already on open purchase orders, to help size a requisition.
     *
     * @return array{on_hand: int, reserved: int, available: int, damaged: int, quarantined: int, on_order: int}
     */
    public function getStockPlanningContext(int $organizationId, int $warehouseId, int $productVariantId): array
    {
        $availability = $this->inventoryEngine->getAvailability($organizationId, $warehouseId, $productVariantId);

        $onOrder = (int) PurchaseOrderItem::whereHas('purchaseOrder', fn ($q) => $q
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['draft', 'issued', 'confirmed', 'partially_received']))
            ->where('product_variant_id', $productVariantId)
            ->sum(DB::raw('ordered_quantity - received_quantity'));

        return [
            'on_hand' => $availability['on_hand'],
            'reserved' => $availability['reserved'],
            'available' => $availability['available'],
            'damaged' => $availability['damaged'],
            'quarantined' => $availability['quarantined'],
            'on_order' => max(0, $onOrder),
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function ensureStatus(PurchaseRequisition $requisition, array $allowed, string $action): void
    {
        if (! in_array($requisition->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Requisition #{$requisition->pr_number} cannot be {$action} because it is {$requisition->status}.",
            ]);
        }
    }

    /**
     * Add a step to the requisition's approval history.
     */
    private function record(PurchaseRequisition $requisition, User $user, string $action, string $comments): void
    {
        PurchaseRequisitionApproval::create([
            'purchase_requisition_id' => $requisition->id,
            'user_id' => $user->id,
            'action' => $action,
            'comments' => $comments,
            'created_at' => now(),
        ]);
    }
}
