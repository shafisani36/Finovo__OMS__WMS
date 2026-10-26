<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $purchaseOrderService
    ) {}

    /**
     * List purchase orders.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        // With ?store_id= only that store's purchase orders are listed and counted.
        $scoped = fn () => $this->inUserWarehouses(PurchaseOrder::where('organization_id', $org->id), $request)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId));

        $orders = $scoped()
            ->with(['warehouse', 'creator', 'requisition', 'items', 'goodsReceivedNotes'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('warehouse_id'), fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('po_number', 'like', "%{$search}%")
                ->orWhere('supplier_name', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate($this->perPage($request));

        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = ['total' => (int) $byStatus->sum()];

        foreach (['draft', 'issued', 'partially_received', 'received', 'cancelled'] as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return response()->json([
            'status' => 'success',
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
            'kpis' => $counts,
        ]);
    }

    /**
     * Create a standalone purchase order (orders from a requisition are raised through its convert-po action,
     * which also tracks the ordered quantities on the requisition).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_contact' => 'nullable|string|max:100',
            'store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')],
            'warehouse_id' => ['required', 'integer', $this->accessibleWarehouse($request)],
            'order_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:'.($request->input('order_date') ?: 'today'),
            'payment_terms' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
            'items.*.sku' => 'required|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.ordered_quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.uom' => 'nullable|string|max:20',
            'items.*.notes' => 'nullable|string',
        ]);

        $po = $this->purchaseOrderService->createPurchaseOrder($validated, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Order #{$po->po_number} created successfully.",
            'data' => $po,
        ], 201);
    }

    /**
     * Show Purchase Order detail.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $po = $this->inUserWarehouses(PurchaseOrder::where('organization_id', $org->id), $request)
            ->with(['warehouse', 'creator', 'requisition', 'items.variant.product', 'goodsReceivedNotes.receivedBy'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $po,
        ]);
    }

    /**
     * Issue draft purchase order.
     */
    public function issue(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $po = $this->inUserWarehouses(PurchaseOrder::where('organization_id', $org->id), $request)->findOrFail($id);

        $updated = $this->purchaseOrderService->issuePurchaseOrder($po, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Order #{$updated->po_number} marked as issued.",
            'data' => $updated,
        ]);
    }

    /**
     * Cancel purchase order.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $po = $this->inUserWarehouses(PurchaseOrder::where('organization_id', $org->id), $request)->findOrFail($id);

        $request->validate(['reason' => 'nullable|string|max:1000']);

        $updated = $this->purchaseOrderService->cancelPurchaseOrder($po, $request->user(), $request->input('reason'));

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Order #{$updated->po_number} cancelled.",
            'data' => $updated,
        ]);
    }
}
