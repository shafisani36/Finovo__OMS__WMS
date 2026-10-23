<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceivedNote;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceivedNoteService;
use App\Services\ProcurementPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoodsReceivedNoteController extends Controller
{
    public function __construct(
        protected GoodsReceivedNoteService $grnService,
        protected ProcurementPdfService $pdfService
    ) {}

    /**
     * List Goods Received Notes with filtering, search, and KPI metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        // With ?store_id= only notes for that store's purchase orders are listed and counted.
        $scoped = fn () => GoodsReceivedNote::where('organization_id', $org->id)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->whereHas('purchaseOrder', fn ($po) => $po->where('store_id', $storeId)));

        $query = $scoped()
            ->with(['purchaseOrder', 'warehouse', 'receivingLocation', 'receivedBy', 'inspectedBy', 'postedBy', 'items']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($poId = $request->query('purchase_order_id')) {
            $query->where('purchase_order_id', $poId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('grn_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('supplier_delivery_note', 'like', "%{$search}%")
                    ->orWhere('carrier', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $grns = $query->latest('id')->paginate($perPage);

        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = ['total' => (int) $byStatus->sum()];

        foreach (['draft', 'inspected', 'posted', 'cancelled'] as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return response()->json([
            'status' => 'success',
            'data' => $grns->items(),
            'meta' => [
                'current_page' => $grns->currentPage(),
                'last_page' => $grns->lastPage(),
                'per_page' => $grns->perPage(),
                'total' => $grns->total(),
            ],
            'kpis' => $counts,
        ]);
    }

    /**
     * Create a new Goods Received Note against a Purchase Order.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $validated = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'receiving_location_id' => 'nullable|exists:warehouse_locations,id',
            'supplier_delivery_note' => 'nullable|string|max:100',
            'carrier' => 'nullable|string|max:100',
            'tracking_or_vehicle_number' => 'nullable|string|max:100',
            'received_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'discrepancy_summary' => 'nullable|string',
            'allow_over_receipt' => 'nullable|boolean',
            'items' => 'nullable|array',
            'items.*.purchase_order_item_id' => 'required_with:items|exists:purchase_order_items,id',
            'items.*.received_quantity' => 'nullable|integer|min:0',
            'items.*.accepted_quantity' => 'nullable|integer|min:0',
            'items.*.damaged_quantity' => 'nullable|integer|min:0',
            'items.*.quarantined_quantity' => 'nullable|integer|min:0',
            'items.*.rejected_quantity' => 'nullable|integer|min:0',
            'items.*.pending_inspection_quantity' => 'nullable|integer|min:0',
            'items.*.destination_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.lot_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.discrepancy_reason' => 'nullable|string',
            'items.*.notes' => 'nullable|string',
        ]);

        $po = PurchaseOrder::where('organization_id', $org->id)->findOrFail($validated['purchase_order_id']);

        $grn = $this->grnService->createGrn($po, $validated, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Goods Received Note #{$grn->grn_number} created.",
            'data' => $grn,
        ], 201);
    }

    /**
     * Show GRN detail.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $grn = GoodsReceivedNote::where('organization_id', $org->id)
            ->with([
                'purchaseOrder.items.variant.product',
                'warehouse',
                'receivingLocation',
                'receivedBy',
                'inspectedBy',
                'postedBy',
                'items.destinationLocation',
                'items.productVariant.product',
            ])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $grn,
        ]);
    }

    /**
     * Update inspection results and condition distribution.
     */
    public function inspect(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $grn = GoodsReceivedNote::where('organization_id', $org->id)->findOrFail($id);

        $validated = $request->validate([
            'notes' => 'nullable|string',
            'discrepancy_summary' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:goods_received_note_items,id',
            'items.*.accepted_quantity' => 'required|integer|min:0',
            'items.*.damaged_quantity' => 'nullable|integer|min:0',
            'items.*.quarantined_quantity' => 'nullable|integer|min:0',
            'items.*.rejected_quantity' => 'nullable|integer|min:0',
            'items.*.pending_inspection_quantity' => 'nullable|integer|min:0',
            'items.*.lot_number' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.discrepancy_reason' => 'nullable|string',
        ]);

        $updated = $this->grnService->inspectGrn($grn, $validated, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Inspection recorded for GRN #{$updated->grn_number}.",
            'data' => $updated,
        ]);
    }

    /**
     * Post GRN quantities into authoritative inventory balances and stock ledger.
     */
    public function postToInventory(Request $request, int $id): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $grn = GoodsReceivedNote::where('organization_id', $org->id)->findOrFail($id);

        $posted = $this->grnService->postToInventory($grn, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "GRN #{$posted->grn_number} successfully posted to authoritative inventory ledger.",
            'data' => $posted,
        ]);
    }

    /**
     * Download or stream official Goods Received Note PDF.
     */
    public function downloadPdf(Request $request, int $id): Response
    {
        $org = $request->user()->currentOrganization();
        $grn = GoodsReceivedNote::where('organization_id', $org->id)->findOrFail($id);

        return $this->pdfService->streamGrnPdf($grn);
    }
}
