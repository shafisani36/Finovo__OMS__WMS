<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RecurringOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orders placed again on a schedule. A plan copies an existing order (store, customer, address, lines at their
 * prices); each run creates a new order from it the way an order taken by hand is created.
 */
class RecurringOrderService
{
    public function __construct(
        protected OrderService $orders
    ) {}

    public function repeat(Order $order, string $interval, CarbonInterface $startsOn, User $user): RecurringOrder
    {
        if ($order->items()->whereNotNull('product_variant_id')->doesntExist()) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} has no catalog products to repeat."]);
        }

        return RecurringOrder::create([
            'organization_id' => $order->organization_id,
            'source_order_id' => $order->id,
            'created_by' => $user->id,
            'template' => [
                'store_id' => $order->store_id,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'shipping_address' => $order->shipping_address,
                'billing_address' => $order->billing_address,
                'payment_status' => 'pending',
                'payment_method' => $order->payment_method,
                'currency' => $order->currency,
                'shipping_amount' => (float) $order->shipping_amount,
                'notes' => "Repeats order {$order->order_number}.",
                'items' => $order->items()->whereNotNull('product_variant_id')->get()->map(fn ($item) => [
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])->values()->all(),
            ],
            'interval' => $interval,
            'next_run_at' => $startsOn->copy()->startOfDay(),
        ]);
    }

    /**
     * Places every plan that is due. A plan that fails keeps its date and the reason, and is tried again.
     *
     * @return array{created: int, failed: int}
     */
    public function runDue(): array
    {
        $created = 0;
        $failed = 0;
        RecurringOrder::where('active', true)->where('next_run_at', '<=', now())->orderBy('next_run_at')->each(function (RecurringOrder $plan) use (&$created, &$failed) {
            try {
                $this->place($plan, advance: true);
                $created++;
            } catch (Throwable $e) {
                $plan->update(['last_error' => mb_substr($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage(), 0, 500)]);
                $failed++;
            }
        });

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Creates the plan's order now; a scheduled run also moves the plan to its next date. The run is claimed first
     * (so two workers never place it twice) and handed back when the order cannot be created.
     */
    public function place(RecurringOrder $plan, bool $advance = false): Order
    {
        $user = $plan->creator;
        if (! $user || $user->status !== 'active' || $user->currentOrganization()->id !== $plan->organization_id) {
            throw ValidationException::withMessages(['plan' => 'The person who set up this recurring order can no longer place orders for this company.']);
        }
        $data = Validator::make($plan->template, $this->orders->rules($plan->organization_id), OrderService::VALIDATION_MESSAGES)->validate();

        $due = DB::transaction(function () use ($plan, $advance) {
            $locked = RecurringOrder::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($advance && $locked->next_run_at->isFuture()) {
                throw ValidationException::withMessages(['plan' => 'This recurring order already ran.']);
            }
            // A plan missed for a while places one order, then keeps its rhythm from now.
            $due = $locked->next_run_at;
            $next = $due;
            while ($advance && ! $next->isFuture()) {
                $next = $locked->nextAfter($next);
            }
            $locked->update(['last_run_at' => now(), 'last_error' => null, 'next_run_at' => $next]);

            return $due;
        });

        try {
            [$order] = $this->orders->create($user, $data);
        } catch (Throwable $e) {
            $plan->update(['next_run_at' => $due]);
            throw $e;
        }
        $order->forceFill(['recurring_order_id' => $plan->id])->saveQuietly();

        return $order;
    }
}
