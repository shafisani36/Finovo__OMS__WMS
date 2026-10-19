<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    /**
     * List all warehouses for user's organization.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $warehouses = Warehouse::where('organization_id', $org->id)
            ->withCount(['locations', 'balances'])
            ->orderBy('priority', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $warehouses,
            'kpis' => [
                'total_warehouses' => $warehouses->count(),
                'active_warehouses' => $warehouses->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * Create a new warehouse.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $code = ! empty($validated['code'])
            ? strtoupper(trim($validated['code']))
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4).'-'.rand(100, 999));

        $warehouse = Warehouse::create([
            'organization_id' => $org->id,
            'name' => $validated['name'],
            'code' => $code,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => $validated['country'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'priority' => $validated['priority'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Warehouse created successfully.',
            'data' => $warehouse,
        ], 201);
    }

    /**
     * Show warehouse details.
     */
    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        if ($warehouse->organization_id !== $org->id) {
            abort(403, 'Unauthorized access to warehouse.');
        }

        $warehouse->load(['locations', 'balances.variant.product']);

        return response()->json([
            'status' => 'success',
            'data' => $warehouse,
        ]);
    }
}
