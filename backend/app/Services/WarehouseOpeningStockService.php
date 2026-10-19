<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseAuditLog;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseOpeningStockService
{
    public function __construct(
        protected InventoryEngine $inventoryEngine
    ) {}

    /**
     * Validate and preview proposed opening stock records without mutating database.
     */
    public function preview(Warehouse $warehouse, array $rows): array
    {
        $previewRows = [];
        $errors = [];
        $seenKeys = [];
        $totalUnits = 0;

        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            $sku = trim((string) ($row['sku'] ?? ''));
            $locationCode = strtoupper(trim((string) ($row['location_code'] ?? '')));
            $quantity = (int) ($row['quantity'] ?? 0);
            $lotNumber = trim((string) ($row['lot_number'] ?? ''));

            $rowErrors = [];

            // 1. Validate SKU
            if (empty($sku)) {
                $rowErrors[] = 'SKU is required.';
            } else {
                $variant = ProductVariant::where('organization_id', $warehouse->organization_id)
                    ->where('sku', $sku)
                    ->with('product')
                    ->first();

                if (! $variant) {
                    $rowErrors[] = "SKU '{$sku}' not found in product catalog.";
                }
            }

            // 2. Validate Location Code
            if (empty($locationCode)) {
                $rowErrors[] = 'Location code is required.';
            } else {
                $location = WarehouseLocation::where('warehouse_id', $warehouse->id)
                    ->where('code', $locationCode)
                    ->first();

                if (! $location) {
                    $rowErrors[] = "Location '{$locationCode}' does not exist in this warehouse.";
                } elseif (! $location->is_active) {
                    $rowErrors[] = "Location '{$locationCode}' is inactive.";
                } elseif (! $location->putaway_eligible) {
                    $rowErrors[] = "Location '{$locationCode}' is not marked as putaway-eligible.";
                }
            }

            // 3. Validate Quantity
            if ($quantity <= 0) {
                $rowErrors[] = 'Quantity must be greater than zero.';
            }

            // 4. Duplicate row detection in same batch
            $uniqueKey = "{$sku}|{$locationCode}|{$lotNumber}";
            if (isset($seenKeys[$uniqueKey])) {
                $rowErrors[] = "Duplicate entry for SKU '{$sku}' at location '{$locationCode}' (first seen on row {$seenKeys[$uniqueKey]}).";
            } else {
                $seenKeys[$uniqueKey] = $rowNum;
            }

            if (! empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNum,
                    'sku' => $sku,
                    'errors' => $rowErrors,
                ];
            } else {
                $totalUnits += $quantity;
            }

            $previewRows[] = [
                'row' => $rowNum,
                'sku' => $sku,
                'product_name' => isset($variant) ? $variant->product->name : null,
                'variant_title' => isset($variant) ? $variant->title : null,
                'location_code' => $locationCode,
                'quantity' => $quantity,
                'lot_number' => $lotNumber ?: null,
                'expiry_date' => $row['expiry_date'] ?? null,
                'is_valid' => empty($rowErrors),
                'validation_errors' => $rowErrors,
            ];
        }

        return [
            'can_commit' => empty($errors) && count($previewRows) > 0,
            'total_rows' => count($previewRows),
            'valid_rows' => count($previewRows) - count($errors),
            'error_count' => count($errors),
            'total_units' => $totalUnits,
            'errors' => $errors,
            'items' => $previewRows,
        ];
    }

    /**
     * Atomically commit opening stock into the authoritative InventoryEngine.
     */
    public function commit(Warehouse $warehouse, array $rows, int $userId, ?string $idempotencyKey = null): array
    {
        $preview = $this->preview($warehouse, $rows);

        if (! $preview['can_commit']) {
            throw ValidationException::withMessages([
                'opening_stock' => ['Cannot commit opening stock due to validation errors. Review the preview report.'],
            ]);
        }

        if ($warehouse->isClosed()) {
            throw ValidationException::withMessages([
                'warehouse' => ['Cannot initialize opening stock in a closed warehouse.'],
            ]);
        }

        return DB::transaction(function () use ($warehouse, $rows, $userId, $idempotencyKey) {
            $committedLines = 0;
            $totalUnits = 0;

            foreach ($rows as $index => $row) {
                $sku = trim((string) $row['sku']);
                $quantity = (int) $row['quantity'];
                $notes = 'Opening Stock: '.($row['location_code'] ?? 'Default');
                if (! empty($row['lot_number'])) {
                    $notes .= " | Lot: {$row['lot_number']}";
                }

                $variant = ProductVariant::where('organization_id', $warehouse->organization_id)
                    ->where('sku', $sku)
                    ->firstOrFail();

                $lineIdempotency = $idempotencyKey ? "{$idempotencyKey}-line-{$index}" : null;

                $this->inventoryEngine->adjustStock(
                    organizationId: $warehouse->organization_id,
                    warehouseId: $warehouse->id,
                    productVariantId: $variant->id,
                    adjustmentType: 'add',
                    quantity: $quantity,
                    reason: 'opening_stock',
                    notes: $notes,
                    userId: $userId,
                    idempotencyKey: $lineIdempotency
                );

                $committedLines++;
                $totalUnits += $quantity;
            }

            WarehouseAuditLog::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId,
                'action' => 'opening_stock_initialized',
                'description' => "Opening stock initialized: {$committedLines} lines, {$totalUnits} total units.",
                'after_state' => [
                    'lines_count' => $committedLines,
                    'total_units' => $totalUnits,
                ],
            ]);

            return [
                'success' => true,
                'committed_lines' => $committedLines,
                'total_units' => $totalUnits,
            ];
        });
    }
}
