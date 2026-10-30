<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders created and changed in Finovo: create (also on the order's store), edit while nothing is reserved,
 * cancel (releasing stock) and delete orders created by mistake. Imported orders use cancel() too, so a
 * cancellation from the store releases stock exactly like one made in Finovo.
 */
class OrderService
{
    /** Orders still waiting for stock; only these can be edited. */
    public const EDITABLE_STATUSES = ['imported', 'pending', 'exception'];

    public function __construct(
        protected InventoryEngine $inventory,
        protected StorePushService $push,
        protected EmailService $emails,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated order fields with items[]: product_variant_id, quantity, unit_price
     * @return array{0: Order, 1: PushReport}
     */
    public function create(User $user, array $data): array
    {
        $org = $user->currentOrganization();

        $order = DB::transaction(function () use ($user, $org, $data) {
            $order = Order::create([
                ...$this->orderFields($data),
                'organization_id' => $org->id,
                'store_id' => $data['store_id'] ?? null,
                'created_by' => $user->id,
                'order_number' => $this->nextNumber($org->id),
                'order_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'placed_at' => now(),
            ]);
            $this->saveLines($order, $data['items']);
            $this->addToStoreCatalog($order);

            return $order;
        });

        $this->emails->sendOrderConfirmationEmail($order);

        return [$order->fresh(['items', 'store']), $this->push->createOrder($order->load('store', 'items'))];
    }

    /**
     * Change an order that is still waiting for stock. The lines of an order that came from (or was sent to)
     * a store stay as they are there; its customer, address and note are sent to the store.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Order, 1: PushReport}
     */
    public function update(Order $order, array $data): array
    {
        $this->ensureEditable($order);

        if (isset($data['items']) && $order->external_order_id) {
            throw ValidationException::withMessages([
                'items' => 'This order is on the store, so its products and quantities are changed there. You can still edit the customer, address and note.',
            ]);
        }

        DB::transaction(function () use ($order, $data) {
            $order->update($this->orderFields($data, $order));

            if (isset($data['items'])) {
                $order->items()->delete();
                $this->saveLines($order, $data['items']);
                $this->addToStoreCatalog($order);
            }
        });

        return [$order->fresh(['items', 'store']), $this->push->updateOrder($order->load('store'))];
    }

    /**
     * Products sold in a store kept in Finovo only join its catalog, so they are listed with its products.
     */
    private function addToStoreCatalog(Order $order): void
    {
        $order->loadMissing('store', 'items.variant.product');

        if (! $order->store) {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->variant) {
                SkuMapping::addToFinovoStore($order->store, $item->variant);
            }
        }
    }

    /**
     * Cancel the order: reserved stock is released, it leaves the picking list and a shipment still waiting
     * for dispatch is withdrawn. Orders that already left the warehouse cannot be cancelled.
     *
     * @param  bool  $onStore  also cancel it on its store (false when the store itself cancelled it)
     */
    public function cancel(Order $order, bool $onStore = true): PushReport
    {
        if (in_array($order->order_status, Order::SHIPPED_STATUSES, true)) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} has already shipped and cannot be cancelled."]);
        }

        if ($order->order_status === 'cancelled') {
            return new PushReport;
        }

        DB::transaction(function () use ($order) {
            InventoryReservation::where('order_id', $order->id)
                ->where('status', 'active')
                ->pluck('id')
                ->each(fn (int $id) => $this->inventory->releaseReservation($id));

            $order->update(['order_status' => 'cancelled', 'fulfillment_status' => 'cancelled']);
            $order->items()->update(['status' => 'cancelled']);
            $order->pickTasks()->update(['status' => 'cancelled', 'notes' => 'Order cancelled']);
            $order->shipments()->where('status', 'pending')->delete();
        });

        return $onStore ? $this->push->cancelOrder($order->load('store')) : new PushReport;
    }

    /**
     * Delete an order created in Finovo by mistake. Orders on a store, orders that reserved or moved stock,
     * and invoiced orders are kept for the record; cancel them instead.
     */
    public function delete(Order $order): void
    {
        $reason = match (true) {
            (bool) $order->external_order_id => 'it is on the store',
            (bool) $order->invoice_number => 'an invoice was issued for it',
            ! in_array($order->order_status, [...self::EDITABLE_STATUSES, 'cancelled'], true) => 'stock was reserved or moved for it',
            InventoryReservation::where('order_id', $order->id)->exists() || $order->pickTasks()->exists() || $order->shipments()->exists() || $order->returns()->exists() => 'stock was reserved or moved for it',
            default => null,
        };

        if ($reason) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} cannot be deleted because {$reason}. Cancel it instead."]);
        }

        DB::transaction(function () use ($order) {
            $order->items()->delete();
            $order->delete();
        });
    }

    public function ensureEditable(Order $order): void
    {
        $reserved = InventoryReservation::where('order_id', $order->id)->where('status', 'active')->exists();

        if (! in_array($order->order_status, self::EDITABLE_STATUSES, true) || $reserved) {
            throw ValidationException::withMessages([
                'order' => "Order {$order->order_number} can no longer be edited because stock has been reserved for it or it has moved on.",
            ]);
        }
    }

    /**
     * Customer, address, payment and amount fields from the request; totals are worked out from the lines.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function orderFields(array $data, ?Order $order = null): array
    {
        $fields = collect($data)->only([
            'customer_name', 'customer_email', 'customer_phone', 'shipping_address', 'billing_address',
            'payment_status', 'payment_method', 'currency', 'notes', 'shipping_amount', 'discount_amount', 'tax_amount',
        ])->all();

        foreach (['shipping_amount', 'discount_amount', 'tax_amount'] as $amount) {
            if (array_key_exists($amount, $fields)) {
                $fields[$amount] = (float) ($fields[$amount] ?? 0);
            }
        }

        if (isset($data['store_id']) && $order && (int) $data['store_id'] !== (int) $order->store_id && $order->external_order_id) {
            throw ValidationException::withMessages(['store_id' => 'This order is already on its store and cannot move to another one.']);
        }

        if (! $order || ! $order->external_order_id) {
            $fields['store_id'] = $data['store_id'] ?? $order?->store_id;
        }

        return $fields;
    }

    /**
     * Save the order lines and the totals that follow from them.
     *
     * @param  list<array{product_variant_id: int, quantity: int, unit_price: float|string}>  $items
     */
    private function saveLines(Order $order, array $items): void
    {
        $variants = ProductVariant::with('product')
            ->where('organization_id', $order->organization_id)
            ->whereIn('id', array_column($items, 'product_variant_id'))
            ->get()
            ->keyBy('id');

        $subtotal = 0.0;

        foreach ($items as $item) {
            $variant = $variants->get($item['product_variant_id']);
            $total = round($item['quantity'] * (float) $item['unit_price'], 2);
            $subtotal += $total;

            $order->items()->create([
                'product_variant_id' => $variant->id,
                'external_sku' => $variant->sku,
                'product_name' => trim($variant->product->name.($variant->option_value ? ' - '.$variant->option_value : '')),
                'image_url' => $variant->image_url ?: $variant->product->image_url,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $total,
                'status' => 'pending',
            ]);
        }

        $order->update([
            'subtotal_amount' => $subtotal,
            'total_amount' => max(0, $subtotal - (float) $order->discount_amount + (float) $order->shipping_amount + (float) $order->tax_amount),
        ]);
    }

    /**
     * The next free Finovo order number, e.g. "FIN-1001".
     */
    private function nextNumber(int $organizationId): string
    {
        $last = Order::where('organization_id', $organizationId)
            ->where('order_number', 'like', 'FIN-%')
            ->pluck('order_number')
            ->map(fn (string $number) => (int) substr($number, 4))
            ->max();

        return 'FIN-'.(max(1000, (int) $last) + 1);
    }
}
