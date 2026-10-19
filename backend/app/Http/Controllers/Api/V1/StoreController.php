<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    /**
     * List all stores for the user's organization with metrics and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $query = Store::where('organization_id', $org->id)->with('warehouses');

        // Search
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('platform', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Platform filter
        if ($platform = $request->query('platform')) {
            $query->where('platform', $platform);
        }

        $perPage = (int) $request->query('per_page', 10);
        $stores = $query->latest()->paginate($perPage);

        // Aggregated Metrics for KPI cards
        $totalStores = Store::where('organization_id', $org->id)->count();
        $connectedStores = Store::where('organization_id', $org->id)->where('status', 'active')->count();
        $syncIssues = Store::where('organization_id', $org->id)->where('sync_status', 'sync_issues')->count();
        $ordersAwaitingSync = (int) Store::where('organization_id', $org->id)->sum('orders_awaiting_sync');

        return response()->json([
            'status' => 'success',
            'data' => $stores->items(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'last_page' => $stores->lastPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
            'kpis' => [
                'total_stores' => $totalStores,
                'connected_stores' => $connectedStores,
                'sync_issues' => $syncIssues,
                'orders_awaiting_sync' => $ordersAwaitingSync,
            ],
        ]);
    }

    /**
     * Create a new store connection.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:shopify,woocommerce,bigcommerce,prestashop,wix,ebay,magento,opencart,oscommerce,manual,custom_bridge'],
            'bridge_url' => ['nullable', 'url', 'max:255'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['exists:warehouses,id'],
        ]);

        $code = Str::slug($validated['name']).'-'.strtolower(Str::random(4));

        $apiKey = null;
        $apiSecret = null;
        if ($validated['platform'] === 'custom_bridge') {
            $apiKey = 'finovo_'.Str::random(32);
            $apiSecret = Str::random(64);
        }

        $store = Store::create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'code' => $code,
            'platform' => $validated['platform'],
            'status' => 'active',
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'bridge_url' => $validated['bridge_url'] ?? null,
            'last_sync_at' => now(),
        ]);

        if (! empty($validated['warehouse_ids'])) {
            $store->warehouses()->sync($validated['warehouse_ids']);
        } else {
            // Assign default active warehouse if exists
            $defaultWh = Warehouse::where('organization_id', $org->id)->where('is_active', true)->first();
            if ($defaultWh) {
                $store->warehouses()->sync([$defaultWh->id => ['is_default' => true]]);
            }
        }

        $store->load('warehouses');

        return response()->json([
            'status' => 'success',
            'message' => 'Store connected successfully.',
            'data' => $store,
            'credentials' => $apiKey ? [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ] : null,
        ], 201);
    }

    /**
     * Show store details.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($store->organization_id !== $org->id) {
            abort(403, 'Unauthorized access to this store.');
        }

        $store->load('warehouses');

        return response()->json([
            'status' => 'success',
            'data' => $store,
        ]);
    }

    /**
     * Trigger manual store sync.
     */
    public function sync(Request $request, Store $store): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($store->organization_id !== $org->id) {
            abort(403, 'Unauthorized access to this store.');
        }

        $store->update([
            'last_sync_at' => now(),
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store synchronization initiated successfully.',
            'data' => $store,
        ]);
    }

    /**
     * Disconnect store.
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($store->organization_id !== $org->id) {
            abort(403, 'Unauthorized access to this store.');
        }

        $store->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Store disconnected successfully.',
        ]);
    }
}
