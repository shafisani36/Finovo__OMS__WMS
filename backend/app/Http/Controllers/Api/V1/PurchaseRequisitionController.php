<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Services\ProcurementPdfService;
use App\Services\ProcurementRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        $query = PurchaseRequisition::where('organization_id', $org->id)
            ->with(['requester', 'approver', 'warehouse', 'items']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('pr_number', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $requisitions = $query->latest('id')->paginate($perPage);

        // Calculate KPI summary
        $counts = [
            'total' => PurchaseRequisition::where('organization_id', $org->id)->count(),
            'draft' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'draft')->count(),
            'submitted' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'submitted')->count(),
            'approved' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'approved')->count(),
            'partially_ordered' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'partially_ordered')->count(),
            'ordered' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'ordered')->count(),
            'rejected' => PurchaseRequisition::where('organization_id', $org->id)->where('status', 'rejected')->count(),
        ];

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
        $org = $request->user()->currentOrganization();
        $user = $request->user();

        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'delivery_location_id' => 'nullable|exists:warehouse_locations,id',
            'department' => 'nullable|string|max:100',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'required_by_date' => 'nullable|date',
            'purpose' => 'nullable|string|max:255',
            'justification' => 'nullable|string',
            'notes' => 'nullable|string',
            'currency' => 'nullable|string|max:10',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.sku' => 'required|string|max:100',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.requested_quantity' => 'required|integer|min:1',
            'items.*.estimated_unit_price' => 'nullable|numeric|min:0',
            'items.*.uom' => 'nullable|string|max:20',
            'items.*.notes' => 'nullable|string',
        ]);

        $requisition = $this->requisitionService->createRequisition($validated, $user);

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

        $requisition = PurchaseRequisition::where('organization_id', $org->id)
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
        $requisition = PurchaseRequisition::where('organization_id', $org->id)->findOrFail($id);

        $comments = $request->input('comments');
        $updated = $this->requisitionService->submitRequisition($requisition, $request->user(), $comments);

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
        $requisition = PurchaseRequisition::where('organization_id', $org->id)->findOrFail($id);

        $itemQuantities = $request->input('approved_quantities'); // [item_id => qty]
        $comments = $request->input('comments');

        $updated = $this->requisitionService->approveRequisition($requisition, $request->user(), $itemQuantities, $comments);

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
        $requisition = PurchaseRequisition::where('organization_id', $org->id)->findOrFail($id);

        $request->validate([
            'reason' => 'required|string|min:3',
            'comments' => 'nullable|string',
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
        $requisition = PurchaseRequisition::where('organization_id', $org->id)->findOrFail($id);

        $validated = $request->validate([
            'supplier_name' => 'required|string|max:255',
            'supplier_email' => 'nullable|email|max:255',
            'supplier_contact' => 'nullable|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'order_date' => 'nullable|date',
            'expected_delivery_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.purchase_requisition_item_id' => 'required_with:items|exists:purchase_requisition_items,id',
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
        $warehouseId = (int) $request->query('warehouse_id');
        $variantId = (int) $request->query('variant_id');

        if (! $warehouseId || ! $variantId) {
            return response()->json([
                'status' => 'error',
                'message' => 'warehouse_id and variant_id are required query parameters.',
            ], 422);
        }

        $context = $this->requisitionService->getStockPlanningContext($org->id, $warehouseId, $variantId);

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
        $requisition = PurchaseRequisition::where('organization_id', $org->id)->findOrFail($id);

        return $this->pdfService->streamPrPdf($requisition);
    }
}
