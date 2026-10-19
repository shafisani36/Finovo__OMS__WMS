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

        $query = PurchaseOrder::where('organization_id', $org->id)
            ->with(['warehouse', 'creator', 'requisition', 'items', 'goodsReceivedNotes']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $orders = $query->latest('id')->paginate($perPage);

        $counts = [
            'total' => PurchaseOrder::where('organization_id', $org->id)->count(),
            'draft' => PurchaseOrder::where('organization_id', $org->id)->where('status', 'draft')->count(),
            'issued' => PurchaseOrder::where('organization_id', $org->id)->where('status', 'issued')->count(),
            'partially_received' => PurchaseOrder::where('organization_id', $org->id)->where('status', 'partially_received')->count(),
            'received' => PurchaseOrder::where('organization_id', $org->id)->where('status', 'received')->count(),
            'cancelled' => PurchaseOrder::where('organization_id', $org->id)->where('status', 'cancelled')->count(),
        ];

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
     * Create a standalone Purchase Order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_contact' => 'nullable|string|max:100',
            'warehouse_id' => 'required|exists:warehouses,id',
            'purchase_requisition_id' => 'nullable|exists:purchase_requisitions,id',
            'order_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
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

        $po = PurchaseOrder::where('organization_id', $org->id)
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
        $po = PurchaseOrder::where('organization_id', $org->id)->findOrFail($id);

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
        $po = PurchaseOrder::where('organization_id', $org->id)->findOrFail($id);

        $reason = $request->input('reason');
        $updated = $this->purchaseOrderService->cancelPurchaseOrder($po, $request->user(), $reason);

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Order #{$updated->po_number} cancelled.",
            'data' => $updated,
        ]);
    }
}
