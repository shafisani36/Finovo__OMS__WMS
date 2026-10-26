<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Services\ProcurementPdfService;
use App\Services\ProcurementRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PurchaseRequisitionController extends Controller
{
    public function __construct(
        protected ProcurementRequisitionService $requisitionService,
        protected ProcurementPdfService $pdfService
    ) {}

    /**
     * List purchase requisitions with filtering, search, and KPI metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        // With ?store_id= only that store's requisitions are listed and counted.
        $scoped = fn () => $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->where('store_id', $storeId));

        $requisitions = $scoped()
            ->with(['requester', 'approver', 'warehouse', 'items'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('warehouse_id'), fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('pr_number', 'like', "%{$search}%")
                ->orWhere('purpose', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate($this->perPage($request));

        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = ['total' => (int) $byStatus->sum()];

        foreach (['draft', 'submitted', 'approved', 'partially_ordered', 'ordered', 'rejected'] as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return response()->json([
            'status' => 'success',
            'data' => $requisitions->items(),
            'meta' => [
                'current_page' => $requisitions->currentPage(),
                'last_page' => $requisitions->lastPage(),
                'per_page' => $requisitions->perPage(),
                'total' => $requisitions->total(),
            ],
            'kpis' => $counts,
        ]);
    }

    /**
     * Create a new purchase requisition.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_id' => ['nullable', 'integer', $this->inOrganization($request, 'stores')],
            'warehouse_id' => ['required', 'integer', $this->accessibleWarehouse($request)],
            // The delivery bin must be inside the requisition's warehouse.
            'delivery_location_id' => ['nullable', 'integer', $this->inOrganization($request, 'warehouse_locations')->where('warehouse_id', $request->integer('warehouse_id'))],
            'department' => 'nullable|string|max:100',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'required_by_date' => 'nullable|date',
            'purpose' => 'nullable|string|max:255',
            'justification' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
            'items.*.product_id' => ['nullable', 'integer', $this->inOrganization($request, 'products')],
            'items.*.sku' => 'required|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.requested_quantity' => 'required|integer|min:1',
            'items.*.estimated_unit_price' => 'nullable|numeric|min:0',
            'items.*.uom' => 'nullable|string|max:20',
            'items.*.notes' => 'nullable|string',
        ]);

        $requisition = $this->requisitionService->createRequisition($validated, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Requisition #{$requisition->pr_number} created successfully.",
            'data' => $requisition,
        ], 201);
    }

    /**
     * Get requisition details with enriched real-time stock planning context.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)
            ->with(['requester', 'approver', 'warehouse', 'deliveryLocation', 'approvals.user', 'items.variant.product', 'purchaseOrders'])
            ->findOrFail($id);

        // Enrich items with real-time stock planning context
        $itemsWithPlanning = $requisition->items->map(function ($item) use ($requisition, $org) {
            $planning = $this->requisitionService->getStockPlanningContext(
                $org->id,
                $requisition->warehouse_id,
                $item->product_variant_id
            );

            $data = $item->toArray();
            $data['stock_context'] = $planning;
            $data['remaining_quantity'] = $item->remaining_quantity;

            return $data;
        });

        $responseData = $requisition->toArray();
        $responseData['items'] = $itemsWithPlanning;

        return response()->json([
            'status' => 'success',
            'data' => $responseData,
        ]);
    }

    /**
     * Submit requisition for approval.
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)->findOrFail($id);
        $request->validate(['comments' => 'nullable|string|max:1000']);

        $updated = $this->requisitionService->submitRequisition($requisition, $request->user(), $request->input('comments'));

        return response()->json([
            'status' => 'success',
            'message' => "Requisition #{$updated->pr_number} submitted for management approval.",
            'data' => $updated,
        ]);
    }

    /**
     * Approve requisition (Enforces separation of duties!).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)->findOrFail($id);

        $request->validate([
            'approved_quantities' => 'nullable|array', // [requisition item ID => quantity]
            'approved_quantities.*' => 'integer|min:0',
            'comments' => 'nullable|string|max:1000',
        ]);

        $updated = $this->requisitionService->approveRequisition($requisition, $request->user(), $request->input('approved_quantities'), $request->input('comments'));

        return response()->json([
            'status' => 'success',
            'message' => "Requisition #{$updated->pr_number} approved successfully.",
            'data' => $updated,
        ]);
    }

    /**
     * Reject requisition.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)->findOrFail($id);

        $request->validate([
            'reason' => 'required|string|min:3|max:1000',
            'comments' => 'nullable|string|max:1000',
        ]);

        $updated = $this->requisitionService->rejectRequisition(
            $requisition,
            $request->user(),
            $request->input('reason'),
            $request->input('comments')
        );

        return response()->json([
            'status' => 'success',
            'message' => "Requisition #{$updated->pr_number} rejected.",
            'data' => $updated,
        ]);
    }

    /**
     * Convert approved requisition to purchase order.
     */
    public function convertToPo(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)->findOrFail($id);

        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_contact' => 'nullable|string|max:100',
            'warehouse_id' => ['nullable', 'integer', $this->accessibleWarehouse($request)],
            'order_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:'.($request->input('order_date') ?: 'today'),
            'payment_terms' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            // Lines must come from this requisition.
            'items.*.purchase_requisition_item_id' => ['required_with:items', 'integer', Rule::exists('purchase_requisition_items', 'id')->where('purchase_requisition_id', $requisition->id)],
            'items.*.ordered_quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        $po = $this->requisitionService->convertToPurchaseOrder($requisition, $validated, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Purchase Order #{$po->po_number} successfully generated from PR #{$requisition->pr_number}.",
            'data' => $po,
        ], 201);
    }

    /**
     * Real-time stock planning context for a variant in a warehouse.
     */
    public function stockContext(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', $this->accessibleWarehouse($request)],
            'variant_id' => ['required', 'integer', $this->inOrganization($request, 'product_variants')],
        ]);

        $context = $this->requisitionService->getStockPlanningContext($org->id, (int) $validated['warehouse_id'], (int) $validated['variant_id']);

        return response()->json([
            'status' => 'success',
            'data' => $context,
        ]);
    }

    /**
     * Download or stream official Purchase Requisition PDF.
     */
    public function downloadPdf(Request $request, int $id): Response
    {
        $org = $request->user()->currentOrganization();
        $requisition = $this->inUserWarehouses(PurchaseRequisition::where('organization_id', $org->id), $request)->findOrFail($id);

        return $this->pdfService->streamPrPdf($requisition);
    }
}
