<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\OrderControlService;
use App\Services\OrderReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Customer self-service, without an account: the order number and the email it was placed with open the order.
 * Customers follow their order and parcels, ask to cancel an order that has not shipped (it is held for staff to
 * decide) and ask to return shipped items (a return request staff then receive and inspect).
 */
class PublicOrderController extends Controller
{
    /** Order statuses a customer can still ask to cancel (nothing has left the warehouse). */
    private const CANCELLABLE = ['imported', 'pending', 'exception', 'allocated', 'partially_allocated', 'picking', 'picked'];

    public function track(Request $request, OrderReturnService $returns): JsonResponse
    {
        $order = $this->find($request);
        $returnable = in_array($order->order_status, OrderReturnService::RETURNABLE_ORDER_STATUSES, true) ? $returns->returnableQuantities($order) : [];

        return response()->json(['status' => 'success', 'data' => [
            'order_number' => $order->order_number,
            'status' => $order->order_status,
            'placed_at' => $order->placed_at,
            'total' => $order->total_amount,
            'currency' => $order->currency,
            'store' => $order->store?->name,
            'on_hold' => $order->hold_type !== null,
            'can_cancel' => $order->hold_type === null && in_array($order->order_status, self::CANCELLABLE, true),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'item_id' => $item->id,
                'name' => $item->product_name,
                'quantity' => $item->quantity,
                'returnable_quantity' => $returnable[$item->id] ?? 0,
            ])->values(),
            'shipments' => $order->shipments->map(fn (Shipment $shipment) => [
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->trackingLink(),
                'status' => $shipment->status,
                'events' => $shipment->events->map(fn ($event) => ['description' => $event->description, 'at' => $event->happened_at])->values(),
            ])->values(),
            'returns' => $order->returns->map(fn ($return) => [
                'return_number' => $return->return_number,
                'status' => $return->status,
                'resolution' => $return->resolution,
            ])->values(),
        ]]);
    }

    /**
     * The order is held with the customer's reason; staff cancel it (or release it) from the Orders page.
     */
    public function requestCancellation(Request $request, OrderControlService $controls): JsonResponse
    {
        $order = $this->find($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        if ($order->hold_type !== null || ! in_array($order->order_status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages(['order' => 'This order can no longer be cancelled online. Please contact the store.']);
        }

        $controls->hold($order, 'manual', "Customer asked to cancel: {$reason}");

        return response()->json(['status' => 'success', 'message' => 'Your request was sent. The order is paused while the store reviews it.']);
    }

    public function requestReturn(Request $request, OrderReturnService $returns): JsonResponse
    {
        $order = $this->find($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $return = $returns->request($order, collect($data['items'])->pluck('quantity', 'item_id')->all(), "Customer request: {$data['reason']}");

        return response()->json([
            'status' => 'success',
            'message' => "Return {$return->return_number} was requested. The store will tell you where to send the items.",
            'data' => ['return_number' => $return->return_number, 'status' => $return->status],
        ], 201);
    }

    /**
     * The order with this number placed with this email (the same answer for a wrong number or email, so
     * nothing can be learned by guessing).
     */
    private function find(Request $request): Order
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $order = Order::where('order_number', trim($data['order_number']))
            ->whereRaw('LOWER(customer_email) = ?', [strtolower(trim($data['email']))])
            ->with(['store:id,name', 'items', 'shipments.events', 'returns'])
            ->latest('id')
            ->first();

        abort_unless($order !== null, 404, 'No order matches that number and email.');

        return $order;
    }
}
