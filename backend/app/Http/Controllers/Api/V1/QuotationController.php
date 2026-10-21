<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveQuotationRequest;
use App\Models\Quotation;
use App\Services\QuotationPdfService;
use App\Services\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(
        protected QuotationService $quotations,
        protected QuotationPdfService $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();
        $storeId = $request->integer('store_id') ?: null;
        $scoped = fn () => Quotation::where('organization_id', $org->id)->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $quotations = $scoped()
            ->with('store:id,name,platform')
            ->withCount('items')
            ->when($request->query('status'), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->query('search'), function ($query, string $search) {
                $query->where(fn ($q) => $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%"));
            })
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 10), 1), 100));

        $counts = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'status' => 'success',
            'data' => $quotations->items(),
            'meta' => [
                'current_page' => $quotations->currentPage(),
                'last_page' => $quotations->lastPage(),
                'per_page' => $quotations->perPage(),
                'total' => $quotations->total(),
            ],
            'kpis' => [
                'total' => (int) $counts->sum(),
                'open' => (int) ($counts[Quotation::STATUS_DRAFT] ?? 0) + (int) ($counts[Quotation::STATUS_SENT] ?? 0),
                'accepted' => (int) ($counts[Quotation::STATUS_ACCEPTED] ?? 0) + (int) ($counts[Quotation::STATUS_CONVERTED] ?? 0),
                'rejected' => (int) ($counts[Quotation::STATUS_REJECTED] ?? 0),
            ],
        ]);
    }

    public function store(SaveQuotationRequest $request): JsonResponse
    {
        $quotation = $this->quotations->create($request->user()->currentOrganization(), $request->user(), $request->validated());

        return response()->json(['status' => 'success', 'message' => "Quotation {$quotation->quotation_number} created.", 'data' => $quotation], 201);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeOrganization($request, $quotation);

        return response()->json([
            'status' => 'success',
            'data' => $quotation->load(['items.variant.product:id,name,image_url', 'store:id,name,platform', 'customer:id,name,email', 'creator:id,name', 'order:id,order_number,order_status']),
        ]);
    }

    public function update(SaveQuotationRequest $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeOrganization($request, $quotation);
        $quotation = $this->quotations->update($quotation, $request->validated());

        return response()->json(['status' => 'success', 'message' => 'Quotation updated.', 'data' => $quotation]);
    }

    public function destroy(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeOrganization($request, $quotation);
        abort_unless($quotation->status === Quotation::STATUS_DRAFT, 422, 'Only a draft quotation can be deleted.');

        $quotation->delete();

        return response()->json(['status' => 'success', 'message' => "Quotation {$quotation->quotation_number} deleted."]);
    }

    public function updateStatus(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeOrganization($request, $quotation);
        $validated = $request->validate([
            'status' => ['required', Rule::in([Quotation::STATUS_SENT, Quotation::STATUS_ACCEPTED, Quotation::STATUS_REJECTED])],
        ]);

        $quotation = $this->quotations->changeStatus($quotation, $validated['status']);

        return response()->json(['status' => 'success', 'message' => "Quotation marked as {$quotation->status}.", 'data' => $quotation]);
    }

    public function convert(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeOrganization($request, $quotation);
        $order = $this->quotations->convertToOrder($quotation);

        return response()->json(['status' => 'success', 'message' => "Order {$order->order_number} created from the quotation.", 'data' => $order], 201);
    }

    public function pdf(Request $request, Quotation $quotation): Response
    {
        $this->authorizeOrganization($request, $quotation);

        return $this->pdf->download($quotation);
    }

    private function authorizeOrganization(Request $request, Quotation $quotation): void
    {
        abort_if($quotation->organization_id !== $request->user()->currentOrganization()->id, 404);
    }
}
