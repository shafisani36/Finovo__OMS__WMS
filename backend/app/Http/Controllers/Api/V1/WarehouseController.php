<?php

namespace App\Services;

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\EmailService;
use App\Services\WarehouseLayoutService;
use App\Services\WarehouseOpeningStockService;
use App\Services\WarehouseReadinessService;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService,
        protected WarehouseReadinessService $readinessService,
        protected WarehouseLayoutService $layoutService,
        protected WarehouseOpeningStockService $openingStockService,
        protected EmailService $emails
    ) {}

    /**
     * List all warehouses with search, filters, pagination, and KPIs.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse_type' => $request->query('warehouse_type'),
            'sort_by' => $request->query('sort_by'),
            'sort_dir' => $request->query('sort_dir'),
        ];

        $perPage = (int) $request->query('per_page', 10);
        $result = $this->warehouseService->listWarehouses($org->id, $filters, $perPage);

        return response()->json([
            'status' => 'success',
            'data' => $result['data'],
            'meta' => $result['meta'],
            'kpis' => $result['kpis'],
        ]);
    }

    /**
     * Get lookup metadata for warehouse creation (types, timezones, default policies).
     */
    public function metadata(Request $request): JsonResponse
    {
        $types = [
            ['id' => Warehouse::TYPE_DISTRIBUTION_CENTER, 'label' => 'Distribution Center', 'description' => 'Large regional storage and distribution hub'],
            ['id' => Warehouse::TYPE_FULFILLMENT_CENTER, 'label' => 'Fulfillment Center', 'description' => 'High-velocity e-commerce picking and packing facility'],
            ['id' => Warehouse::TYPE_STORAGE, 'label' => 'Storage Warehouse', 'description' => 'Long-term bulk inventory holding depot'],
            ['id' => Warehouse::TYPE_RETAIL_STORE, 'label' => 'Retail Store / Showroom', 'description' => 'Customer-facing store with backroom storage'],
            ['id' => Warehouse::TYPE_MANUFACTURING, 'label' => 'Manufacturing Facility', 'description' => 'Raw materials and production staging plant'],
            ['id' => Warehouse::TYPE_CROSS_DOCK, 'label' => 'Cross-Docking Facility', 'description' => 'Rapid inbound-to-outbound transfer facility'],
            ['id' => Warehouse::TYPE_3PL, 'label' => 'Third-Party Logistics (3PL)', 'description' => 'Partner-managed external fulfillment network'],
            ['id' => Warehouse::TYPE_QUARANTINE, 'label' => 'Returns & Quarantine', 'description' => 'Inspection, quarantine, and RMA returns triage'],
        ];

        $timezones = array_slice(timezone_identifiers_list(), 0, 150);

        return response()->json([
            'status' => 'success',
            'data' => [
                'warehouse_types' => $types,
                'timezones' => $timezones,
                'default_timezone' => 'UTC',
            ],
        ]);
    }

    /**
     * Create a new warehouse through the wizard or quick creation.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'warehouse_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'in:draft,setup_incomplete,active'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'parent_warehouse_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'operating_hours' => ['nullable', 'array'],
            'inventory_policies' => ['nullable', 'array'],
            'inbound_policies' => ['nullable', 'array'],
            'outbound_policies' => ['nullable', 'array'],
            'integration_settings' => ['nullable', 'array'],
            'create_starter_layout' => ['nullable', 'boolean'],
            'starter_layout_config' => ['nullable', 'array'],
        ]);

        $warehouse = $this->warehouseService->createWarehouse($org, $request->user()->id, $validated);

        $this->emails->sendActivityEmail(EmailService::WAREHOUSE_CREATED, $org, [
            'name' => $warehouse->name,
            'actor' => $request->user(),
            'details' => [
                'Code' => $warehouse->code,
                'Type' => $warehouse->warehouse_type,
                'Location' => collect([$warehouse->city, $warehouse->country])->filter()->implode(', '),
                'Status' => ucwords(str_replace('_', ' ', (string) $warehouse->status)),
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Warehouse '{$warehouse->name}' created successfully.",
            'data' => $warehouse,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show comprehensive warehouse details, configuration, and readiness score.
     */
    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $warehouse->load([
            'parentWarehouse:id,name,code',
            'locations' => function ($q) {
                $q->orderBy('sort_order')->limit(50);
            },
            'memberships.user:id,name,email,role',
            'stores:id,name,platform,code',
        ]);

        $warehouse->loadCount(['locations', 'balances', 'memberships']);

        $readiness = $this->readinessService->evaluate($warehouse);

        return response()->json([
            'status' => 'success',
            'data' => $warehouse,
            'readiness' => $readiness,
        ]);
    }

    /**
     * Update warehouse configuration.
     */
    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'warehouse_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'parent_warehouse_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'operating_hours' => ['nullable', 'array'],
            'inventory_policies' => ['nullable', 'array'],
            'inbound_policies' => ['nullable', 'array'],
            'outbound_policies' => ['nullable', 'array'],
            'integration_settings' => ['nullable', 'array'],
        ]);

        $updated = $this->warehouseService->updateWarehouse($warehouse, $request->user()->id, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Warehouse configuration updated.',
            'data' => $updated,
            'readiness' => $this->readinessService->evaluate($updated),
        ]);
    }

    /**
     * Evaluate warehouse readiness prerequisites.
     */
    public function readiness(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $readiness = $this->readinessService->evaluate($warehouse);

        return response()->json([
            'status' => 'success',
            'data' => $readiness,
        ]);
    }

    /**
     * Activate warehouse after verifying all readiness requirements.
     */
    public function activate(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $result = $this->warehouseService->activateWarehouse($warehouse, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => $result['warehouse'],
        ]);
    }

    /**
     * Deactivate warehouse safely without modifying inventory records.
     */
    public function deactivate(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $reason = $request->input('reason');
        $result = $this->warehouseService->deactivateWarehouse($warehouse, $request->user()->id, $reason);

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => $result['warehouse'],
        ]);
    }

    /**
     * Initiate controlled warehouse closure.
     */
    public function beginClosure(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $reason = $request->input('reason');
        $result = $this->warehouseService->beginClosure($warehouse, $request->user()->id, $reason);

        return response()->json([
            'status' => 'success',
            'message' => $result['message'],
            'data' => $result['warehouse'],
            'outstanding_stock_lines' => $result['outstanding_stock_lines'],
        ]);
    }

    /**
     * Permanently delete an unused warehouse (no stores, stock or documents) and email the admins.
     */
    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $this->warehouseService->deleteWarehouse($warehouse, $request->user()->id);

        $this->emails->sendActivityEmail(EmailService::WAREHOUSE_DELETED, $request->user()->currentOrganization(), [
            'name' => $warehouse->name,
            'actor' => $request->user(),
            'details' => [
                'Code' => $warehouse->code,
                'Location' => collect([$warehouse->city, $warehouse->country])->filter()->implode(', '),
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Warehouse '{$warehouse->name}' deleted.",
        ]);
    }

    /**
     * List locations for warehouse with hierarchy, filtering, and search.
     */
    public function locations(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $query = $warehouse->locations()->with('parent:id,code,location_type');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('aisle', 'like', "%{$search}%");
            });
        }

        if ($type = $request->query('location_type')) {
            $query->where('location_type', $type);
        }

        $locations = $query->orderBy('sort_order')->orderBy('code')->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'status' => 'success',
            'data' => $locations->items(),
            'meta' => [
                'current_page' => $locations->currentPage(),
                'last_page' => $locations->lastPage(),
                'per_page' => $locations->perPage(),
                'total' => $locations->total(),
            ],
        ]);
    }

    /**
     * Create location with hierarchy and validation.
     */
    public function storeLocation(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'location_type' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'aisle' => ['nullable', 'string', 'max:20'],
            'rack' => ['nullable', 'string', 'max:20'],
            'shelf' => ['nullable', 'string', 'max:20'],
            'bin' => ['nullable', 'string', 'max:20'],
            'picking_eligible' => ['nullable', 'boolean'],
            'putaway_eligible' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:30'],
            'weight_capacity' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $location = $this->layoutService->createLocation($warehouse, $validated, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => "Location '{$location->code}' created.",
            'data' => $location,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update location.
     */
    public function updateLocation(Request $request, Warehouse $warehouse, WarehouseLocation $location): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        if ($location->warehouse_id !== $warehouse->id) {
            abort(404, 'Location not found in this warehouse.');
        }

        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'location_type' => ['nullable', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'aisle' => ['nullable', 'string', 'max:20'],
            'rack' => ['nullable', 'string', 'max:20'],
            'shelf' => ['nullable', 'string', 'max:20'],
            'bin' => ['nullable', 'string', 'max:20'],
            'picking_eligible' => ['nullable', 'boolean'],
            'putaway_eligible' => ['nullable', 'boolean'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:30'],
            'weight_capacity' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->layoutService->updateLocation($location, $validated, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => "Location '{$updated->code}' updated.",
            'data' => $updated,
        ]);
    }

    /**
     * Delete location safely.
     */
    public function destroyLocation(Request $request, Warehouse $warehouse, WarehouseLocation $location): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        if ($location->warehouse_id !== $warehouse->id) {
            abort(404, 'Location not found in this warehouse.');
        }

        $this->layoutService->deleteLocation($location, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => "Location '{$location->code}' deleted successfully.",
        ]);
    }

    /**
     * Generate starter layout with preview or commit.
     */
    public function starterLayout(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $previewOnly = $request->boolean('preview', true);
        $config = $request->all();

        $result = $this->layoutService->generateStarterLayout(
            warehouse: $warehouse,
            config: $config,
            previewOnly: $previewOnly,
            userId: $request->user()->id
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * List warehouse staff members and eligible organization users.
     */
    public function staff(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $assigned = $warehouse->memberships()->with('user:id,name,email,role')->get();

        $org = $request->user()->currentOrganization();
        $eligibleUsers = User::whereHas('memberships', function ($q) use ($org) {
            $q->where('organization_id', $org->id);
        })->orWhere('current_organization_id', $org->id)->get(['id', 'name', 'email', 'role']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'assigned_staff' => $assigned,
                'eligible_users' => $eligibleUsers,
            ],
        ]);
    }

    /**
     * Assign staff member to warehouse.
     */
    public function assignStaff(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', 'string', 'in:manager,supervisor,operator,picker,packer,receiver'],
            'permissions' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $membership = $this->warehouseService->assignStaff($warehouse, $validated, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff member assigned successfully.',
            'data' => $membership->load('user:id,name,email,role'),
        ], Response::HTTP_CREATED);
    }

    /**
     * Remove staff member from warehouse.
     */
    public function removeStaff(Request $request, Warehouse $warehouse, int $membershipId): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $this->warehouseService->removeStaff($warehouse, $membershipId, $request->user()->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff member assignment removed.',
        ]);
    }

    /**
     * Preview opening stock initialization.
     */
    public function previewOpeningStock(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.location_code' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.lot_number' => ['nullable', 'string'],
            'items.*.expiry_date' => ['nullable', 'date'],
        ]);

        $preview = $this->openingStockService->preview($warehouse, $validated['items']);

        return response()->json([
            'status' => 'success',
            'data' => $preview,
        ]);
    }

    /**
     * Commit opening stock initialization into InventoryEngine.
     */
    public function commitOpeningStock(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string'],
            'items.*.location_code' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.lot_number' => ['nullable', 'string'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->openingStockService->commit(
            warehouse: $warehouse,
            rows: $validated['items'],
            userId: $request->user()->id,
            idempotencyKey: $validated['idempotency_key'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Opening stock committed successfully into inventory ledger.',
            'data' => $result,
        ]);
    }

    /**
     * Paginated audit logs for this warehouse.
     */
    public function auditLogs(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $logs = $warehouse->auditLogs()
            ->with('user:id,name,email')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Authorize that the authenticated user belongs to the same organization as the warehouse.
     */
    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        $org = $request->user()->currentOrganization();
        if ($warehouse->organization_id !== $org->id) {
            abort(403, 'Unauthorized access: warehouse does not belong to your organization.');
        }
    }
}
