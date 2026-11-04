<?php

namespace App\Services;

use App\Models\GoodsReceivedNote;
use App\Models\GoodsReceivedNoteItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\PickTask;
use App\Models\ReturnItem;
use App\Models\UnitSerialNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * End-to-end traceability of individual serialized product units.
 *
 * Tracks each unique serial number across its entire lifecycle:
 * - Receiving at dock via GRN
 * - Barcode-verified pick & pack for fulfillment
 * - Dispatch & customer shipment
 * - RMA returns, condition inspection and restocking or disposal
 */
class SerialNumberService
{
    /**
     * Register or update unit serial numbers received through a GRN.
     *
     * @param  list<string>  $serialNumbers
     * @return Collection<int, UnitSerialNumber>
     */
    public function registerFromGrn(
        GoodsReceivedNote $grn,
        GoodsReceivedNoteItem $item,
        array $serialNumbers,
        string $condition,
        User $user
    ): Collection {
        if (empty($serialNumbers)) {
            return collect();
        }

        $status = match ($condition) {
            'damaged' => UnitSerialNumber::STATUS_DAMAGED,
            'quarantined' => UnitSerialNumber::STATUS_QUARANTINED,
            default => UnitSerialNumber::STATUS_IN_STOCK,
        };

        $locationId = $item->destination_location_id ?: $grn->receiving_location_id;
        $cleanSerials = array_values(array_unique(array_filter(array_map('trim', $serialNumbers))));

        return DB::transaction(function () use ($grn, $item, $cleanSerials, $status, $condition, $locationId, $user) {
            $created = collect();

            foreach ($cleanSerials as $sn) {
                /** @var UnitSerialNumber $unit */
                $unit = UnitSerialNumber::updateOrCreate(
                    [
                        'organization_id' => $grn->organization_id,
                        'product_variant_id' => $item->product_variant_id,
                        'serial_number' => $sn,
                    ],
                    [
                        'product_id' => $item->productVariant?->product_id,
                        'warehouse_id' => $grn->warehouse_id,
                        'location_id' => $locationId,
                        'lot_number' => $item->lot_number,
                        'status' => $status,
                        'grn_id' => $grn->id,
                        'grn_item_id' => $item->id,
                        'received_at' => $grn->received_at ?? now(),
                    ]
                );

                $unit->recordEvent(
                    event: 'received',
                    userId: $user->id,
                    reference: "GRN #{$grn->grn_number}",
                    details: [
                        'condition' => $condition,
                        'warehouse_id' => $grn->warehouse_id,
                        'location_id' => $locationId,
                        'lot_number' => $item->lot_number,
                    ]
                );
                $unit->save();

                $created->push($unit);
            }

            return $created;
        });
    }

