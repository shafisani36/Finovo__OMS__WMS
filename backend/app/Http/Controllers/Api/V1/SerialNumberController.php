<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UnitSerialNumber;
use App\Services\AccessControl;
use App\Services\SerialNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SerialNumberController extends Controller
{
    public function __construct(
        protected SerialNumberService $serialService,
        protected AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $search = $request->query('search');
        $status = $request->query('status');
        $warehouseId = $request->query('warehouse_id');

        $query = UnitSerialNumber::where('organization_id', $orgId)
            ->with(['productVariant.product', 'warehouse', 'location'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(function ($sub) use ($like) {
                    $sub->where('serial_number', 'like', $like)
                        ->orWhereHas('productVariant', fn ($pv) => $pv->where('sku', 'like', $like));
                });
            })
            ->latest('id');

        $page = $query->paginate($this->perPage($request, 25));

        return response()->json([
            'status' => 'success',
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $serial): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $trace = $this->serialService->trace($orgId, $serial);

        abort_if(! $trace, 404, "Serial number '{$serial}' was not found.");

        return response()->json([
            'status' => 'success',
            'data' => $trace,
        ]);
    }
}
