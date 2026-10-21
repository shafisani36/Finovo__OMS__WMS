<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders to suppliers: created directly or from an approved requisition, issued and cancelled.
 */
class PurchaseOrderService
{
    /** Statuses in which a purchase order can still be cancelled. */
    private const CANCELLABLE = ['draft', 'issued', 'confirmed'];

    /**
     * Create a purchase order. Each item: product_variant_id, sku, product_name, ordered_quantity, unit_cost,
     * and optionally uom, notes and purchase_requisition_item_id (set when the line comes from a requisition).
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function createPurchaseOrder(array $data, User $user): PurchaseOrder
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'At least one line item is required for a purchase order.']);
        }

        return DB::transaction(function () use ($data, $user) {
            $orgId = $user->currentOrganization()->id;
            $lines = array_map(fn (array $item) => $item + ['total_cost' => round((int) $item['ordered_quantity'] * (float) $item['unit_cost'], 2)], $data['items']);
            $subtotal = array_sum(array_column($lines, 'total_cost'));
            $taxAmount = round($subtotal * (float) ($data['tax_rate'] ?? 0) / 100, 2);
            $status = $data['status'] ?? 'issued';

            $po = PurchaseOrder::create([
                'organization_id' => $orgId,
                'po_number' => PurchaseOrder::nextNumber($orgId),
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'supplier_name' => $data['supplier_name'],
                'supplier_email' => $data['supplier_email'] ?? null,
                'supplier_contact' => $data['supplier_contact'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'created_by_user_id' => $user->id,
                'status' => $status,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'currency' => $data['currency'] ?? 'USD',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
                'notes' => $data['notes'] ?? null,
                'issued_at' => $status === 'issued' ? now() : null,
            ]);

            foreach ($lines as $line) {
                $po->items()->create([
                    'purchase_requisition_item_id' => $line['purchase_requisition_item_id'] ?? null,
                    'product_variant_id' => $line['product_variant_id'],
                    'sku' => $line['sku'],
                    'product_name' => $line['product_name'],
                    'ordered_quantity' => (int) $line['ordered_quantity'],
                    'received_quantity' => 0,
                    'unit_cost' => (float) $line['unit_cost'],
                    'total_cost' => $line['total_cost'],
                    'uom' => $line['uom'] ?? 'pcs',
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $po->load(['items.variant.product', 'warehouse', 'creator', 'requisition']);
        });
    }

    /**
     * Mark a draft purchase order as issued to the supplier.
     */
    public function issuePurchaseOrder(PurchaseOrder $po, User $user): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['status' => "Purchase order #{$po->po_number} is already {$po->status}."]);
        }

        $po->update(['status' => 'issued', 'issued_at' => now()]);

        return $po->fresh(['items.variant.product', 'warehouse', 'creator', 'requisition']);
    }

    /**
     * Cancel a purchase order before anything is received. Quantities it took from a requisition are
     * released, so they can be ordered again.
     */
    public function cancelPurchaseOrder(PurchaseOrder $po, User $user, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $po = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($po->id);

            if (! in_array($po->status, self::CANCELLABLE, true)) {
                throw ValidationException::withMessages(['status' => "Purchase order #{$po->po_number} cannot be cancelled because it is {$po->status}."]);
            }

            if ($po->items->contains(fn ($item) => $item->received_quantity > 0)) {
                throw ValidationException::withMessages(['status' => "Purchase order #{$po->po_number} cannot be cancelled because items have already been received."]);
            }

            $po->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => trim($po->notes."\n".($reason ? "Cancellation reason: {$reason}" : 'Cancelled by user')),
            ]);

            foreach ($po->items->whereNotNull('purchase_requisition_item_id') as $item) {
                $requisitionItem = PurchaseRequisitionItem::lockForUpdate()->find($item->purchase_requisition_item_id);
                $requisitionItem?->update(['ordered_quantity' => max(0, $requisitionItem->ordered_quantity - $item->ordered_quantity)]);
            }

            if ($po->purchase_requisition_id) {
                PurchaseRequisition::find($po->purchase_requisition_id)?->refreshOrderStatus();
            }

            return $po->fresh(['items.variant.product', 'warehouse', 'creator', 'requisition']);
        });
    }
}
