<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedgerEntry;
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
     */
    public function getAvailability(int $organizationId, int $warehouseId, int $productVariantId): array
    {
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
            ];
        }

        return [
            'on_hand' => $balance->on_hand,
            'reserved' => $balance->reserved,
            'available' => $balance->available,
            'damaged' => $balance->damaged,
        ];
    }

    /**
     * Get or create balance row with pessimistic lock.
     */
    public function lockBalance(int $organizationId, int $warehouseId, int $productVariantId): InventoryBalance
    {
        // First ensure record exists
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
            ]
        );

        // Pessimistic lock
        return InventoryBalance::where('id', $balance->id)->lockForUpdate()->first();
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
        ?string $idempotencyKey = null
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
            $idempotencyKey
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
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged);
            $balance->save();

            $refNum = 'ADJ-'.strtoupper(uniqid());

            $adjustment = StockAdjustment::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
                'adjustment_type' => $adjustmentType,
                'quantity' => $quantity,
                'reason' => $reason,
                'notes' => $notes,
                'user_id' => $userId,
            ]);

            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'adjustment',
                'quantity' => $movementQty,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'StockAdjustment',
                'reference_id' => $adjustment->id,
                'reference_number' => $refNum,
                'reason' => $reason,
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $adjustment;
        });
    }

    /**
     * Transfer stock between warehouses deterministically.
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
            $notes,
            $userId
        ) {
            // Deterministic lock order by warehouse ID to prevent deadlocks
            $firstWh = min($fromWarehouseId, $toWarehouseId);
            $secondWh = max($fromWarehouseId, $toWarehouseId);

            $this->lockBalance($organizationId, $firstWh, $productVariantId);
            $this->lockBalance($organizationId, $secondWh, $productVariantId);

            $sourceBalance = InventoryBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('product_variant_id', $productVariantId)
                ->lockForUpdate()
                ->first();

            $destBalance = InventoryBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $toWarehouseId)
                ->where('product_variant_id', $productVariantId)
                ->lockForUpdate()
                ->first();

            if ($sourceBalance->available < $quantity) {
                throw new RuntimeException("Insufficient available stock ($sourceBalance->available) at source warehouse for transfer of $quantity units.");
            }

            // Deduct from source
            $srcBefore = $sourceBalance->on_hand;
            $sourceBalance->on_hand -= $quantity;
            $sourceBalance->available = max(0, $sourceBalance->on_hand - $sourceBalance->reserved - $sourceBalance->damaged);
            $sourceBalance->save();

            // Add to destination
            $dstBefore = $destBalance->on_hand;
            $destBalance->on_hand += $quantity;
            $destBalance->available = max(0, $destBalance->on_hand - $destBalance->reserved - $destBalance->damaged);
            $destBalance->save();

            $transferNum = 'TRF-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5));
            $refNum = 'MOV-'.strtoupper(substr(uniqid(), -6));

            $transfer = InventoryTransfer::create([
                'organization_id' => $organizationId,
                'transfer_number' => $transferNum,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
                'status' => 'completed',
                'notes' => $notes,
                'user_id' => $userId,
            ]);

            // Ledger out
            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $fromWarehouseId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'transfer_out',
                'quantity' => -$quantity,
                'balance_before' => $srcBefore,
                'balance_after' => $sourceBalance->on_hand,
                'reference_type' => 'InventoryTransfer',
                'reference_id' => $transfer->id,
                'reference_number' => $refNum,
                'reason' => 'Transfer to warehouse #'.$toWarehouseId,
                'user_id' => $userId,
            ]);

            // Ledger in
            InventoryLedgerEntry::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $toWarehouseId,
                'product_variant_id' => $productVariantId,
                'movement_type' => 'transfer_in',
                'quantity' => $quantity,
                'balance_before' => $dstBefore,
                'balance_after' => $destBalance->on_hand,
                'reference_type' => 'InventoryTransfer',
                'reference_id' => $transfer->id,
                'reference_number' => $refNum,
                'reason' => 'Transfer from warehouse #'.$fromWarehouseId,
                'user_id' => $userId,
            ]);

            return $transfer;
        });
    }

    /**
     * Reserve stock for an order item.
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
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged);
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
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged);
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
        ?int $userId = null
    ): void {
        DB::transaction(function () use ($organizationId, $warehouseId, $productVariantId, $quantity, $orderId, $userId) {
            $balance = $this->lockBalance($organizationId, $warehouseId, $productVariantId);

            $before = $balance->on_hand;
            $balance->on_hand = max(0, $balance->on_hand - $quantity);
            $balance->reserved = max(0, $balance->reserved - $quantity);
            $balance->available = max(0, $balance->on_hand - $balance->reserved - $balance->damaged);
            $balance->save();

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
}
