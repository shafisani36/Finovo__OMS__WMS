<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\Couriers\CourierStatus;
use App\Models\CourierAccount;
use App\Models\PickTask;
use App\Models\Shipment;
use App\Services\CourierService;
use App\Services\EmailService;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Packed orders waiting for dispatch, and the parcels already handed to a carrier.
 */
class ShipmentController extends Controller
{
    public const STATUSES = ['pending', 'shipped', 'delivered'];

    public function __construct(
        protected InventoryEngine $inventoryEngine,
        protected EmailService $emails,
        protected CourierService $couriers,
    ) {}

    /**
     * The company's active courier accounts, to book parcels with at dispatch.
     */
    public function couriers(Request $request): JsonResponse
    {
        $accounts = CourierAccount::where('organization_id', $request->user()->currentOrganization()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'carrier', 'name'])
            ->map(fn (CourierAccount $account) => [
                ...$account->only(['id', 'carrier', 'name']),
                'carrier_name' => CourierService::CARRIERS[$account->carrier]['name'] ?? $account->carrier,
            ]);

        return response()->json(['status' => 'success', 'data' => $accounts]);
    }

    /**
     * List shipments with KPIs. With ?store_id= only that store's orders are listed and counted.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $scoped = fn () => $this->inUserWarehouses(Shipment::where('organization_id', $org->id), $request)
            ->whereIn('status', self::STATUSES)
            ->when($request->integer('store_id'), fn ($q, $storeId) => $q->whereHas('order', fn ($o) => $o->where('store_id', $storeId)));

        $shipments = $scoped()
            ->with(['order.store', 'order.items', 'warehouse', 'dispatchedBy'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('tracking_number', 'like', "%{$search}%")
                ->orWhere('carrier', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($o) => $o
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%"))))
            // Orders waiting for dispatch first, then the most recent.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
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
                'ready_to_dispatch' => (int) ($byStatus['pending'] ?? 0),
                'dispatched_today' => $scoped()->whereIn('status', ['shipped', 'delivered'])->whereDate('shipped_at', today())->count(),
                'in_transit' => (int) ($byStatus['shipped'] ?? 0),
                'delivered' => (int) ($byStatus['delivered'] ?? 0),
            ],
        ]);
    }

    /**
     * Hand a packed order to a carrier: stock leaves the warehouse, the order is marked shipped and the customer
     * is emailed the tracking details.
     */
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            // Book the parcel with one of the company's courier accounts, or name the carrier and enter its details.
            'courier_account_id' => ['nullable', 'integer', Rule::exists('courier_accounts', 'id')->where('organization_id', $request->user()->currentOrganization()->id)->where('is_active', true)],
            'carrier' => 'required_without:courier_account_id|nullable|string|max:100',
            // Left empty, Finovo generates a unique tracking ID.
            'tracking_number' => 'nullable|string|max:100',
            'tracking_url' => 'nullable|url:http,https|max:500',
        ]);

        $account = isset($validated['courier_account_id']) ? CourierAccount::find($validated['courier_account_id']) : null;
        $booking = null;

        if ($account) {
            // The courier is called before the database work (and outside its transaction); a booking that cannot be
            // saved afterwards is cancelled again.
            $pending = $this->find($request, $id);
            if ($pending->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This shipment has already been dispatched.']);
            }
            if (! $pending->order?->items()->where('status', 'allocated')->exists()) {
                throw ValidationException::withMessages(['status' => "Order {$pending->order?->order_number} has no allocated items to dispatch."]);
            }
            $booking = $this->couriers->book($pending, $account);
            $validated = [
                'carrier' => CourierService::CARRIERS[$account->carrier]['name'],
                'courier_account_id' => $account->id,
                'tracking_number' => $booking->trackingNumber,
                'tracking_url' => null,
                'label_url' => $booking->labelUrl,
                'courier_status' => CourierStatus::BOOKED,
                'courier_status_text' => 'Booked',
                'courier_status_at' => now(),
            ];
        }

        try {
            $shipment = $this->dispatchShipment($request, $id, $validated);
        } catch (Throwable $e) {
            if ($account && $booking) {
                $this->couriers->cancelQuietly($account, $booking->trackingNumber);
            }

            throw $e;
        }

        $shipment = $shipment->fresh(['order.store', 'order.items', 'warehouse', 'dispatchedBy']);
        $emailed = $this->emails->sendShipmentEmail($shipment);

        return response()->json([
            'status' => 'success',
            'message' => "Order {$shipment->order->order_number} dispatched with {$shipment->carrier} (tracking ID {$shipment->tracking_number}). ".match (true) {
                $emailed => "Tracking details were emailed to {$shipment->order->customer_email}.",
                blank($shipment->order->customer_email) => 'No tracking email was sent because the order has no customer email address.',
                default => 'The tracking email could not be sent; check the mail settings.',
            },
            'data' => $this->present($shipment),
            'customer_emailed' => $emailed,
        ]);
    }

    /**
     * Stock leaves the warehouse and the order is marked shipped, in one transaction.
     *
     * @param  array<string, mixed>  $details  carrier, tracking and courier fields to save on the shipment
     */
    private function dispatchShipment(Request $request, int $id, array $details): Shipment
    {
        return DB::transaction(function () use ($request, $id, $details) {
            $shipment = $this->find($request, $id, lock: true);

            if ($shipment->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This shipment has already been dispatched.']);
            }

            $trackingNumber = trim((string) ($details['tracking_number'] ?? ''));
            if ($trackingNumber === '') {
                $trackingNumber = $shipment->generateTrackingNumber();
            } elseif (Shipment::trackingNumberTaken($shipment->organization_id, $trackingNumber, $shipment->id)) {
                throw ValidationException::withMessages(['tracking_number' => "Tracking ID {$trackingNumber} is already used by another shipment."]);
            }
            $details['tracking_number'] = $trackingNumber;

            $order = $shipment->order()->with('items')->lockForUpdate()->firstOrFail();
            $shippable = $order->items->filter(fn ($item) => $item->status === 'allocated' && $item->allocated_warehouse_id && $item->product_variant_id);

            if ($shippable->isEmpty()) {
                throw ValidationException::withMessages(['status' => "Order {$order->order_number} has no allocated items to dispatch."]);
            }

            foreach ($shippable as $item) {
                $this->inventoryEngine->deductShipped(
                    organizationId: $order->organization_id,
                    warehouseId: $item->allocated_warehouse_id,
                    productVariantId: $item->product_variant_id,
                    quantity: $item->quantity,
                    orderId: $order->id,
                    userId: $request->user()->id,
                );
                $item->update(['status' => 'shipped', 'fulfilled_quantity' => $item->quantity]);
            }

            $complete = $shippable->count() === $order->items->count();
            $order->update([
                'order_status' => $complete ? 'shipped' : 'partially_shipped',
                'fulfillment_status' => $complete ? 'fulfilled' : 'partially_fulfilled',
            ]);
            $order->pickTasks()->whereNotIn('status', PickTask::CLOSED)->update(['status' => 'shipped']);

            $shipment->update([
                ...$details,
                'status' => 'shipped',
                'shipped_at' => now(),
                'dispatched_by_user_id' => $request->user()->id,
            ]);

            return $shipment;
        });
    }

    /**
     * Confirm that a dispatched parcel reached the customer.
     */
    public function deliver(Request $request, int $id): JsonResponse
    {
        $shipment = $this->find($request, $id);

        if (! $shipment->markDelivered()) {
            throw ValidationException::withMessages(['status' => $shipment->status === 'delivered'
                ? 'This shipment is already marked as delivered.'
                : 'Dispatch the shipment before marking it delivered.']);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Order {$shipment->order->order_number} marked as delivered.",
            'data' => $this->present($shipment->fresh(['order.store', 'order.items', 'warehouse', 'dispatchedBy'])),
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

        return response($label->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"Label-{$shipment->tracking_number}.pdf\"",
        ]);
    }

    /**
     * Ask the couriers now for the latest status of the parcels on their way.
     */
    public function track(Request $request): JsonResponse
    {
        $accounts = CourierAccount::where('organization_id', $request->user()->currentOrganization()->id)->where('is_active', true)->get();
        $totals = ['checked' => 0, 'updated' => 0, 'delivered' => 0, 'failed_accounts' => 0];

        foreach ($accounts as $account) {
            foreach ($this->couriers->syncTracking($account) as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Checked {$totals['checked']} parcels with the couriers: {$totals['updated']} updated, {$totals['delivered']} delivered."
                .($totals['failed_accounts'] ? " {$totals['failed_accounts']} courier account(s) could not be reached; see Courier Accounts." : ''),
            'data' => $totals,
        ]);
    }

    private function find(Request $request, int $id, bool $lock = false): Shipment
    {
        return $this->inUserWarehouses(Shipment::where('organization_id', $request->user()->currentOrganization()->id), $request)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->findOrFail($id);
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
            'store' => $order?->store?->name,
            'items_count' => (int) $order?->items->sum('quantity'),
            'total_amount' => $order?->total_amount,
            'currency' => $order?->currency,
            'warehouse' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->trackingLink(),
            'status' => $shipment->status,
            'packed_at' => $shipment->created_at?->toIso8601String(),
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'delivered_at' => $shipment->delivered_at?->toIso8601String(),
            'dispatched_by' => $shipment->dispatchedBy?->name,
            'booked_with_courier' => $shipment->courier_account_id !== null,
            'has_label' => $shipment->label_url !== null || ($shipment->courier_account_id !== null && (CourierService::CARRIERS[$shipment->courierAccount?->carrier]['labels'] ?? false)),
            'courier_status' => $shipment->courier_status,
            'courier_status_text' => $shipment->courier_status_text,
            'courier_status_at' => $shipment->courier_status_at?->toIso8601String(),
        ];
    }
}
