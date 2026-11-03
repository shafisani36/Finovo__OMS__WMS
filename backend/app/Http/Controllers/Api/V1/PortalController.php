<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\SkuMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 3PL client portal: what a client's own user can see. Everything is limited to the stores linked to the user's
 * client; nothing can be changed here.
 */
class PortalController extends Controller
{
    /** Shipment updates listed on the activity feed. */
    private const ACTIVITY_LIMIT = 50;

    /**
     * Stock of the client's products, summed over the warehouses holding it.
     */
    public function stock(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $variantIds = SkuMapping::whereIn('store_id', $client->stores()->pluck('id'))->distinct()->pluck('product_variant_id');

        $stock = InventoryBalance::with('variant.product:id,name')
            ->where('organization_id', $client->organization_id)
            ->whereIn('product_variant_id', $variantIds)
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn ($balances) => [
                'sku' => $balances->first()->variant?->sku,
                'product_name' => $balances->first()->variant?->product?->name,
                'on_hand' => (int) $balances->sum('on_hand'),
                'reserved' => (int) $balances->sum('reserved'),
                'available' => (int) $balances->sum('available'),
            ])
            ->sortBy('sku')
            ->values();

        return response()->json(['status' => 'success', 'client' => ['name' => $client->name], 'data' => $stock]);
    }

    /**
     * The client's orders, newest first, with their shipment (?search= by order number or customer).
     */
    public function orders(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $orders = Order::with(['store:id,name', 'shipments' => fn ($q) => $q->latest('id')])
            ->whereIn('store_id', $this->client($request)->stores()->pluck('id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('order_number', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($orders->items())->map(function (Order $order) {
                $shipment = $order->shipments->first();

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'store' => $order->store?->name,
                    'customer' => $order->customer_name,
                    'status' => $order->order_status,
                    'total_amount' => (float) $order->total_amount,
                    'currency' => $order->currency,
                    'placed_at' => $order->placed_at?->toIso8601String(),
                    'shipment' => $shipment ? [
                        'status' => $shipment->status,
                        'carrier' => $shipment->carrier,
                        'tracking_number' => $shipment->tracking_number,
                        'tracking_url' => $shipment->trackingLink(),
                    ] : null,
                ];
            }),
            'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()],
        ]);
    }

    /**
     * Latest shipment updates of the client's orders: dispatched, courier scans, delivered, returned.
     */
    public function activity(Request $request): JsonResponse
    {
        $shipmentIds = Shipment::whereHas('order', fn ($q) => $q->whereIn('store_id', $this->client($request)->stores()->pluck('id')))->pluck('id');

        $events = ShipmentEvent::with('shipment.order:id,order_number')
            ->whereIn('shipment_id', $shipmentIds)
            ->latest('happened_at')
            ->latest('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ShipmentEvent $event) => [
                'id' => $event->id,
                'order_number' => $event->shipment?->order?->order_number,
                'description' => $event->description,
                'happened_at' => $event->happened_at?->toIso8601String(),
            ]);

        return response()->json(['status' => 'success', 'data' => $events]);
    }

    private function client(Request $request): Client
    {
        return Client::where('organization_id', $request->user()->currentOrganization()->id)->findOrFail($request->user()->client_id);
    }
}
