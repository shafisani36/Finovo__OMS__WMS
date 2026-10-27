<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Services\ClientBillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Invoices to 3PL clients: made as a draft from the period's warehouse work, then issued, paid or voided.
 */
class ClientInvoiceController extends Controller
{
    public function __construct(
        protected ClientBillingService $billing
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'issued', 'paid', 'void', 'overdue'])],
        ]);
        $base = fn () => ClientInvoice::where('organization_id', $orgId);

        $invoices = $base()
            ->with('client:id,name,code')
            ->when($validated['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($validated['status'] ?? null, fn ($q, $status) => $status === 'overdue'
                ? $q->where('status', ClientInvoice::STATUS_ISSUED)->whereDate('due_date', '<', now()->toDateString())
                : $q->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request, 10));

        return response()->json([
            'status' => 'success',
            'data' => collect($invoices->items())->map(fn (ClientInvoice $invoice) => $this->present($invoice)),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
            // Amounts per currency, as clients may be billed in different currencies.
            'kpis' => [
                'outstanding' => $this->totals($base()->where('status', ClientInvoice::STATUS_ISSUED)),
                'overdue' => $this->totals($base()->where('status', ClientInvoice::STATUS_ISSUED)->whereDate('due_date', '<', now()->toDateString())),
                'paid_this_month' => $this->totals($base()->where('status', ClientInvoice::STATUS_PAID)->where('paid_at', '>=', now()->startOfMonth())),
                'drafts' => $base()->where('status', ClientInvoice::STATUS_DRAFT)->count(),
            ],
        ]);
    }

    /**
     * Make a draft invoice for a client and period (last month by default on the screen).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', $this->inOrganization($request, 'clients')],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $invoice = $this->billing->generate(
            Client::findOrFail($validated['client_id']),
            CarbonImmutable::parse($validated['period_start']),
            CarbonImmutable::parse($validated['period_end']),
            $request->user(),
        );

        return response()->json(['status' => 'success', 'message' => "Draft invoice {$invoice->invoice_number} created.", 'data' => $this->present($invoice, true)], 201);
    }

    public function show(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return response()->json(['status' => 'success', 'data' => $this->present($clientInvoice, true)]);
    }

    public function recalculate(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return $this->changed($this->billing->recalculate($clientInvoice), 'recalculated with the latest activity and rates');
    }

    public function addLine(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            // A negative rate is a discount or credit.
            'rate' => ['required', 'numeric', 'min:-9999999', 'max:9999999', 'not_in:0'],
        ]);

        return $this->changed($this->billing->addLine($clientInvoice, $validated['description'], (float) $validated['quantity'], (float) $validated['rate']), 'updated');
    }

    public function removeLine(Request $request, ClientInvoice $clientInvoice, ClientInvoiceLine $line): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return $this->changed($this->billing->removeLine($clientInvoice, $line), 'updated');
    }

    public function issue(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return $this->changed($this->billing->issue($clientInvoice), 'issued');
    }

    public function pay(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return $this->changed($this->billing->markPaid($clientInvoice), 'marked as paid');
    }

    public function void(Request $request, ClientInvoice $clientInvoice): JsonResponse
    {
        $this->authorizeInvoice($request, $clientInvoice);

        return $this->changed($this->billing->void($clientInvoice), 'voided');
    }

    public function pdf(Request $request, ClientInvoice $clientInvoice): Response
    {
        $this->authorizeInvoice($request, $clientInvoice);
        $clientInvoice->load(['client.stores:id,client_id,name', 'organization', 'lines']);

        $pdf = Pdf::loadView('pdf.client-invoice', ['invoice' => $clientInvoice])
            ->setPaper('a4', 'portrait')
            ->setOptions(['defaultFont' => 'Helvetica', 'isHtml5ParserEnabled' => true, 'isRemoteEnabled' => false])
            ->output();

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$clientInvoice->invoice_number}.pdf\"",
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    private function changed(ClientInvoice $invoice, string $what): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => "Invoice {$invoice->invoice_number} {$what}.", 'data' => $this->present($invoice->fresh(), true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClientInvoice $invoice, bool $withLines = false): array
    {
        $invoice->loadMissing('client:id,name,code,currency');

        return [
            ...$invoice->only(['id', 'invoice_number', 'client_id', 'status', 'currency', 'total', 'notes']),
            'client' => $invoice->client?->only(['id', 'name', 'code']),
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'is_overdue' => $invoice->isOverdue(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            ...($withLines ? ['lines' => $invoice->lines()->get(['id', 'type', 'description', 'quantity', 'rate', 'amount'])] : []),
        ];
    }

    /**
     * @return list<array{currency: string, amount: float}>
     */
    private function totals($query): array
    {
        return $query->groupBy('currency')
            ->selectRaw('currency, SUM(total) as amount')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount' => round((float) $row->amount, 2)])
            ->all();
    }

    private function authorizeInvoice(Request $request, ClientInvoice $invoice): void
    {
        abort_unless($invoice->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
