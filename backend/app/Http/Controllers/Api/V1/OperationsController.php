<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    /**
     * Admin-only overview metrics and warehouse settings.
     */
    public function adminOverview(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin system telemetry retrieved.',
            'data' => [
                'role' => 'admin',
                'total_orders' => 1248,
                'inventory_items' => 321,
                'connected_stores' => 12,
                'active_shipments' => 86,
                'warehouses_synced' => 3,
                'system_health' => 'optimal',
            ],
            'errors' => null,
        ]);
    }

    /**
     * Operator tasks and fulfillment queues.
     */
    public function operatorTasks(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operator queue retrieved.',
            'data' => [
                'role' => $request->user()->role->value,
                'active_pick_lists' => 14,
                'pending_shipments' => 86,
                'stock_movements_today' => 52,
                'assigned_zone' => 'Zone B (Fulfillment)',
            ],
            'errors' => null,
        ]);
    }
}
