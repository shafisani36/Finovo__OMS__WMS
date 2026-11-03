<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Couriers\Parcel;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\CourierService;
use App\Services\ShipmentDocumentService;
use App\Services\ShipmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Packed orders waiting for dispatch, and the parcels handed to a carrier: dispatch (one or many), cancel a
 * dispatch, delivery, courier tracking, labels and the courier's load sheet.
 */
class ShipmentController extends Controller
{
    /** List filter for parcels a courier could not deliver or cancelled. */
    private const ISSUES = 'issues';

    /** Most shipments one bulk action may take. */
    private const BULK_LIMIT = 100;

    private const RELATIONS = ['order.store', 'order.items', 'warehouse', 'dispatchedBy', 'courierAccount'];

    public function __construct(
        protected ShipmentService $shipments,
        protected CourierService $couriers,
        protected ShipmentDocumentService $documents,
    ) {}

    /**
     * The company's active courier accounts (to book with at dispatch) and the carriers that can be entered by hand.
     */
    public function couriers(Request $request): JsonResponse
    {
        $accounts = CourierAccount::where('organization_id', $request->user()->currentOrganization()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CourierAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'carrier' => $account->carrier,
                'carrier_name' => CourierService::carrierName($account->carrier),
                'default_weight_kg' => Parcel::defaultWeight($account),
            ]);

