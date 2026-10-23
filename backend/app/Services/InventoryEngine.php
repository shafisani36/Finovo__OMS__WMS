<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryLocationBalance;
use App\Models\InventoryReservation;
use App\Models\InventoryTransfer;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InventoryEngine
{
    /**
     * Get availability for a product variant at a warehouse.
     * Available = On-hand - Reserved - Damaged - Quarantined.
     */
    public function getAvailability(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        ?int $locationId = null,
        ?string $lotNumber = null
    ): array {
        if ($locationId) {
            $locQuery = InventoryLocationBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('warehouse_location_id', $locationId)
                ->where('product_variant_id', $productVariantId);

            if ($lotNumber) {
                $locQuery->where('lot_number', $lotNumber);
            }

            $locBalance = $locQuery->first();

            if (! $locBalance) {
                return [
                    'on_hand' => 0,
                    'reserved' => 0,
                    'available' => 0,
                    'damaged' => 0,
                    'quarantined' => 0,
                    'in_transit' => 0,
                ];
            }

            $avail = max(0, $locBalance->quantity - $locBalance->reserved);

            return [
                'on_hand' => $locBalance->quantity,
                'reserved' => $locBalance->reserved,
                'available' => $avail,
                'damaged' => $locBalance->condition === 'damaged' ? $locBalance->quantity : 0,
                'quarantined' => $locBalance->condition === 'quarantined' ? $locBalance->quantity : 0,
                'in_transit' => 0,
            ];
        }

        $balance = InventoryBalance::where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $productVariantId)
            ->first();

        if (! $balance) {
            return [
                'on_hand' => 0,
                'reserved' => 0,
                'available' => 0,
                'damaged' => 0,
                'quarantined' => 0,
                'in_transit' => 0,
            ];
        }

        return [
            'on_hand' => $balance->on_hand,
            'reserved' => $balance->reserved,
            'available' => $balance->available,
            'damaged' => $balance->damaged,
            'quarantined' => $balance->quarantined ?? 0,
            'in_transit' => $balance->in_transit ?? 0,
        ];
    }

    /**
     * Get or create warehouse balance row with pessimistic lock.
     */
    public function lockBalance(int $organizationId, int $warehouseId, int $productVariantId): InventoryBalance
    {
        $balance = InventoryBalance::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
            ],
            [
                'on_hand' => 0,
                'reserved' => 0,
                'available' => 0,
                'damaged' => 0,
                'in_transit' => 0,
                'quarantined' => 0,
                'picked' => 0,
            ]
        );

        return InventoryBalance::where('id', $balance->id)->lockForUpdate()->first();
    }

    /**
     * Get or create location balance row with pessimistic lock.
     */
    public function lockLocationBalance(
        int $organizationId,
        int $warehouseId,
        int $locationId,
        int $productVariantId,
        ?string $lotNumber = null,
        string $condition = 'available'
    ): InventoryLocationBalance {
        $locBalance = InventoryLocationBalance::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'lot_number' => $lotNumber,
                'condition' => $condition,
            ],
            [
                'quantity' => 0,
                'reserved' => 0,
            ]
        );

        return InventoryLocationBalance::where('id', $locBalance->id)->lockForUpdate()->first();
    }

    /**
     * Receive stock from PO, supplier or external inbound receipt.
     */
    public function receiveStock(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        ?int $locationId = null,
        ?string $lotNumber = null,
        ?string $expiryDate = null,
        string $condition = 'available',
        ?string $referenceDoc = null,
        ?int $userId = null,
        ?string $idempotencyKey = null
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Received quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $productVariantId,
            $quantity,
            $locationId,
            $lotNumber,
            $expiryDate,
            $condition,
            $referenceDoc,
            $userId,
            $idempotencyKey
        ) {
            if ($idempotencyKey) {
                $existing = InventoryLedgerEntry::where('organization_id', $organizationId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return [
                        'status' => 'already_processed',
                        'entry' => $existing,
                    ];
                }
            }

            // Lock warehouse balance
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);
            $before = $balance->on_hand;

            $balance->on_hand += $quantity;
            if ($condition === 'damaged') {
                $balance->damaged += $quantity;
            } elseif ($condition === 'quarantined') {
                $balance->quarantined = ($balance->quarantined ?? 0) + $quantity;
            } else {
                $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            }
            $balance->save();

            // Lock & update location balance if specified
            if ($locationId) {
                $locBalance = $this->lockLocationBalance($organizationId, $warehouseId, $locationId, $productVariantId, $lotNumber, $condition);
                $locBalance->quantity += $quantity;
                if ($expiryDate) {
                    $locBalance->expiry_date = $expiryDate;
                }
                $locBalance->save();
            }

            $refNum = 'REC-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -6));

            $ledger = InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'receipt',
                'quantity' => $quantity,
                'balance_before' => $before,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Receipt',
                'reference_number' => $referenceDoc ?: $refNum,
                'lot_number' => $lotNumber,
                'expiry_date' => $expiryDate,
                'inventory_status' => $condition,
                'reason' => 'Stock received from '.($referenceDoc ?: 'inbound document'),
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'success',
                'balance' => $balance,
                'ledger' => $ledger,
            ];
        });
    }

    /**
     * Putaway stock: move from receiving/staging location to storage bin location.
     */
    public function putawayStock(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $fromLocationId,
        int $toLocationId,
        int $quantity,
        ?string $lotNumber = null,
        ?int $userId = null
    ): void {
        if ($fromLocationId === $toLocationId) {
            throw new InvalidArgumentException('Source and destination locations cannot be identical.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Putaway quantity must be greater than zero.');
        }

        DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $productVariantId,
            $fromLocationId,
            $toLocationId,
            $quantity,
            $lotNumber,
            $userId
        ) {
            // Lock locations deterministically
            $firstLoc = min($fromLocationId, $toLocationId);
            $secondLoc = max($fromLocationId, $toLocationId);

            $this->lockLocationBalance($organizationId, $warehouseId, $firstLoc, $productVariantId, $lotNumber);
            $this->lockLocationBalance($organizationId, $warehouseId, $secondLoc, $productVariantId, $lotNumber);

            $sourceLocBalance = InventoryLocationBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('warehouse_location_id', $fromLocationId)
                ->where('product_variant_id', $productVariantId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if (! $sourceLocBalance || $sourceLocBalance->quantity < $quantity) {
                $avail = $sourceLocBalance ? $sourceLocBalance->quantity : 0;
                throw new RuntimeException("Insufficient stock in source location ($avail) for putaway of $quantity units.");
            }

            $destLocBalance = InventoryLocationBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('warehouse_location_id', $toLocationId)
                ->where('product_variant_id', $productVariantId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            $sourceLocBalance->quantity -= $quantity;
            $sourceLocBalance->save();

            $destLocBalance->quantity += $quantity;
            $destLocBalance->save();

            $whBalance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $fromLocationId,
                'destination_location_id' => $toLocationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'putaway',
                'quantity' => $quantity,
                'balance_before' => $whBalance->on_hand,
                'balance_after' => $whBalance->on_hand, // warehouse total does not change
                'reference_type' => 'Putaway',
                'reference_number' => 'PUT-'.strtoupper(substr(uniqid(), -6)),
                'lot_number' => $lotNumber,
                'reason' => "Putaway from location #$fromLocationId to location #$toLocationId",
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Adjust stock with transactional ledger logging.
     */
    public function adjustStock(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        string $adjustmentType, // add, subtract, recount
        int $quantity,
        string $reason,
        ?string $notes = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
        ?int $locationId = null,
        ?string $lotNumber = null
    ): StockAdjustment {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Adjustment quantity must be non-negative.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $productVariantId,
            $adjustmentType,
            $quantity,
            $reason,
            $notes,
            $userId,
            $idempotencyKey,
            $locationId,
            $lotNumber
        ) {
            if ($idempotencyKey) {
                $existing = InventoryLedgerEntry::where('organization_id', $organizationId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $adjustment = StockAdjustment::where('organization_id', $organizationId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_variant_id', $productVariantId)
                        ->latest()
                        ->first();
                    if ($adjustment) {
                        return $adjustment;
                    }
                }
            }

            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);
            $before = $balance->on_hand;

            if ($adjustmentType === 'add') {
                $after = $before + $quantity;
                $movementQty = $quantity;
            } elseif ($adjustmentType === 'subtract') {
                if ($balance->available < $quantity) {
                    throw new RuntimeException("Insufficient available stock ($balance->available) to subtract $quantity units.");
                }
                $after = $before - $quantity;
                $movementQty = -$quantity;
            } elseif ($adjustmentType === 'recount') {
                $after = $quantity;
                $movementQty = $after - $before;
                if ($after < $balance->reserved) {
                    throw new RuntimeException("New counted stock ($after) cannot be lower than currently reserved stock ($balance->reserved).");
                }
            } else {
                throw new InvalidArgumentException("Unknown adjustment type: $adjustmentType");
            }

            $balance->on_hand = $after;
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            $balance->save();

            // Location level update if location provided
            if ($locationId) {
                $locBalance = $this->lockLocationBalance($organizationId, $warehouseId, $locationId, $productVariantId, $lotNumber);
                if ($adjustmentType === 'add') {
                    $locBalance->quantity += $quantity;
                } elseif ($adjustmentType === 'subtract') {
                    $locBalance->quantity = max(0, $locBalance->quantity - $quantity);
                } elseif ($adjustmentType === 'recount') {
                    $locBalance->quantity = $quantity;
                }
                $locBalance->save();
            }

            $refNum = 'ADJ-'.strtoupper(uniqid());

            $adjustment = StockAdjustment::create([
                'organization_id' => $organizationId,
                'adjustment_number' => $refNum,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'lot_number' => $lotNumber,
                'adjustment_type' => $adjustmentType,
                'quantity' => $quantity,
                'current_quantity' => $before,
                'new_quantity' => $after,
                'status' => 'approved',
                'reason' => $reason,
                'notes' => $notes,
                'user_id' => $userId,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'adjustment',
                'quantity' => $movementQty,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'StockAdjustment',
                'reference_id' => $adjustment->id,
                'reference_number' => $refNum,
                'lot_number' => $lotNumber,
                'reason' => $reason,
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $adjustment;
        });
    }

    /**
     * Dispatch an inter-warehouse transfer.
     */
    public function dispatchTransfer(
        int $organizationId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productVariantId,
        int $quantity,
        ?int $fromLocationId = null,
        ?int $toLocationId = null,
        ?string $lotNumber = null,
        ?string $notes = null,
        ?int $userId = null
    ): InventoryTransfer {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses cannot be the same.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Transfer quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $organizationId,
            $fromWarehouseId,
            $toWarehouseId,
            $productVariantId,
            $quantity,
            $fromLocationId,
            $toLocationId,
            $lotNumber,
            $notes,
            $userId
        ) {
            // Lock balances deterministically by warehouse ID to prevent deadlocks
            $firstWh = min($fromWarehouseId, $toWarehouseId);
            $secondWh = max($fromWarehouseId, $toWarehouseId);

            $this->lockBalance($organizationId, $firstWh, $productVariantId);
            $this->lockBalance($organizationId, $secondWh, $productVariantId);

            $sourceBalance = InventoryBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('product_variant_id', $productVariantId)
                ->lockForUpdate()
                ->first();

            if ($sourceBalance->available < $quantity) {
                throw new RuntimeException("Insufficient available stock ($sourceBalance->available) at source warehouse for transfer of $quantity units.");
            }

            // Deduct on-hand, put into in_transit
            $srcBefore = $sourceBalance->on_hand;
            $sourceBalance->on_hand -= $quantity;
            $sourceBalance->in_transit = ($sourceBalance->in_transit ?? 0) + $quantity;
            $sourceBalance->available = max(0, $sourceBalance->on_hand - $sourceBalance->reserved - $sourceBalance->damaged - ($sourceBalance->quarantined ?? 0));
            $sourceBalance->save();

            // If location specified, decrement from location
            if ($fromLocationId) {
                $srcLoc = $this->lockLocationBalance($organizationId, $fromWarehouseId, $fromLocationId, $productVariantId, $lotNumber);
                $srcLoc->quantity = max(0, $srcLoc->quantity - $quantity);
                $srcLoc->save();
            }

            $transferNum = 'TRF-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));
            $refNum = 'MOV-'.strtoupper(substr(uniqid(), -6));

            $transfer = InventoryTransfer::create([
                'organization_id' => $organizationId,
                'transfer_number' => $transferNum,
                'from_warehouse_id' => $fromWarehouseId,
                'from_location_id' => $fromLocationId,
                'to_warehouse_id' => $toWarehouseId,
                'to_location_id' => $toLocationId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
                'lot_number' => $lotNumber,
                'status' => 'in_transit',
                'dispatched_at' => now(),
                'notes' => $notes,
                'user_id' => $userId,
            ]);

            // Ledger out
            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $fromWarehouseId,
                'warehouse_location_id' => $fromLocationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'transfer_out',
                'quantity' => -$quantity,
                'balance_before' => $srcBefore,
                'balance_after' => $sourceBalance->on_hand,
                'reference_type' => 'InventoryTransfer',
                'reference_id' => $transfer->id,
                'reference_number' => $refNum,
                'lot_number' => $lotNumber,
                'reason' => 'Dispatched transfer to warehouse #'.$toWarehouseId,
                'user_id' => $userId,
            ]);

            return $transfer;
        });
    }

    /**
     * Receive an in-transit transfer at the destination warehouse.
     */
    public function receiveTransfer(
        int $transferId,
        int $receivedQuantity,
        ?int $destinationLocationId = null,
        ?int $userId = null,
        ?string $discrepancyReason = null
    ): InventoryTransfer {
        return DB::transaction(function () use (
            $transferId,
            $receivedQuantity,
            $destinationLocationId,
            $userId,
            $discrepancyReason
        ) {
            $transfer = InventoryTransfer::where('id', $transferId)->lockForUpdate()->firstOrFail();

            if ($transfer->status === 'completed') {
                return $transfer;
            }

            if ($transfer->status !== 'in_transit' && $transfer->status !== 'pending') {
                throw new RuntimeException("Cannot receive transfer with status '{$transfer->status}'.");
            }

            $orgId = $transfer->organization_id;
            $srcWhId = $transfer->from_warehouse_id;
            $dstWhId = $transfer->to_warehouse_id;
            $pvId = $transfer->product_variant_id;
            $sentQty = $transfer->quantity;

            // Lock destination and source balances
            $firstWh = min($srcWhId, $dstWhId);
            $secondWh = max($srcWhId, $dstWhId);
            $this->lockBalance($orgId, $firstWh, $pvId);
            $this->lockBalance($orgId, $secondWh, $pvId);

            $sourceBalance = InventoryBalance::where('organization_id', $orgId)
                ->where('warehouse_id', $srcWhId)
                ->where('product_variant_id', $pvId)
                ->lockForUpdate()
                ->first();

            $destBalance = InventoryBalance::where('organization_id', $orgId)
                ->where('warehouse_id', $dstWhId)
                ->where('product_variant_id', $pvId)
                ->lockForUpdate()
                ->first();

            // Release in-transit from source
            $sourceBalance->in_transit = max(0, ($sourceBalance->in_transit ?? 0) - $sentQty);
            $sourceBalance->save();

            // Add accepted received quantity to destination
            $dstBefore = $destBalance->on_hand;
            $destBalance->on_hand += $receivedQuantity;
            $destBalance->available = max(0, $destBalance->on_hand - $destBalance->reserved - $destBalance->damaged - ($destBalance->quarantined ?? 0));
            $destBalance->save();

            // If destination location provided, update location balance
            $targetLocId = $destinationLocationId ?: $transfer->to_location_id;
            if ($targetLocId) {
                $destLoc = $this->lockLocationBalance($orgId, $dstWhId, $targetLocId, $pvId, $transfer->lot_number);
                $destLoc->quantity += $receivedQuantity;
                $destLoc->save();
            }

            $discrepancy = $sentQty - $receivedQuantity;

            $transfer->update([
                'status' => 'completed',
                'received_quantity' => $receivedQuantity,
                'discrepancy_quantity' => $discrepancy,
                'discrepancy_reason' => $discrepancy > 0 ? ($discrepancyReason ?: 'Short receipt') : null,
                'received_at' => now(),
                'received_by_user_id' => $userId,
            ]);

            // Ledger in for destination
            InventoryLedgerEntry::create([
                'organization_id' => $orgId,
                'warehouse_id' => $dstWhId,
                'warehouse_location_id' => $targetLocId,
                'product_variant_id' => $pvId,
                'movement_type' => 'transfer_in',
                'quantity' => $receivedQuantity,
                'balance_before' => $dstBefore,
                'balance_after' => $destBalance->on_hand,
                'reference_type' => 'InventoryTransfer',
                'reference_id' => $transfer->id,
                'reference_number' => $transfer->transfer_number,
                'lot_number' => $transfer->lot_number,
                'reason' => 'Transfer receipt from warehouse #'.$srcWhId.($discrepancy > 0 ? " (Discrepancy: -{$discrepancy})" : ''),
                'user_id' => $userId,
            ]);

            return $transfer;
        });
    }

    /**
     * Backward-compatible instant transfer for existing tests.
     */
    public function transferStock(
        int $organizationId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productVariantId,
        int $quantity,
        ?string $notes = null,
        ?int $userId = null
    ): InventoryTransfer {
        $transfer = $this->dispatchTransfer(
            organizationId: $organizationId,
            fromWarehouseId: $fromWarehouseId,
            toWarehouseId: $toWarehouseId,
            productVariantId: $productVariantId,
            quantity: $quantity,
            notes: $notes,
            userId: $userId
        );

        return $this->receiveTransfer(
            transferId: $transfer->id,
            receivedQuantity: $quantity,
            userId: $userId
        );
    }

    /**
     * Reserve stock for an order item.
     * Transactionally locks row and guarantees Available >= Quantity.
     */
    public function reserveStock(
        int $organizationId,
        int $orderId,
        int $orderItemId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        ?string $idempotencyKey = null
    ): InventoryReservation {
        return DB::transaction(function () use (
            $organizationId,
            $orderId,
            $orderItemId,
            $warehouseId,
            $productVariantId,
            $quantity,
            $idempotencyKey
        ) {
            if ($idempotencyKey) {
                $existing = InventoryReservation::where('organization_id', $organizationId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            if ($balance->available < $quantity) {
                throw new RuntimeException("Insufficient available stock ($balance->available) to reserve $quantity units.");
            }

            $balance->reserved += $quantity;
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            $balance->save();

            $reservation = InventoryReservation::create([
                'organization_id' => $organizationId,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
            ]);

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'reservation',
                'quantity' => 0, // on-hand does not change
                'balance_before' => $balance->on_hand,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Order',
                'reference_id' => $orderId,
                'reference_number' => 'RES-'.$reservation->id,
                'reason' => "Reserved $quantity units for Order #$orderId",
                'idempotency_key' => $idempotencyKey,
            ]);

            return $reservation;
        });
    }

    /**
     * Release reservation (e.g. order cancelled or reallocated).
     */
    public function releaseReservation(int $reservationId): void
    {
        DB::transaction(function () use ($reservationId) {
            $reservation = InventoryReservation::where('id', $reservationId)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'active') {
                return;
            }

            $balance = $this->lockBalance($reservation->organization_id, $reservation->warehouse_id, $reservation->product_variant_id);

            $balance->reserved = max(0, $balance->reserved - $reservation->quantity);
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            $balance->save();

            $reservation->status = 'released';
            $reservation->save();

            InventoryLedgerEntry::create([
                'organization_id' => $reservation->organization_id,
                'warehouse_id' => $reservation->warehouse_id,
                'product_variant_id' => $reservation->product_variant_id,
                'movement_type' => 'release',
                'quantity' => 0,
                'balance_before' => $balance->on_hand,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Order',
                'reference_id' => $reservation->order_id,
                'reference_number' => 'REL-'.$reservation->id,
                'reason' => "Released reservation of $reservation->quantity units for Order #$reservation->order_id",
            ]);
        });
    }

    /**
     * Deduct stock when shipped.
     */
    public function deductShipped(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        int $orderId,
        ?int $userId = null,
        ?int $locationId = null
    ): void {
        DB::transaction(function () use ($organizationId, $warehouseId, $productVariantId, $quantity, $orderId, $userId, $locationId) {
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            $before = $balance->on_hand;
            $balance->on_hand = max(0, $balance->on_hand - $quantity);
            $balance->reserved = max(0, $balance->reserved - $quantity);
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            $balance->save();

            // If location specified, decrement location balance
            if ($locationId) {
                $locBalance = InventoryLocationBalance::where('organization_id', $organizationId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('warehouse_location_id', $locationId)
                    ->where('product_variant_id', $productVariantId)
                    ->lockForUpdate()
                    ->first();
                if ($locBalance) {
                    $locBalance->quantity = max(0, $locBalance->quantity - $quantity);
                    $locBalance->save();
                }
            }

            // Mark active reservation as fulfilled
            $reservation = InventoryReservation::where('organization_id', $organizationId)
                ->where('order_id', $orderId)
                ->where('product_variant_id', $productVariantId)
                ->where('status', 'active')
                ->first();

            if ($reservation) {
                $reservation->status = 'fulfilled';
                $reservation->save();
            }

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'shipment',
                'quantity' => -$quantity,
                'balance_before' => $before,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Order',
                'reference_id' => $orderId,
                'reference_number' => 'SHP-'.$orderId,
                'reason' => "Shipped $quantity units for Order #$orderId",
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Put returned units back on hand. Sellable units become available again; damaged or quarantined units are
     * counted on hand but held out of what can be sold.
     */
    public function receiveReturn(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        string $condition, // sellable, damaged, quarantine
        int $returnId,
        string $returnNumber,
        ?int $userId = null
    ): void {
        DB::transaction(function () use ($organizationId, $warehouseId, $productVariantId, $quantity, $condition, $returnId, $returnNumber, $userId) {
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);
            $before = $balance->on_hand;

            $balance->on_hand += $quantity;
            if ($condition === 'damaged') {
                $balance->damaged += $quantity;
            } elseif ($condition === 'quarantine') {
                $balance->quarantined = ($balance->quarantined ?? 0) + $quantity;
            }
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - ($balance->quarantined ?? 0));
            $balance->save();

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'return',
                'quantity' => $quantity,
                'balance_before' => $before,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Return',
                'reference_id' => $returnId,
                'reference_number' => $returnNumber,
                'inventory_status' => $condition === 'sellable' ? 'available' : ($condition === 'damaged' ? 'damaged' : 'quarantined'),
                'reason' => "Returned $quantity units ($condition) on $returnNumber",
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Quarantine stock (e.g. damaged, suspected batch, returned for inspection).
     */
    public function quarantineStock(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        string $reason,
        ?int $locationId = null,
        ?string $lotNumber = null,
        ?int $userId = null
    ): void {
        DB::transaction(function () use ($organizationId, $warehouseId, $productVariantId, $quantity, $reason, $locationId, $lotNumber, $userId) {
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            if ($balance->available < $quantity) {
                throw new RuntimeException("Insufficient available stock ($balance->available) to quarantine $quantity units.");
            }

            $balance->quarantined = ($balance->quarantined ?? 0) + $quantity;
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - $balance->quarantined);
            $balance->save();

            if ($locationId) {
                $locBalance = $this->lockLocationBalance($organizationId, $warehouseId, $locationId, $productVariantId, $lotNumber);
                $locBalance->condition = 'quarantined';
                $locBalance->save();
            }

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'quarantine',
                'quantity' => 0,
                'balance_before' => $balance->on_hand,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'Quarantine',
                'reference_number' => 'QRN-'.strtoupper(substr(uniqid(), -6)),
                'lot_number' => $lotNumber,
                'inventory_status' => 'quarantined',
                'reason' => $reason,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Release stock from quarantine back to available.
     */
    public function releaseQuarantine(
        int $organizationId,
        int $warehouseId,
        int $productVariantId,
        int $quantity,
        string $reason,
        ?int $locationId = null,
        ?string $lotNumber = null,
        ?int $userId = null
    ): void {
        DB::transaction(function () use ($organizationId, $warehouseId, $productVariantId, $quantity, $reason, $locationId, $lotNumber, $userId) {
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            if (($balance->quarantined ?? 0) < $quantity) {
                throw new RuntimeException("Cannot release $quantity units: only {$balance->quarantined} units are quarantined.");
            }

            $balance->quarantined = max(0, ($balance->quarantined ?? 0) - $quantity);
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged - $balance->quarantined);
            $balance->save();

            if ($locationId) {
                $locBalance = $this->lockLocationBalance($organizationId, $warehouseId, $locationId, $productVariantId, $lotNumber);
                $locBalance->condition = 'available';
                $locBalance->save();
            }

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'warehouse_location_id' => $locationId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'release_quarantine',
                'quantity' => 0,
                'balance_before' => $balance->on_hand,
                'balance_after' => $balance->on_hand,
                'reference_type' => 'QuarantineRelease',
                'reference_number' => 'REL-QRN-'.strtoupper(substr(uniqid(), -6)),
                'lot_number' => $lotNumber,
                'inventory_status' => 'available',
                'reason' => $reason,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Ledger-to-Balance reconciliation verification:
     * Compares ledger entries sum against materialized on-hand balance without silently overwriting.
     */
    public function reconcileLedgerWithBalances(int $organizationId, ?int $warehouseId = null): array
    {
        $query = InventoryBalance::where('organization_id', $organizationId)->with(['variant.product', 'warehouse']);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $balances = $query->get();
        $discrepancies = [];

        foreach ($balances as $b) {
            // Calculate sum of movements that affect on-hand (receipt, shipment, adjustment, transfer_in, transfer_out)
            $ledgerNet = (int) InventoryLedgerEntry::where('organization_id', $organizationId)
                ->where('warehouse_id', $b->warehouse_id)
                ->where('product_variant_id', $b->product_variant_id)
                ->whereIn('movement_type', ['receipt', 'shipment', 'adjustment', 'transfer_in', 'transfer_out'])
                ->sum('quantity');

            $onHand = (int) $b->on_hand;

            if ($ledgerNet !== $onHand) {
                $discrepancies[] = [
                    'warehouse_id' => $b->warehouse_id,
                    'warehouse_name' => $b->warehouse->name ?? 'Unknown',
                    'product_variant_id' => $b->product_variant_id,
                    'sku' => $b->variant->sku ?? 'N/A',
                    'product_name' => $b->variant->product->name ?? 'N/A',
                    'materialized_on_hand' => $onHand,
                    'ledger_net_quantity' => $ledgerNet,
                    'variance' => $onHand - $ledgerNet,
                ];
            }
        }

        return [
            'reconciled' => count($discrepancies) === 0,
            'total_checked' => $balances->count(),
            'discrepancy_count' => count($discrepancies),
            'discrepancies' => $discrepancies,
        ];
    }
}