    /**
     * Scan and link a specific unit serial number to a pick task during picking or packing.
     *
     * @throws ValidationException
     */
    public function scanForPickTask(PickTask $task, string $serialCode, string $stage, User $user): UnitSerialNumber
    {
        $serialCode = trim($serialCode);
        $task->loadMissing('order.items');

        /** @var UnitSerialNumber|null $unit */
        $unit = UnitSerialNumber::where('organization_id', $task->organization_id)
            ->where('serial_number', $serialCode)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                'serial_number' => "Serial number '{$serialCode}' was not found in inventory.",
            ]);
        }

        // Verify variant belongs to this order's items
        $matchingItem = $task->order?->items
            ->where('status', '!=', 'cancelled')
            ->firstWhere('product_variant_id', $unit->product_variant_id);

        if (! $matchingItem) {
            throw ValidationException::withMessages([
                'serial_number' => "Unit '{$serialCode}' is not part of Order #{$task->order?->order_number}.",
            ]);
        }

        // In pick stage, unit must be in stock or already allocated to this order
        if ($stage === 'pick') {
            $allowedStatuses = [UnitSerialNumber::STATUS_IN_STOCK, UnitSerialNumber::STATUS_ALLOCATED];
            if (! in_array($unit->status, $allowedStatuses, true) && $unit->order_id !== $task->order_id) {
                throw ValidationException::withMessages([
                    'serial_number' => "Unit '{$serialCode}' is {$unit->status} and cannot be picked.",
                ]);
            }
        }

        DB::transaction(function () use ($unit, $task, $matchingItem, $stage, $user) {
            $nextStatus = $stage === 'pick' ? UnitSerialNumber::STATUS_PICKED : UnitSerialNumber::STATUS_PACKED;
            $unit->update([
                'status' => $nextStatus,
                'order_id' => $task->order_id,
                'order_item_id' => $matchingItem->id,
                'pick_task_id' => $task->id,
                'warehouse_id' => $task->warehouse_id,
            ]);

            $unit->recordEvent(
                event: $stage === 'pick' ? 'picked' : 'packed',
                userId: $user->id,
                reference: "PickTask #{$task->id} (Order #{$task->order?->order_number})",
                details: ['stage' => $stage, 'sku' => $matchingItem->sku]
            );
            $unit->save();
        });

        return $unit->fresh(['productVariant.product', 'warehouse', 'location']);
    }

    /**
     * Mark all serial numbers on an order as shipped when dispatched.
     */
    public function recordOrderDispatched(Order $order, ?User $user = null, ?string $trackingNumber = null): void
    {
        $units = UnitSerialNumber::where('organization_id', $order->organization_id)
            ->where('order_id', $order->id)
            ->whereIn('status', [UnitSerialNumber::STATUS_PICKED, UnitSerialNumber::STATUS_PACKED])
            ->get();

        foreach ($units as $unit) {
            $unit->update([
                'status' => UnitSerialNumber::STATUS_SHIPPED,
                'shipped_at' => now(),
            ]);
            $unit->recordEvent(
                event: 'shipped',
                userId: $user?->id,
                reference: "Order #{$order->order_number}",
                details: [
                    'tracking_number' => $trackingNumber,
                    'customer' => $order->customer_name,
                ]
            );
            $unit->save();
        }
    }

    /**
     * Record returned unit serial numbers upon RMA receipt and inspection.
     *
     * @param  list<string>  $serialNumbers
     * @return Collection<int, UnitSerialNumber>
     */
    public function registerFromReturn(
        OrderReturn $return,
        ReturnItem $item,
        array $serialNumbers,
        string $disposition,
        User $user
    ): Collection {
        if (empty($serialNumbers)) {
            return collect();
        }

        $nextStatus = match ($disposition) {
            'sellable', 'restock' => UnitSerialNumber::STATUS_IN_STOCK,
            'damaged' => UnitSerialNumber::STATUS_DAMAGED,
            'quarantined' => UnitSerialNumber::STATUS_QUARANTINED,
            'disposed' => UnitSerialNumber::STATUS_DISPOSED,
            default => UnitSerialNumber::STATUS_RETURNED,
        };

        $cleanSerials = array_values(array_unique(array_filter(array_map('trim', $serialNumbers))));

        return DB::transaction(function () use ($return, $item, $cleanSerials, $nextStatus, $disposition, $user) {
            $updated = collect();

            foreach ($cleanSerials as $sn) {
                /** @var UnitSerialNumber|null $unit */
                $unit = UnitSerialNumber::where('organization_id', $return->organization_id)
                    ->where('serial_number', $sn)
                    ->first();

                if (! $unit) {
                    $unit = UnitSerialNumber::create([
                        'organization_id' => $return->organization_id,
                        'product_variant_id' => $item->product_variant_id,
                        'serial_number' => $sn,
                        'status' => $nextStatus,
                    ]);
                }

                $unit->update([
                    'status' => $nextStatus,
                    'order_return_id' => $return->id,
                    'return_item_id' => $item->id,
                    'warehouse_id' => $return->warehouse_id ?? $unit->warehouse_id,
                    'returned_at' => now(),
                ]);

                $unit->recordEvent(
                    event: 'returned',
                    userId: $user->id,
                    reference: "Return #{$return->return_number}",
                    details: [
                        'disposition' => $disposition,
                        'order_number' => $return->order?->order_number,
                    ]
                );
                $unit->save();

                $updated->push($unit);
            }

            return $updated;
        });
    }

    /**
     * Complete lifecycle trace of an exact unit by serial number.
     *
     * @return array<string, mixed>|null
     */
    public function trace(int $organizationId, string $serialNumber): ?array
    {
        /** @var UnitSerialNumber|null $unit */
        $unit = UnitSerialNumber::where('organization_id', $organizationId)
            ->where('serial_number', trim($serialNumber))
            ->with([
                'productVariant.product',
                'warehouse',
                'location',
                'goodsReceivedNote.purchaseOrder',
                'order.shipments',
                'orderReturn',
                'pickTask',
            ])
            ->first();

        if (! $unit) {
            return null;
        }

        return [
            'id' => $unit->id,
            'serial_number' => $unit->serial_number,
            'status' => $unit->status,
            'lot_number' => $unit->lot_number,
            'product' => [
                'name' => $unit->productVariant?->product?->name ?? 'Unknown',
                'sku' => $unit->productVariant?->sku ?? '-',
                'barcode' => $unit->productVariant?->barcode,
                'image_url' => $unit->productVariant?->product?->image_url,
            ],
            'current_location' => [
                'warehouse_id' => $unit->warehouse_id,
                'warehouse_name' => $unit->warehouse?->name,
                'location_id' => $unit->location_id,
                'location_code' => $unit->location?->code,
            ],
            'receiving' => $unit->goodsReceivedNote ? [
                'grn_id' => $unit->grn_id,
                'grn_number' => $unit->goodsReceivedNote->grn_number,
                'po_number' => $unit->goodsReceivedNote->purchaseOrder?->po_number,
                'supplier' => $unit->goodsReceivedNote->supplier_name,
                'received_at' => $unit->received_at?->toIso8601String(),
            ] : null,
            'fulfillment' => $unit->order ? [
                'order_id' => $unit->order_id,
                'order_number' => $unit->order->order_number,
                'customer_name' => $unit->order->customer_name,
                'pick_task_id' => $unit->pick_task_id,
                'shipped_at' => $unit->shipped_at?->toIso8601String(),
                'shipment' => $unit->order->shipments->first()?->only(['tracking_number', 'carrier', 'status']),
            ] : null,
            'return' => $unit->orderReturn ? [
                'return_id' => $unit->order_return_id,
                'return_number' => $unit->orderReturn->return_number,
                'disposition' => $unit->orderReturn->disposition,
                'returned_at' => $unit->returned_at?->toIso8601String(),
            ] : null,
            'timeline' => $unit->history ?? [],
            'created_at' => $unit->created_at?->toIso8601String(),
            'updated_at' => $unit->updated_at?->toIso8601String(),
        ];
    }
}
