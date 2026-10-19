<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\Warehouse;
use App\Models\WarehouseAuditLog;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseLayoutService
{
    /**
     * Create a physical warehouse location.
     */
    public function createLocation(Warehouse $warehouse, array $data, ?int $userId = null): WarehouseLocation
    {
        $code = strtoupper(trim($data['code']));

        // 1. Enforce uniqueness within warehouse
        $existing = WarehouseLocation::where('warehouse_id', $warehouse->id)
            ->where('code', $code)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'code' => ["Location code '{$code}' already exists in this warehouse."],
            ]);
        }

        // 2. Validate parent hierarchy if provided
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = WarehouseLocation::where('warehouse_id', $warehouse->id)->find($parentId);
            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['The specified parent location does not belong to this warehouse.'],
                ]);
            }
        }

        return DB::transaction(function () use ($warehouse, $data, $code, $parentId, $userId) {
            $location = WarehouseLocation::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'parent_id' => $parentId,
                'code' => $code,
                'location_type' => $data['location_type'] ?? 'bin',
                'barcode' => $data['barcode'] ?? "BC-{$code}",
                'aisle' => $data['aisle'] ?? null,
                'rack' => $data['rack'] ?? null,
                'shelf' => $data['shelf'] ?? null,
                'bin' => $data['bin'] ?? null,
                'picking_eligible' => $data['picking_eligible'] ?? true,
                'putaway_eligible' => $data['putaway_eligible'] ?? true,
                'capacity' => $data['capacity'] ?? null,
                'capacity_unit' => $data['capacity_unit'] ?? 'units',
                'weight_capacity' => $data['weight_capacity'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            WarehouseAuditLog::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId,
                'action' => 'location_created',
                'description' => "Location [{$location->code}] created (Type: {$location->location_type}).",
                'after_state' => $location->toArray(),
            ]);

            return $location;
        });
    }

    /**
     * Update an existing warehouse location.
     */
    public function updateLocation(WarehouseLocation $location, array $data, ?int $userId = null): WarehouseLocation
    {
        $warehouse = $location->warehouse;

        if (isset($data['code'])) {
            $code = strtoupper(trim($data['code']));
            $existing = WarehouseLocation::where('warehouse_id', $warehouse->id)
                ->where('code', $code)
                ->where('id', '!=', $location->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'code' => ["Location code '{$code}' already in use by another location."],
                ]);
            }
            $location->code = $code;
        }

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'];
            if ($parentId == $location->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A location cannot be its own parent.'],
                ]);
            }

            if ($parentId) {
                $parent = WarehouseLocation::where('warehouse_id', $warehouse->id)->find($parentId);
                if (! $parent) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Parent location does not belong to this warehouse.'],
                    ]);
                }

                // Prevent circular reference
                if ($this->isDescendant($location, $parent)) {
                    throw ValidationException::withMessages([
                        'parent_id' => ['Circular reference detected: cannot assign a child location as parent.'],
                    ]);
                }
            }
            $location->parent_id = $parentId;
        }

        $fields = [
            'location_type', 'barcode', 'aisle', 'rack', 'shelf', 'bin',
            'picking_eligible', 'putaway_eligible', 'capacity', 'capacity_unit',
            'weight_capacity', 'sort_order', 'is_active',
        ];

        $beforeState = $location->toArray();

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $location->{$field} = $data[$field];
            }
        }

        $location->save();

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'location_updated',
            'description' => "Location [{$location->code}] updated.",
            'before_state' => $beforeState,
            'after_state' => $location->toArray(),
        ]);

        return $location;
    }

    /**
     * Delete location safely — blocked if location contains stock or child locations.
     */
    public function deleteLocation(WarehouseLocation $location, ?int $userId = null): void
    {
        $warehouse = $location->warehouse;

        // 1. Prevent deletion if child locations exist
        $hasChildren = WarehouseLocation::where('parent_id', $location->id)->exists();
        if ($hasChildren) {
            throw ValidationException::withMessages([
                'location' => ["Cannot delete location '{$location->code}' because it contains child sub-locations. Delete child locations first."],
            ]);
        }

        // 2. Prevent deletion if active stock exists
        $hasStock = InventoryBalance::where('warehouse_id', $warehouse->id)
            ->where('on_hand', '>', 0)
            ->exists();

        // If warehouse has balances, check location-specific references where applicable
        if ($hasStock && $location->picking_eligible) {
            // Check if this is the only picking location
            $pickingCount = WarehouseLocation::where('warehouse_id', $warehouse->id)
                ->where('picking_eligible', true)
                ->count();
            if ($pickingCount <= 1) {
                throw ValidationException::withMessages([
                    'location' => ["Cannot delete '{$location->code}': warehouse has on-hand inventory and requires at least one active picking location."],
                ]);
            }
        }

        $code = $location->code;
        $before = $location->toArray();

        $location->delete();

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'location_deleted',
            'description' => "Location [{$code}] was deleted.",
            'before_state' => $before,
        ]);
    }

    /**
     * Generate or preview an enterprise standard starter layout:
     * Zones -> Aisles -> Racks -> Shelves -> Bins
     */
    public function generateStarterLayout(Warehouse $warehouse, array $config, bool $previewOnly = false, ?int $userId = null): array
    {
        $aislesCount = max(1, min(10, (int) ($config['aisles_count'] ?? 2)));
        $racksCount = max(1, min(10, (int) ($config['racks_per_aisle'] ?? 2)));
        $shelvesCount = max(1, min(5, (int) ($config['shelves_per_rack'] ?? 2)));
        $binsCount = max(1, min(10, (int) ($config['bins_per_shelf'] ?? 3)));

        $zones = [
            ['code' => 'Z-RCV', 'name' => 'Receiving Staging Zone', 'type' => 'receiving', 'picking' => false, 'putaway' => true],
            ['code' => 'Z-PICK', 'name' => 'Forward Picking Zone', 'type' => 'picking', 'picking' => true, 'putaway' => true],
            ['code' => 'Z-BULK', 'name' => 'Bulk Pallet Storage', 'type' => 'bulk_storage', 'picking' => false, 'putaway' => true],
            ['code' => 'Z-PACK', 'name' => 'Packing & QA Station', 'type' => 'packing', 'picking' => false, 'putaway' => false],
            ['code' => 'Z-DISP', 'name' => 'Dispatch & Outbound Dock', 'type' => 'dispatch', 'picking' => false, 'putaway' => false],
            ['code' => 'Z-RET', 'name' => 'Returns & Quarantine', 'type' => 'quarantine', 'picking' => false, 'putaway' => true],
        ];

        $generated = [];

        // Build zones
        foreach ($zones as $z) {
            $generated[] = [
                'code' => $z['code'],
                'location_type' => $z['type'],
                'parent_code' => null,
                'barcode' => "BC-{$z['code']}",
                'picking_eligible' => $z['picking'],
                'putaway_eligible' => $z['putaway'],
                'capacity' => 1000,
                'capacity_unit' => 'units',
            ];
        }

        // Build hierarchical picking aisles, racks, shelves, bins inside Z-PICK
        for ($a = 1; $a <= $aislesCount; $a++) {
            $aisleCode = sprintf('A%02d', $a);
            $generated[] = [
                'code' => $aisleCode,
                'location_type' => 'aisle',
                'parent_code' => 'Z-PICK',
                'barcode' => "BC-{$aisleCode}",
                'picking_eligible' => true,
                'putaway_eligible' => true,
                'aisle' => (string) $a,
            ];

            for ($r = 1; $r <= $racksCount; $r++) {
                $rackCode = sprintf('%s-R%02d', $aisleCode, $r);
                $generated[] = [
                    'code' => $rackCode,
                    'location_type' => 'rack',
                    'parent_code' => $aisleCode,
                    'barcode' => "BC-{$rackCode}",
                    'picking_eligible' => true,
                    'putaway_eligible' => true,
                    'aisle' => (string) $a,
                    'rack' => (string) $r,
                ];

                for ($s = 1; $s <= $shelvesCount; $s++) {
                    $shelfCode = sprintf('%s-S%d', $rackCode, $s);
                    $generated[] = [
                        'code' => $shelfCode,
                        'location_type' => 'shelf',
                        'parent_code' => $rackCode,
                        'barcode' => "BC-{$shelfCode}",
                        'picking_eligible' => true,
                        'putaway_eligible' => true,
                        'aisle' => (string) $a,
                        'rack' => (string) $r,
                        'shelf' => (string) $s,
                    ];

                    for ($b = 1; $b <= $binsCount; $b++) {
                        $binCode = sprintf('%s-B%02d', $shelfCode, $b);
                        $generated[] = [
                            'code' => $binCode,
                            'location_type' => 'bin',
                            'parent_code' => $shelfCode,
                            'barcode' => "BC-{$binCode}",
                            'picking_eligible' => true,
                            'putaway_eligible' => true,
                            'aisle' => (string) $a,
                            'rack' => (string) $r,
                            'shelf' => (string) $s,
                            'bin' => (string) $b,
                            'capacity' => 150,
                            'capacity_unit' => 'units',
                        ];
                    }
                }
            }
        }

        if ($previewOnly) {
            return [
                'preview' => true,
                'total_locations' => count($generated),
                'zones_count' => count($zones),
                'bins_count' => $aislesCount * $racksCount * $shelvesCount * $binsCount,
                'items' => $generated,
            ];
        }

        // Commit directly into database
        return DB::transaction(function () use ($warehouse, $generated, $userId) {
            $createdMap = [];
            $createdCount = 0;

            foreach ($generated as $locData) {
                // Check if already exists
                $existing = WarehouseLocation::where('warehouse_id', $warehouse->id)
                    ->where('code', $locData['code'])
                    ->first();

                if ($existing) {
                    $createdMap[$locData['code']] = $existing->id;

                    continue;
                }

                $parentId = null;
                if (! empty($locData['parent_code']) && isset($createdMap[$locData['parent_code']])) {
                    $parentId = $createdMap[$locData['parent_code']];
                }

                $loc = WarehouseLocation::create([
                    'organization_id' => $warehouse->organization_id,
                    'warehouse_id' => $warehouse->id,
                    'parent_id' => $parentId,
                    'code' => $locData['code'],
                    'location_type' => $locData['location_type'],
                    'barcode' => $locData['barcode'],
                    'aisle' => $locData['aisle'] ?? null,
                    'rack' => $locData['rack'] ?? null,
                    'shelf' => $locData['shelf'] ?? null,
                    'bin' => $locData['bin'] ?? null,
                    'picking_eligible' => $locData['picking_eligible'] ?? true,
                    'putaway_eligible' => $locData['putaway_eligible'] ?? true,
                    'capacity' => $locData['capacity'] ?? null,
                    'capacity_unit' => $locData['capacity_unit'] ?? 'units',
                    'sort_order' => $createdCount++,
                    'is_active' => true,
                ]);

                $createdMap[$locData['code']] = $loc->id;
            }

            WarehouseAuditLog::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId,
                'action' => 'starter_layout_generated',
                'description' => "Starter physical layout created with {$createdCount} new locations.",
            ]);

            return [
                'preview' => false,
                'created_count' => $createdCount,
                'total_locations' => WarehouseLocation::where('warehouse_id', $warehouse->id)->count(),
            ];
        });
    }

    /**
     * Check if potentialParent is already a child/descendant of location.
     */
    private function isDescendant(WarehouseLocation $location, WarehouseLocation $potentialParent): bool
    {
        $current = $potentialParent;
        while ($current->parent_id) {
            if ($current->parent_id == $location->id) {
                return true;
            }
            $current = WarehouseLocation::find($current->parent_id);
            if (! $current) {
                break;
            }
        }

        return false;
    }
}
