<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PickTask;
use App\Models\Shipment;
use App\Services\EmailService;
use App\Services\InventoryEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Packed orders waiting for dispatch, and the parcels already handed to a carrier.
 */
class ShipmentController extends Controller
{
    public const STATUSES = ['pending', 'shipped', 'delivered'];

    public function __construct(
        protected InventoryEngine $inventoryEngine,
        protected EmailService $emails,
    ) {}

    /**
     * List shipments with KPIs. With ?store_id= only that store's orders are listed and counted.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->user()->currentOrganization();

        $scoped = fn () => Shipment::where('organization_id', $org->id)
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
            'carrier' => 'required|string|max:100',
            // Left empty, Finovo generates a unique tracking ID.
            'tracking_number' => 'nullable|string|max:100',
            'tracking_url' => 'nullable|url:http,https|max:500',
        ]);

        $shipment = DB::transaction(function () use ($request, $id, $validated) {
            $shipment = $this->find($request, $id, lock: true);

            if ($shipment->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This shipment has already been dispatched.']);
            }

            $trackingNumber = trim((string) ($validated['tracking_number'] ?? ''));
            if ($trackingNumber === '') {
                $trackingNumber = $shipment->generateTrackingNumber();
            } elseif (Shipment::trackingNumberTaken($shipment->organization_id, $trackingNumber, $shipment->id)) {
                throw ValidationException::withMessages(['tracking_number' => "Tracking ID {$trackingNumber} is already used by another shipment."]);
            }

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
                ...$validated,
                'tracking_number' => $trackingNumber,
                'status' => 'shipped',
                'shipped_at' => now(),
                'dispatched_by_user_id' => $request->user()->id,
            ]);

            return $shipment;
        });

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
     * Confirm that a dispatched parcel reached the customer.
     */
    public function deliver(Request $request, int $id): JsonResponse
    {
        $shipment = DB::transaction(function () use ($request, $id) {
            $shipment = $this->find($request, $id, lock: true);

            if ($shipment->status !== 'shipped') {
                throw ValidationException::withMessages(['status' => $shipment->status === 'delivered'
                    ? 'This shipment is already marked as delivered.'
                    : 'Dispatch the shipment before marking it delivered.']);
            }

            $shipment->update(['status' => 'delivered', 'delivered_at' => now()]);

            if ($shipment->order->order_status === 'shipped') {
                $shipment->order->update(['order_status' => 'delivered']);
            }

            return $shipment;
        });

        return response()->json([
            'status' => 'success',
            'message' => "Order {$shipment->order->order_number} marked as delivered.",
            'data' => $this->present($shipment->fresh(['order.store', 'order.items', 'warehouse', 'dispatchedBy'])),
        ]);
    }

    private function find(Request $request, int $id, bool $lock = false): Shipment
    {
        return Shipment::where('organization_id', $request->user()->currentOrganization()->id)
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
        ];
    }
}
