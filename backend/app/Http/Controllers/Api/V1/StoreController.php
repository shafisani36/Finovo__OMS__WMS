<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectStoreRequest;
use App\Jobs\SyncStoreJob;
use App\Models\Store;
use App\Services\EmailService;
use App\Services\StoreConnectionService;
use App\Services\StoreSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StoreController extends Controller
{
    public function __construct(
        protected StoreConnectionService $connections,
        protected EmailService $emails,
        protected StoreSyncService $syncService,
    ) {}

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
     * Connect a new store and link it to a warehouse.
     */
    public function store(ConnectStoreRequest $request): JsonResponse
    {
        $result = $this->connections->connect($request->validated(), $request->user());
        $store = $result['store'];

        $this->emails->sendActivityEmail(EmailService::STORE_CREATED, $store->organization, [
            'name' => $store->name,
            'actor' => $request->user(),
            'details' => [
                'Platform' => $store->platform->label(),
                'Fulfilling warehouse' => $store->warehouses->first()?->name,
                'Connection status' => $store->isConnected() ? 'Connected' : 'Waiting for authorization on '.$store->platform->label(),
            ],
        ]);

        return response()->json([
            'status' => 'success',
            'message' => $result['next_action']
                ? 'Store created. Finish authorizing it on '.$result['store']->platform->label().'.'
                : ($result['connection']?->message ?? 'Store created.'),
            'data' => $result['store'],
            'next_action' => $result['next_action'],
            'credentials' => $result['credentials'],
        ], 201);
    }

    /**
     * Next authorization step for a store that is not connected yet (approval page, app or module install).
     */
    public function authorization(Request $request, Store $store): JsonResponse
    {
        $this->ensureSameOrganization($request, $store);

        if (! $store->platform->authorizesOnPlatform()) {
            return response()->json([
                'status' => 'error',
                'message' => $store->platform->label().' stores connect with keys, not an authorization step.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'next_action' => $this->connections->authorizationAction($store),
        ]);
    }

    /**
     * Check the store's saved credentials against its platform.
     */
    public function testConnection(Request $request, Store $store): JsonResponse
    {
        $this->ensureSameOrganization($request, $store);

        $result = $this->connections->test($store);

        return response()->json([
            'status' => $result->successful ? 'success' : 'error',
            'message' => $result->message,
            'data' => $store->load('warehouses'),
        ], $result->successful ? 200 : 422);
    }

    /**
     * Show store details.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->ensureSameOrganization($request, $store);

        $store->load('warehouses');

        return response()->json([
            'status' => 'success',
            'data' => $store,
        ]);
    }

    /**
     * Import the store's products, variants, stock and orders.
     * Runs in a background job; without a queue worker (QUEUE_CONNECTION=sync) it runs right away.
     */
    public function sync(Request $request, Store $store): JsonResponse
    {
        $this->ensureSameOrganization($request, $store);

        if (! $store->isConnected()) {
            return response()->json(['status' => 'error', 'message' => 'Connect the store before syncing it.'], 422);
        }

        if (! $this->syncService->canSync($store)) {
            return response()->json(['status' => 'error', 'message' => "Finovo cannot import products and orders for {$store->platform->label()}."], 422);
        }

        if (config('queue.default') !== 'sync') {
            SyncStoreJob::dispatch($store);

            return response()->json([
                'status' => 'success',
                'message' => 'Sync started. Products and orders will appear in a few minutes.',
                'data' => $store,
            ], 202);
        }

        try {
            $result = $this->syncService->sync($store);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => 'Sync failed: '.$e->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Sync complete: '.$result->summary().($result->warnings ? ' '.implode(' ', $result->warnings) : ''),
            'data' => $store->fresh(),
            'result' => $result->toArray(),
        ]);
    }

    /**
     * Disconnect store.
     */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->ensureSameOrganization($request, $store);

        $details = [
            'Platform' => $store->platform->label(),
            'Linked warehouses' => $store->warehouses()->pluck('name')->implode(', '),
        ];

        $store->delete();

        $this->emails->sendActivityEmail(EmailService::STORE_DELETED, $request->user()->currentOrganization(), [
            'name' => $store->name,
            'actor' => $request->user(),
            'details' => $details,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Store disconnected successfully.',
        ]);
    }

    private function ensureSameOrganization(Request $request, Store $store): void
    {
        if ($store->organization_id !== $request->user()->currentOrganization()->id) {
            abort(403, 'Unauthorized access to this store.');
        }
    }
}