        return response()->json(['status' => 'success', 'data' => $accounts, 'carriers' => array_keys(Shipment::CARRIERS)]);
    }

    /**
     * List shipments with KPIs. ?status= is a shipment status or "issues"; with ?store_id= only that store's
     * orders are listed and counted.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([...Shipment::STATUSES, self::ISSUES])]]);
        $scoped = fn () => $this->scoped($request)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->whereHas('order', fn ($o) => $o->where('store_id', $storeId)));

        $shipments = $scoped()
            ->with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $status === self::ISSUES ? $this->issues($q) : $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('tracking_number', 'like', "%{$search}%")
                ->orWhere('carrier', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($o) => $o
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"))))
            // Orders waiting for dispatch first, then the most recent.
            ->orderByRaw('case when status = ? then 0 else 1 end', [Shipment::PENDING])
            ->latest('id')
            ->paginate($this->perPage($request, 10));

        $byStatus = $scoped()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'status' => 'success',
            'data' => collect($shipments->items())->map(fn (Shipment $shipment) => $this->present($shipment)),
            'meta' => [
                'current_page' => $shipments->currentPage(),
                'last_page' => $shipments->lastPage(),
                'per_page' => $shipments->perPage(),
                'total' => $shipments->total(),
            ],
            'kpis' => [
                'ready_to_dispatch' => (int) ($byStatus[Shipment::PENDING] ?? 0),
                'dispatched_today' => $scoped()->whereIn('status', [Shipment::SHIPPED, Shipment::DELIVERED, Shipment::RETURNED])->whereDate('shipped_at', today())->count(),
                'in_transit' => (int) ($byStatus[Shipment::SHIPPED] ?? 0),
                'issues' => $this->issues($scoped())->count(),
                'delivered' => (int) ($byStatus[Shipment::DELIVERED] ?? 0),
                'returned' => (int) ($byStatus[Shipment::RETURNED] ?? 0),
            ],
        ]);
    }

    /**
     * One shipment with its order lines, delivery address, parcel details and timeline.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $shipment = $this->find($request, $id)->load([...self::RELATIONS, 'events.user']);
        $order = $shipment->order;

        return response()->json([
            'status' => 'success',
            'data' => [
                ...$this->present($shipment),
                'customer_email' => $order?->customer_email,
                'customer_phone' => $order?->customer_phone,
                'shipping_address' => $order?->shipping_address,
                'payment_status' => $order?->payment_status,
                'notes' => $order?->notes,
                'items' => $order?->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'sku' => $item->external_sku,
                    'quantity' => (int) $item->quantity,
                    'status' => $item->status,
                ])->values() ?? [],
                'events' => $shipment->events->map(fn (ShipmentEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'courier_status' => $event->courier_status,
                    'description' => $event->description,
                    'user' => $event->user?->name,
                    'happened_at' => $event->happened_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * Hand a packed order to a carrier: booked with a courier account, or the carrier and tracking entered by hand.
     */
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            ...$this->carrierRules($request),
            // Left empty, Finovo generates a unique tracking ID.
            'tracking_number' => 'nullable|string|max:100',
            'tracking_url' => 'nullable|url:http,https|max:500',
            ...$this->parcelRules(),
            'cod_amount' => 'nullable|numeric|min:0|max:99999999',
        ]);

        ['shipment' => $shipment, 'emailed' => $emailed] = $this->shipments->dispatch($this->find($request, $id), $validated, $request->user());
        $order = $shipment->order;

        return response()->json([
            'status' => 'success',
            'message' => "Order {$order->order_number} dispatched with {$shipment->carrier} (tracking ID {$shipment->tracking_number}). ".match (true) {
                $emailed => "Tracking details were emailed to {$order->customer_email}.",
                blank($order->customer_email) => 'No tracking email was sent because the order has no customer email address.',
                default => 'The tracking email could not be sent; check the mail settings.',
            },
            'data' => $this->present($shipment),
            'customer_emailed' => $emailed,
        ]);
    }

    /**
     * Dispatch several packed orders with one carrier; each order is reported on its own.
     */
    public function dispatchMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->idsRules(),
            ...$this->carrierRules($request),
            ...$this->parcelRules(),
        ]);

        $results = $this->shipments->dispatchMany($this->findMany($request, $validated['shipment_ids']), $validated, $request->user());
        $dispatched = count(array_filter(array_column($results, 'dispatched')));
        $failed = count($results) - $dispatched;

        return response()->json([
            'status' => 'success',
            'message' => "{$dispatched} of ".count($results).' orders dispatched.'.($failed ? " {$failed} could not be dispatched; see the list." : ''),
            'data' => $results,
        ]);
    }

    /**
     * Take back a dispatch the courier has not collected yet.
     */
    public function cancelDispatch(Request $request, int $id): JsonResponse
    {
        $shipment = $this->find($request, $id);
        $orderNumber = $shipment->order?->order_number;
        $note = $this->shipments->cancelDispatch($shipment, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => trim("Dispatch of order {$orderNumber} cancelled; its stock is back in the warehouse and it is ready to dispatch again. {$note}"),
            'data' => $this->present($shipment->fresh(self::RELATIONS)),
        ]);
    }

    /**
     * Confirm that a dispatched parcel reached the customer.
     */
    public function deliver(Request $request, int $id): JsonResponse
    {
        $shipment = $this->find($request, $id);

        if (! $this->shipments->deliver($shipment, $request->user())) {
            throw ValidationException::withMessages(['status' => $shipment->status === Shipment::DELIVERED
                ? 'This shipment is already marked as delivered.'
                : 'Only a parcel on its way can be marked delivered.']);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order {$shipment->order->order_number} marked as delivered.",
            'data' => $this->present($shipment->fresh(self::RELATIONS)),
        ]);
    }

    /**
     * The courier's label for a booked parcel: a link to open, or the PDF.
     */
    public function label(Request $request, int $id): JsonResponse|Response
    {
        $shipment = $this->find($request, $id);
        $label = $this->couriers->label($shipment);

        if ($label->url) {
            return response()->json(['status' => 'success', 'data' => ['url' => $label->url]]);
        }

        return $this->pdf($label->pdf, "Label-{$shipment->tracking_number}.pdf");
    }

    /**
     * Finovo shipping labels for dispatched parcels, one per page (works for every carrier).
     */
    public function labels(Request $request): Response
    {
        $validated = $request->validate($this->idsRules());

        return $this->pdf($this->documents->labels($this->dispatched($request, $validated['shipment_ids'])), 'Shipping-labels.pdf');
    }

    /**
     * The load sheet the courier signs when collecting the parcels, one page per carrier.
     */
    public function loadSheet(Request $request): Response
    {
        $validated = $request->validate($this->idsRules());

        return $this->pdf($this->documents->loadSheet($this->dispatched($request, $validated['shipment_ids'])), 'Load-sheet.pdf');
    }

    /**
     * Ask the couriers now for the latest status of the parcels on their way.
     */
    public function track(Request $request): JsonResponse
    {
        $accounts = CourierAccount::where('organization_id', $request->user()->currentOrganization()->id)->where('is_active', true)->get();
        $totals = ['checked' => 0, 'updated' => 0, 'delivered' => 0, 'returned' => 0, 'failed_accounts' => 0];

        foreach ($accounts as $account) {
            foreach ($this->shipments->syncCourierTracking($account) as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Checked {$totals['checked']} parcels with the couriers: {$totals['updated']} updated, {$totals['delivered']} delivered, {$totals['returned']} returned."
                .($totals['failed_accounts'] ? " {$totals['failed_accounts']} courier account(s) could not be reached; see Courier Accounts." : ''),
            'data' => $totals,
        ]);
    }

    /**
     * Shipments of the user's organization, in the warehouses the user works in.
     *
     * @return Builder<Shipment>
     */
    private function scoped(Request $request): Builder
    {
        return $this->inUserWarehouses(Shipment::where('organization_id', $request->user()->currentOrganization()->id), $request);
    }

    /**
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    private function issues(Builder $query): Builder
    {
        return $query->where('status', Shipment::SHIPPED)->whereIn('courier_status', ShipmentService::PROBLEM_COURIER_STATUSES);
    }

    private function find(Request $request, int $id): Shipment
    {
        return $this->scoped($request)->with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Shipment>
     */
    private function findMany(Request $request, array $ids): Collection
    {
        $shipments = $this->scoped($request)->with(self::RELATIONS)->whereKey($ids)->orderBy('id')->get();

        if ($shipments->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['shipment_ids' => 'Some of the chosen shipments were not found.']);
        }

        return $shipments;
    }

    /**
     * Dispatched shipments (labels and load sheets are printed for parcels that have a tracking ID).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Shipment>
     */
    private function dispatched(Request $request, array $ids): Collection
    {
        $shipments = $this->findMany($request, $ids);

        if ($shipments->contains(fn (Shipment $shipment) => $shipment->status === Shipment::PENDING)) {
            throw ValidationException::withMessages(['shipment_ids' => 'Dispatch the orders first; only dispatched parcels have a tracking ID to print.']);
        }

        return $shipments;
    }

    /**
     * @return array<string, mixed>
     */
    private function carrierRules(Request $request): array
    {
        return [
            // Book the parcel with one of the company's courier accounts, or name the carrier.
            'courier_account_id' => ['nullable', 'integer', Rule::exists('courier_accounts', 'id')->where('organization_id', $request->user()->currentOrganization()->id)->where('is_active', true)],
            'carrier' => 'required_without:courier_account_id|nullable|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parcelRules(): array
    {
        return [
            'weight_kg' => 'nullable|numeric|min:0.01|max:1000',
            // Parcel size from the packing station: all three or none.
            'length_cm' => 'nullable|required_with:width_cm,height_cm|numeric|min:1|max:500',
            'width_cm' => 'nullable|required_with:length_cm,height_cm|numeric|min:1|max:500',
            'height_cm' => 'nullable|required_with:length_cm,width_cm|numeric|min:1|max:500',
            'pieces' => 'nullable|integer|min:1|max:99',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function idsRules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'shipment_ids.*' => ['integer', 'distinct'],
        ];
    }

    private function pdf(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shipment $shipment): array
    {
        $order = $shipment->order;

        return [
            'id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'order_number' => $order?->order_number,
            'customer' => $order?->customer_name ?: 'Unknown',
            'city' => ((array) ($order?->shipping_address ?? []))['city'] ?? null,
            'store' => $order?->store?->name,
            'items_count' => (int) $order?->items->sum('quantity'),
            'total_amount' => $order?->total_amount,
            'currency' => $order?->currency,
            'warehouse' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->trackingLink(),
            'status' => $shipment->status,
            'weight_kg' => $shipment->weight_kg !== null ? (float) $shipment->weight_kg : null,
            'dimensions_cm' => $shipment->length_cm !== null ? [(float) $shipment->length_cm, (float) $shipment->width_cm, (float) $shipment->height_cm] : null,
            'pieces' => (int) $shipment->pieces,
            'cod_amount' => $shipment->cashToCollect(),
            'packed_at' => $shipment->created_at?->toIso8601String(),
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'returned_at' => $shipment->returned_at?->toIso8601String(),
            'dispatched_by' => $shipment->dispatchedBy?->name,
            'booked_with_courier' => $shipment->courier_account_id !== null,
            'has_label' => $shipment->label_url !== null || CourierService::makesLabels($shipment->courierAccount?->carrier),
            'can_cancel_dispatch' => $this->shipments->canCancelDispatch($shipment),
            'courier_status' => $shipment->courier_status,
            'courier_status_text' => $shipment->courier_status_text,
            'courier_status_at' => $shipment->courier_status_at?->toIso8601String(),
        ];
    }
}
