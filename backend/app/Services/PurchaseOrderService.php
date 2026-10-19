<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseOrderService
{
    /**
     * Create a standalone or PR-linked Purchase Order.
     */
    public function createPurchaseOrder(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            $orgId = $user->currentOrganization()->id;
            $poNumber = $this->generatePoNumber($orgId);

            $itemsData = $data['items'] ?? [];
            if (empty($itemsData)) {
                throw ValidationException::withMessages([
                    'items' => 'At least one line item is required for a purchase order.',
                ]);
            }

            $subtotal = 0.00;
            foreach ($itemsData as $item) {
                $qty = max(1, (int) ($item['ordered_quantity'] ?? 1));
                $cost = (float) ($item['unit_cost'] ?? 0.00);
                $subtotal += ($qty * $cost);
            }

            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : 0.00;
            $taxAmount = $subtotal * ($taxRate / 100);
            $totalAmount = $subtotal + $taxAmount;

            $status = $data['status'] ?? 'issued';

            $po = PurchaseOrder::create([
                'organization_id' => $orgId,
                'po_number' => $poNumber,
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
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
                'issued_at' => $status === 'issued' ? now() : null,
            ]);

            foreach ($itemsData as $item) {
                $qty = max(1, (int) ($item['ordered_quantity'] ?? 1));
                $cost = (float) ($item['unit_cost'] ?? 0.00);
                $lineTotal = $qty * $cost;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'purchase_requisition_item_id' => $item['purchase_requisition_item_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'],
                    'sku' => $item['sku'] ?? 'SKU',
                    'product_name' => $item['product_name'] ?? 'Product Variant',
                    'ordered_quantity' => $qty,
                    'received_quantity' => 0,
                    'unit_cost' => $cost,
                    'total_cost' => $lineTotal,
                    'uom' => $item['uom'] ?? 'pcs',
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $po->load(['items.variant.product', 'warehouse', 'creator', 'requisition']);
        });
    }

    /**
     * Mark Purchase Order as issued to supplier.
     */
    public function issuePurchaseOrder(PurchaseOrder $po, User $user): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new InvalidArgumentException("Purchase order #{$po->po_number} is already {$po->status}.");
        }

        $po->update([
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        return $po->fresh(['items.variant.product', 'warehouse', 'creator', 'requisition']);
    }

    /**
     * Cancel Purchase Order.
     */
    public function cancelPurchaseOrder(PurchaseOrder $po, User $user, ?string $reason = null): PurchaseOrder
    {
        if (in_array($po->status, ['cancelled', 'received'])) {
            throw new InvalidArgumentException("Purchase order #{$po->po_number} cannot be cancelled because it is {$po->status}.");
        }

        if ($po->items()->where('received_quantity', '>', 0)->exists()) {
            throw new InvalidArgumentException("Purchase order #{$po->po_number} cannot be cancelled because items have already been received.");
        }

        $po->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => $po->notes . ($reason ? "\nCancellation reason: {$reason}" : "\nCancelled by user"),
        ]);

        return $po->fresh(['items.variant.product', 'warehouse', 'creator', 'requisition']);
    }

    public function generatePoNumber(int $organizationId): string
    {
        $year = date('Y');
        $count = PurchaseOrder::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf('PO-%s-%04d', $year, $count);
    }
}
