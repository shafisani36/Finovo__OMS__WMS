<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Raising customer returns: by staff for a shipped order, or by Finovo when a courier brings a parcel back.
 */
class OrderReturnService
{
    /** Orders in these statuses have goods with the customer (or the courier) that can come back. */
    public const RETURNABLE_ORDER_STATUSES = ['partially_shipped', 'shipped', 'delivered', 'returned'];

    public const RESOLUTIONS = ['refund', 'replacement'];

    public function __construct(
        protected OrderService $orders
    ) {}

    /**
     * Raise a return for some of the order's shipped units. The goods go back to the warehouse they left from.
     *
     * @param  array<int, int>  $quantities  units by order item ID
     *
     * @throws ValidationException
     */
    public function request(Order $order, array $quantities, string $reason, ?string $notes = null, ?int $userId = null): OrderReturn
    {
        return DB::transaction(function () use ($order, $quantities, $reason, $notes, $userId) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->order_status, self::RETURNABLE_ORDER_STATUSES, true)) {
                throw ValidationException::withMessages(['order_id' => "Order {$order->order_number} has not been shipped, so nothing can be returned yet."]);
            }

            $returnable = $this->returnableQuantities($order);
            $lines = [];

            foreach ($quantities as $itemId => $quantity) {
                $item = $order->items->firstWhere('id', $itemId);
                $left = $returnable[$itemId] ?? 0;

                if (! $item || $quantity > $left) {
                    throw ValidationException::withMessages(['items' => "Only {$left} unit(s) of ".($item?->product_name ?? 'this item').' can be returned.']);
                }

                $lines[] = ['item' => $item, 'quantity' => (int) $quantity];
            }

            $return = OrderReturn::create([
                'organization_id' => $order->organization_id,
                'order_id' => $order->id,
                'warehouse_id' => collect($lines)->map(fn (array $line) => $line['item']->allocated_warehouse_id)->filter()->first(),
                'created_by_user_id' => $userId,
                'return_number' => OrderReturn::nextNumber($order->organization_id),
                'status' => 'requested',
                'reason' => $reason,
                'notes' => $notes,
                'items_count' => array_sum(array_column($lines, 'quantity')),
            ]);

            foreach ($lines as $line) {
                $return->items()->create([
                    'order_item_id' => $line['item']->id,
                    'product_variant_id' => $line['item']->product_variant_id,
                    'sku' => $line['item']->external_sku,
                    'product_name' => $line['item']->product_name,
                    'quantity' => $line['quantity'],
                ]);
            }

            return $return;
        });
    }

    /**
     * Units of each order line that can still come back: shipped minus already on a return that was not rejected.
     *
     * @return array<int, int> units by order item ID (lines with nothing left are included as 0)
     */
    public function returnableQuantities(Order $order): array
    {
        $order->loadMissing('items');
        $returned = $this->returnedQuantities($order->items->pluck('id')->all());

        return $order->items
            ->mapWithKeys(fn ($item) => [$item->id => max(0, (int) $item->fulfilled_quantity - ($returned[$item->id] ?? 0))])
            ->all();
    }

    /**
     * Units already on a return (not rejected), by order item ID.
     *
     * @param  list<int>  $orderItemIds
     * @return array<int, int>
     */
    public function returnedQuantities(array $orderItemIds): array
    {
        return ReturnItem::whereIn('order_item_id', $orderItemIds)
            ->whereHas('orderReturn', fn (Builder $r) => $r->where('status', '!=', 'rejected'))
            ->selectRaw('order_item_id, sum(quantity) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Settles a completed return once: money back (up to what was paid and not refunded yet) or the same items
     * sent again on a new, free order.
     *
     * @param  array{resolution: string, amount?: float|null, method?: string|null}  $data
     */
    public function resolve(OrderReturn $return, array $data, User $user): OrderReturn
    {
        $return = DB::transaction(function () use ($return, $data, $user) {
            $return = OrderReturn::with(['items', 'order'])->lockForUpdate()->findOrFail($return->id);
            if ($return->status !== 'completed') {
                throw ValidationException::withMessages(['resolution' => "Return {$return->return_number} must be inspected (completed) before it is refunded or replaced."]);
            }
            if ($return->resolution !== null) {
                throw ValidationException::withMessages(['resolution' => "Return {$return->return_number} was already settled with a {$return->resolution}."]);
            }

            // A replacement is claimed here and its order created after the lock is released (creating it may
            // call the store), so two clicks never send the items twice.
            $data['resolution'] === 'refund'
                ? $this->refund($return, (float) $data['amount'], $data['method'] ?? null, $user)
                : $return->update(['resolution' => 'replacement', 'resolved_at' => now()]);

            return $return;
        });

        if ($data['resolution'] === 'replacement') {
            try {
                $this->replace($return, $user);
            } catch (Throwable $e) {
                $return->update(['resolution' => null, 'resolved_at' => null]);
                throw $e;
            }
        }

        return $return;
    }

    private function refund(OrderReturn $return, float $amount, ?string $method, User $user): void
    {
        $order = Order::lockForUpdate()->findOrFail($return->order_id);
        if (! in_array($order->payment_status, ['paid', 'partially_refunded'], true)) {
            throw ValidationException::withMessages(['amount' => "Order {$order->order_number} is not paid, so there is nothing to refund. Choose a replacement instead."]);
        }

        $refunded = (float) OrderReturn::where('order_id', $order->id)->sum('refund_amount');
        $left = round((float) $order->total_amount - $refunded, 2);
        if ($amount <= 0 || round($amount, 2) > $left) {
            throw ValidationException::withMessages(['amount' => "Up to {$order->currency} ".number_format($left, 2)." can still be refunded for order {$order->order_number}."]);
        }

        $return->update(['resolution' => 'refund', 'refund_amount' => round($amount, 2), 'refund_method' => $method, 'resolved_at' => now()]);
        $order->update(['payment_status' => round($amount, 2) >= $left ? 'refunded' : 'partially_refunded']);
        $order->record('refunded', "Refunded {$order->currency} ".number_format($amount, 2)." for return {$return->return_number}".($method ? " by {$method}" : ''), $user->id);
    }

    private function replace(OrderReturn $return, User $user): void
    {
        $order = $return->order;
        $lines = $return->items->whereNotNull('product_variant_id')->groupBy('product_variant_id')->map(fn ($items, $variantId) => [
            'product_variant_id' => (int) $variantId,
            'quantity' => (int) $items->sum('quantity'),
            'unit_price' => 0,
        ])->values()->all();
        if ($lines === []) {
            throw ValidationException::withMessages(['resolution' => "The items of return {$return->return_number} are not catalog products, so they cannot be sent again. Refund it instead."]);
        }

        [$replacement] = $this->orders->create($user, [
            'store_id' => $order->store_id,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'shipping_address' => $order->shipping_address,
            'billing_address' => $order->billing_address,
            'payment_status' => 'paid',
            'currency' => $order->currency,
            'notes' => "Replacement for return {$return->return_number} of order {$order->order_number}.",
            'items' => $lines,
        ]);

        $return->update(['replacement_order_id' => $replacement->id]);
        $order->record('replaced', "Replacement order {$replacement->order_number} created for return {$return->return_number}", $user->id);
    }
}
