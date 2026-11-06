<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The analytics page: sales, orders, stores, products, shipping, returns and stock over a chosen period.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $filters = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(AnalyticsService::PERIODS)],
            'store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')],
            'warehouse_id' => ['nullable', 'integer', $this->accessibleWarehouse($request)],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $analytics->report(
                $request->user(),
                (int) ($filters['days'] ?? 30),
                isset($filters['store_id']) ? (int) $filters['store_id'] : null,
                isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
            ),
        ]);
    }
}
