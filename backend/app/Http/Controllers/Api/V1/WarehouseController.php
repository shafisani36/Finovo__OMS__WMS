<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseMembership;
use App\Services\AccessControl;
use App\Services\EmailService;
use App\Services\InventoryEngine;
use App\Services\SlottingService;
use App\Services\StoreWarehouseService;
use App\Services\WarehouseInsights;
use App\Services\WarehouseLayoutService;
use App\Services\WarehouseOpeningStockService;
use App\Services\WarehousePerformanceService;
use App\Services\WarehouseReadinessService;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService,
        protected WarehouseReadinessService $readinessService,
        protected WarehouseLayoutService $layoutService,
        protected WarehouseOpeningStockService $openingStockService,
        protected EmailService $emails,
        protected AccessControl $access,
        protected WarehouseInsights $insights,
        protected WarehousePerformanceService $performance,
        protected SlottingService $slotting,
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

        if (($ids = $this->access->warehouseIds($request->user())) !== null) {
            $filters['ids'] = $ids;
        }

        $perPage = $this->perPage($request, 10);
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
            'warehouse_type' => ['nullable', Rule::in(Warehouse::TYPES)],
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
            'warehouse_type' => ['nullable', Rule::in(Warehouse::TYPES)],
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

        $locations = $query->orderBy('sort_order')->orderBy('code')->paginate($this->perPage($request, 50, 1000));

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

        $assigned = $warehouse->memberships()
            ->with(['user:id,name,email,role', 'assignedBy:id,name'])
            ->orderBy('assigned_at')
            ->get()
            ->map(fn (WarehouseMembership $membership) => [
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'role' => $membership->role,
                'status' => $membership->status,
                'assigned_at' => $membership->assigned_at?->toIso8601String(),
                'expires_at' => $membership->expires_at?->toIso8601String(),
                'assigned_by' => $membership->assignedBy?->name,
                'user' => $membership->user ? [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role_label' => $membership->user->role?->label(),
                ] : null,
            ]);

        $org = $request->user()->currentOrganization();
        $eligibleUsers = User::where(fn ($q) => $q
            ->whereHas('memberships', fn ($q) => $q->where('organization_id', $org->id))
            ->orWhere('current_organization_id', $org->id))
            ->where('status', '!=', 'suspended')
            ->whereNotIn('id', $assigned->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

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
            ->paginate($this->perPage($request, 25));

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
     * Figures for the warehouse overview: stock, capacity, stock by category and storage by zone with the bin map.
     */
    public function overview(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        return response()->json(['status' => 'success', 'data' => $this->insights->overview($warehouse)]);
    }

    /**
     * Stock movements and setup changes of the warehouse, newest first (?type=all|stock|orders|setup).
     */
    public function activity(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $validated = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', WarehouseInsights::ACTIVITY_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'status' => 'success',
            ...$this->insights->activity($warehouse, $validated['type'] ?? 'all', (int) ($validated['page'] ?? 1), $this->perPage($request, 20)),
        ]);
    }

    /**
     * WERC-style performance dashboard: accuracy, cycle times, productivity and capacity
     * versus 2025 best-in-class benchmarks. Optional ?from=&to= (ISO dates, default last 30 days).
     */
    public function performance(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->performance->dashboard(
                $warehouse,
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            ),
        ]);
    }

    /**
     * ABC slotting: each product's class from its sales value, where its stock is, and the moves that would put
     * fast sellers in the picking area and slow sellers in bulk storage.
     */
    public function slotting(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:30,60,90,180,365']])['days'] ?? SlottingService::LOOKBACK_DAYS);

        return response()->json(['status' => 'success', 'data' => $this->slotting->analyse($warehouse, $days)]);
    }

    /**
     * The bin to put received units of a product in.
     */
    public function putawaySuggestion(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'lot_number' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->slotting->suggestPutaway($warehouse, (int) $validated['product_variant_id'], (int) ($validated['quantity'] ?? 1), $validated['lot_number'] ?? null),
        ]);
    }

    /**
     * Set the stores the warehouse serves. A newly linked store ships from it after its other warehouses.
     */
    public function updateStores(Request $request, Warehouse $warehouse, StoreWarehouseService $links): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);

        $validated = $request->validate([
            'store_ids' => ['present', 'array'],
            'store_ids.*' => ['integer', 'distinct'],
        ]);

        $warehouse = $links->setWarehouseStores($warehouse, $validated['store_ids'], $request->user());
        $names = $warehouse->stores->pluck('name');

        return response()->json([
            'status' => 'success',
            'message' => $names->isEmpty()
                ? "{$warehouse->name} is not linked to any store now."
                : "{$warehouse->name} now fulfils orders of ".$names->implode(', ').'.',
            'data' => $warehouse->stores,
        ]);
    }

    /**
     * Move units of a product from one bin to another in the warehouse (a slotting move or a manual re-bin).
     */
    public function moveStock(Request $request, Warehouse $warehouse, InventoryEngine $inventory): JsonResponse
    {
        $this->authorizeWarehouse($request, $warehouse);
        $inWarehouse = Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouse->id);
        $validated = $request->validate([
            'product_variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
            'from_location_id' => ['required', 'integer', $inWarehouse],
            'to_location_id' => ['required', 'integer', 'different:from_location_id', $inWarehouse],
            'quantity' => ['required', 'integer', 'min:1'],
            'lot_number' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $inventory->putawayStock(
                organizationId: $warehouse->organization_id,
                warehouseId: $warehouse->id,
                productVariantId: $validated['product_variant_id'],
                fromLocationId: $validated['from_location_id'],
                toLocationId: $validated['to_location_id'],
                quantity: $validated['quantity'],
                lotNumber: $validated['lot_number'] ?? null,
                userId: $request->user()->id,
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        $codes = WarehouseLocation::whereIn('id', [$validated['from_location_id'], $validated['to_location_id']])->pluck('code', 'id');

        return response()->json([
            'status' => 'success',
            'message' => "Moved {$validated['quantity']} units from {$codes[$validated['from_location_id']]} to {$codes[$validated['to_location_id']]}.",
        ]);
    }

    /**
     * The warehouse must belong to the user's company and be one the user works in;
     * knowing a warehouse's ID never opens it.
     */
    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        $org = $request->user()->currentOrganization();
        if ($warehouse->organization_id !== $org->id) {
            abort(403, 'Unauthorized access: warehouse does not belong to your organization.');
        }

        if (! $this->access->canAccessWarehouse($request->user(), $warehouse->id)) {
            abort(403, 'You are not assigned to this warehouse.');
        }
    }
}
