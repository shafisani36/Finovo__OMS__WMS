<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseAuditLog;
use App\Models\WarehouseMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseService
{
    public function __construct(
        protected WarehouseReadinessService $readinessService,
        protected WarehouseLayoutService $layoutService
    ) {}

    /**
     * List warehouses for organization with search, status filters, and aggregated KPIs.
     */
    public function listWarehouses(int $organizationId, array $filters = [], int $perPage = 10): array
    {
        $query = Warehouse::where('organization_id', $organizationId)
            ->withCount(['locations', 'balances', 'memberships'])
            ->with(['parentWarehouse:id,name,code']);

        // Search by name, code, city, country
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%");
            });
        }

        // Status filter
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        // Type filter
        if (! empty($filters['warehouse_type']) && $filters['warehouse_type'] !== 'all') {
            $query->where('warehouse_type', $filters['warehouse_type']);
        }

        // Sort
        $sortField = $filters['sort_by'] ?? 'priority';
        $sortDir = strtolower($filters['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['name', 'code', 'priority', 'status', 'created_at'];
        if (in_array($sortField, $allowedSorts, true)) {
            $query->orderBy($sortField, $sortDir);
        } else {
            $query->orderBy('priority', 'asc');
        }

        /** @var LengthAwarePaginator $paginated */
        $paginated = $query->paginate($perPage);

        // Aggregated KPIs
        $all = Warehouse::where('organization_id', $organizationId)->get();
        $total = $all->count();
        $active = $all->where('status', Warehouse::STATUS_ACTIVE)->count();
        $draft = $all->whereIn('status', [Warehouse::STATUS_DRAFT, Warehouse::STATUS_SETUP_INCOMPLETE])->count();
        $ready = $all->where('status', Warehouse::STATUS_READY_FOR_ACTIVATION)->count();

        return [
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'kpis' => [
                'total_warehouses' => $total,
                'active_warehouses' => $active,
                'draft_warehouses' => $draft,
                'ready_warehouses' => $ready,
            ],
        ];
    }

    /**
     * Create a new warehouse record with transactional safety and audit tracking.
     */
    public function createWarehouse(Organization $org, int $userId, array $data): Warehouse
    {
        $code = strtoupper(trim($data['code'] ?? ''));

        // Normalization fallback if code empty
        if (empty($code)) {
            $base = preg_replace('/[^A-Za-z0-9]/', '', $data['name'] ?? 'WH');
            $code = strtoupper(substr($base, 0, 4).'-'.rand(100, 999));
        }

        // Enforce uniqueness within organization
        $existing = Warehouse::where('organization_id', $org->id)
            ->where('code', $code)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'code' => ["Warehouse code '{$code}' already exists in your organization."],
            ]);
        }

        return DB::transaction(function () use ($org, $userId, $data, $code) {
            // Default policies if none provided
            $defaultInventoryPolicies = [
                'allow_negative_inventory' => false,
                'picking_strategy' => 'FIFO', // FIFO or FEFO
                'track_lots' => false,
                'track_serials' => false,
                'track_expiry' => false,
                'require_adjustment_approval' => true,
                'cycle_count_interval_days' => 30,
            ];

            $defaultInboundPolicies = [
                'require_purchase_order' => false,
                'allow_over_receipt' => false,
                'putaway_strategy' => 'fixed_zone',
                'require_qc_inspection' => false,
            ];

            $defaultOutboundPolicies = [
                'picking_method' => 'single_order', // single_order, batch, wave, zone
                'pack_verification_required' => true,
                'allow_partial_fulfillment' => true,
                'allow_backorders' => false,
            ];

            $status = $data['status'] ?? Warehouse::STATUS_DRAFT;

            $warehouse = Warehouse::create([
                'organization_id' => $org->id,
                'name' => trim($data['name']),
                'code' => $code,
                'warehouse_type' => $data['warehouse_type'] ?? Warehouse::TYPE_FULFILLMENT_CENTER,
                'description' => $data['description'] ?? null,
                'status' => $status,
                'is_active' => $status === Warehouse::STATUS_ACTIVE,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'priority' => (int) ($data['priority'] ?? 0),
                'external_reference' => $data['external_reference'] ?? null,
                'parent_warehouse_id' => $data['parent_warehouse_id'] ?? null,
                'address' => $data['address'] ?? null,
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'country' => $data['country'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'contact_name' => $data['contact_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'operating_hours' => $data['operating_hours'] ?? null,
                'inventory_policies' => array_merge($defaultInventoryPolicies, $data['inventory_policies'] ?? []),
                'inbound_policies' => array_merge($defaultInboundPolicies, $data['inbound_policies'] ?? []),
                'outbound_policies' => array_merge($defaultOutboundPolicies, $data['outbound_policies'] ?? []),
                'integration_settings' => $data['integration_settings'] ?? null,
            ]);

            // If starter layout requested at creation time
            if (! empty($data['create_starter_layout'])) {
                $this->layoutService->generateStarterLayout(
                    warehouse: $warehouse,
                    config: $data['starter_layout_config'] ?? [],
                    previewOnly: false,
                    userId: $userId
                );
            }

            // Record audit log
            WarehouseAuditLog::create([
                'organization_id' => $org->id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $userId,
                'action' => 'created',
                'description' => "Warehouse [{$warehouse->name}] ({$warehouse->code}) created with status '{$warehouse->status}'.",
                'after_state' => $warehouse->toArray(),
            ]);

            return $warehouse;
        });
    }

    /**
     * Update warehouse settings with audit history and readiness re-evaluation.
     */
    public function updateWarehouse(Warehouse $warehouse, int $userId, array $data): Warehouse
    {
        $before = $warehouse->toArray();

        // Validate code uniqueness if changing
        if (isset($data['code'])) {
            $code = strtoupper(trim($data['code']));
            $existing = Warehouse::where('organization_id', $warehouse->organization_id)
                ->where('code', $code)
                ->where('id', '!=', $warehouse->id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'code' => ["Warehouse code '{$code}' already exists in your organization."],
                ]);
            }
            $warehouse->code = $code;
        }

        $fields = [
            'name', 'warehouse_type', 'description', 'priority', 'is_default',
            'external_reference', 'parent_warehouse_id', 'address', 'address_line_2',
            'city', 'state', 'country', 'postal_code', 'timezone',
            'contact_name', 'contact_email', 'contact_phone', 'latitude', 'longitude',
            'operating_hours', 'inventory_policies', 'inbound_policies', 'outbound_policies',
            'integration_settings',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $warehouse->{$field} = $data[$field];
            }
        }

        // If status was draft/setup_incomplete, recheck readiness
        if (in_array($warehouse->status, [Warehouse::STATUS_DRAFT, Warehouse::STATUS_SETUP_INCOMPLETE], true)) {
            $readiness = $this->readinessService->evaluate($warehouse);
            if ($readiness['is_ready']) {
                $warehouse->status = Warehouse::STATUS_READY_FOR_ACTIVATION;
            } else {
                $warehouse->status = Warehouse::STATUS_SETUP_INCOMPLETE;
            }
        }

        $warehouse->save();

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'updated',
            'description' => "Warehouse [{$warehouse->name}] configuration updated.",
            'before_state' => $before,
            'after_state' => $warehouse->toArray(),
        ]);

        return $warehouse;
    }

    /**
     * Authoritative activation — validates all prerequisites via WarehouseReadinessService.
     */
    public function activateWarehouse(Warehouse $warehouse, int $userId): array
    {
        $readiness = $this->readinessService->evaluate($warehouse);

        if (! $readiness['is_ready']) {
            throw ValidationException::withMessages([
                'readiness' => ['Cannot activate warehouse: critical configuration requirements are incomplete.'],
            ])->status(422);
        }

        $before = $warehouse->toArray();

        $warehouse->status = Warehouse::STATUS_ACTIVE;
        $warehouse->is_active = true;
        $warehouse->save();

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'activated',
            'description' => "Warehouse [{$warehouse->name}] activated and operational.",
            'before_state' => $before,
            'after_state' => $warehouse->toArray(),
        ]);

        return [
            'success' => true,
            'message' => "Warehouse '{$warehouse->name}' successfully activated.",
            'warehouse' => $warehouse,
        ];
    }

    /**
     * Safe deactivation — prevents new operations without deleting existing stock or records.
     */
    public function deactivateWarehouse(Warehouse $warehouse, int $userId, ?string $reason = null): array
    {
        $before = $warehouse->toArray();

        $warehouse->status = Warehouse::STATUS_INACTIVE;
        $warehouse->is_active = false;
        $warehouse->save();

        $desc = "Warehouse [{$warehouse->name}] set to inactive.";
        if ($reason) {
            $desc .= " Reason: {$reason}";
        }

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'deactivated',
            'description' => $desc,
            'before_state' => $before,
            'after_state' => $warehouse->toArray(),
        ]);

        return [
            'success' => true,
            'message' => "Warehouse '{$warehouse->name}' deactivated.",
            'warehouse' => $warehouse,
        ];
    }

    /**
     * Initiate controlled warehouse closure review.
     */
    public function beginClosure(Warehouse $warehouse, int $userId, ?string $reason = null): array
    {
        $before = $warehouse->toArray();

        $warehouse->status = Warehouse::STATUS_CLOSING;
        $warehouse->is_active = false;
        $warehouse->save();

        $balancesCount = $warehouse->balances()->where('on_hand', '>', 0)->count();

        $desc = "Warehouse [{$warehouse->name}] entered closing state. Outstanding positive balances: {$balancesCount}.";
        if ($reason) {
            $desc .= " Reason: {$reason}";
        }

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'action' => 'closure_initiated',
            'description' => $desc,
            'before_state' => $before,
            'after_state' => $warehouse->toArray(),
        ]);

        return [
            'success' => true,
            'message' => "Warehouse '{$warehouse->name}' closure process initiated.",
            'outstanding_stock_lines' => $balancesCount,
            'warehouse' => $warehouse,
        ];
    }

    /**
     * Assign staff member to warehouse.
     */
    public function assignStaff(Warehouse $warehouse, array $data, int $assignedByUserId): WarehouseMembership
    {
        $userId = (int) $data['user_id'];
        $user = User::findOrFail($userId);

        // Verify user belongs to same organization
        $isMember = $user->memberships()->where('organization_id', $warehouse->organization_id)->exists();
        if (! $isMember && $user->current_organization_id !== $warehouse->organization_id) {
            throw ValidationException::withMessages([
                'user_id' => ['Selected user does not belong to your organization.'],
            ]);
        }

        // Prevent duplicate active assignment
        $existing = WarehouseMembership::where('warehouse_id', $warehouse->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->update([
                'role' => $data['role'] ?? 'operator',
                'status' => 'active',
                'permissions' => $data['permissions'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            WarehouseAuditLog::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'user_id' => $assignedByUserId,
                'action' => 'staff_updated',
                'description' => "Updated staff assignment for {$user->name} ({$user->email}) to role '{$existing->role}'.",
            ]);

            return $existing;
        }

        $membership = WarehouseMembership::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $userId,
            'role' => $data['role'] ?? 'operator',
            'status' => 'active',
            'permissions' => $data['permissions'] ?? null,
            'assigned_at' => now(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $assignedByUserId,
            'action' => 'staff_assigned',
            'description' => "Assigned {$user->name} ({$user->email}) to warehouse as '{$membership->role}'.",
        ]);

        return $membership;
    }

    /**
     * Remove staff member from warehouse.
     */
    public function removeStaff(Warehouse $warehouse, int $membershipId, int $removedByUserId): void
    {
        $membership = WarehouseMembership::where('warehouse_id', $warehouse->id)->findOrFail($membershipId);
        $user = $membership->user;

        $membership->delete();

        WarehouseAuditLog::create([
            'organization_id' => $warehouse->organization_id,
            'warehouse_id' => $warehouse->id,
            'user_id' => $removedByUserId,
            'action' => 'staff_removed',
            'description' => "Removed staff assignment for {$user?->name} ({$user?->email}).",
        ]);
    }
}
