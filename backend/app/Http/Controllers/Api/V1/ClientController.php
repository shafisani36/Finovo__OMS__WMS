<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\Store;
use App\Services\ClientBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 3PL clients: the brands the company stores and ships for, their rate cards and their stores.
 */
class ClientController extends Controller
{
    public function __construct(
        protected ClientBillingService $billing
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;

        $clients = Client::where('organization_id', $orgId)
            ->with('stores:id,client_id,name,platform')
            ->withSum(['invoices as outstanding' => fn ($q) => $q->where('status', ClientInvoice::STATUS_ISSUED)], 'total')
            ->withCount(['invoices as overdue_invoices' => fn ($q) => $q->where('status', ClientInvoice::STATUS_ISSUED)->whereDate('due_date', '<', now()->toDateString())])
            ->withMax(['invoices as billed_until' => fn ($q) => $q->where('status', '!=', ClientInvoice::STATUS_VOID)], 'period_end')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client) => $this->present($client));

        return response()->json([
            'status' => 'success',
            'data' => $clients,
            // Every store, so the form can show which client each one is billed to.
            'stores' => Store::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'platform', 'client_id']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = $request->user()->currentOrganization()->id;
        $validated = $this->validated($request);

        $client = DB::transaction(function () use ($orgId, $validated) {
            $client = Client::create(['organization_id' => $orgId, ...collect($validated)->except('store_ids')->all()]);
            $this->assignStores($client, $validated['store_ids'] ?? []);

            return $client;
        });

        return response()->json(['status' => 'success', 'message' => "Client {$client->name} added.", 'data' => $this->present($client->load('stores'))], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);
        $validated = $this->validated($request, $client);

        DB::transaction(function () use ($client, $validated) {
            $client->update(collect($validated)->except('store_ids')->all());
            if (array_key_exists('store_ids', $validated)) {
                $this->assignStores($client, $validated['store_ids'] ?? []);
            }
        });

        return response()->json(['status' => 'success', 'message' => "Client {$client->name} saved.", 'data' => $this->present($client->load('stores'))]);
    }

    /**
     * A client with invoices is kept for the records; it can be made inactive instead.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);

        if ($client->invoices()->exists()) {
            return response()->json(['status' => 'error', 'message' => "{$client->name} has invoices, so it is kept for the records. Make it inactive instead."], 422);
        }

        $client->delete();

        return response()->json(['status' => 'success', 'message' => "Client {$client->name} removed."]);
    }

    /**
     * What the client owes for a period so far (this month by default), before an invoice is made.
     */
    public function charges(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($validated['from'] ?? now()->startOfMonth());
        $to = CarbonImmutable::parse($validated['to'] ?? now());

        return response()->json([
            'status' => 'success',
            'data' => [...$this->billing->charges($client, $from, $to), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $client->currency],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Client $client = null): array
    {
        $orgId = $request->user()->currentOrganization()->id;
        $rate = ['required', 'numeric', 'min:0', 'max:9999999'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('clients')->where('organization_id', $orgId)->ignore($client?->id)],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'storage_per_unit_day' => $rate,
            'receiving_per_unit' => $rate,
            'order_fee' => $rate,
            'additional_unit_fee' => $rate,
            'return_fee' => $rate,
            'monthly_minimum' => $rate,
            'is_active' => ['sometimes', 'boolean'],
            'store_ids' => ['sometimes', 'array'],
            'store_ids.*' => ['integer', 'distinct', $this->inOrganization($request, 'stores')],
        ], [
            'code.unique' => 'Another client already uses this code.',
        ]);

        return ['currency' => strtoupper($validated['currency'])] + $validated;
    }

    /**
     * The chosen stores are billed to the client (moving them from another client); its other stores are released.
     *
     * @param  list<int>  $storeIds
     */
    private function assignStores(Client $client, array $storeIds): void
    {
        Store::where('client_id', $client->id)->whereNotIn('id', $storeIds)->update(['client_id' => null]);
        Store::where('organization_id', $client->organization_id)->whereIn('id', $storeIds)->update(['client_id' => $client->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Client $client): array
    {
        return [
            ...$client->only(['id', 'name', 'code', 'contact_name', 'email', 'phone', 'billing_address', 'currency', 'payment_terms_days', 'is_active', ...Client::RATES]),
            'stores' => $client->stores->map->only(['id', 'name', 'platform'])->values(),
            'outstanding' => round((float) ($client->outstanding ?? 0), 2),
            'overdue_invoices' => (int) ($client->overdue_invoices ?? 0),
            'billed_until' => $client->billed_until ? CarbonImmutable::parse($client->billed_until)->toDateString() : null,
        ];
    }

    private function authorizeClient(Request $request, Client $client): void
    {
        abort_unless($client->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}
