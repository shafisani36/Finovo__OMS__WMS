<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One action on many orders. Each order is handled on its own, so one that cannot be changed (held, already
 * shipped...) is reported without stopping the others.
 */
class OrderBulkService
{
    /** The permission each action needs. */
    public const ACTIONS = [
        'allocate' => Permission::ORDERS_ALLOCATE,
        'hold' => Permission::ORDERS_MANAGE,
        'release' => Permission::ORDERS_MANAGE,
        'cancel' => Permission::ORDERS_CANCEL,
        'set_status' => Permission::ORDERS_MANAGE,
    ];

    public function __construct(
        protected OrderAllocationEngine $allocation,
        protected OrderControlService $controls,
        protected OrderService $orders,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array{reason?: string|null, custom_status?: string|null}  $options
     * @return array{done: list<string>, failed: list<array{order_number: string, message: string}>}
     */
    public function run(string $action, Collection $orders, User $user, array $options = []): array
    {
        $done = [];
        $failed = [];

        foreach ($orders as $order) {
            try {
                $this->apply($action, $order, $user, $options);
                $done[] = $order->order_number;
            } catch (ValidationException $e) {
                $failed[] = ['order_number' => $order->order_number, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * @param  array{reason?: string|null, custom_status?: string|null}  $options
     */
    private function apply(string $action, Order $order, User $user, array $options): void
    {
        match ($action) {
            'allocate' => $this->allocate($order),
            'hold' => $order->hold_type === null
                ? $this->controls->hold($order, 'manual', (string) $options['reason'], $user)
                : throw ValidationException::withMessages(['order' => "Order {$order->order_number} is already on hold."]),
            'release' => $this->controls->release($order, $user),
            'cancel' => $this->orders->cancel($order),
            'set_status' => $this->controls->setCustomStatus($order, $options['custom_status'] ?? null, $user),
        };
    }

    private function allocate(Order $order): void
    {
        if (! in_array($order->order_status, Order::ALLOCATABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['order' => "Order {$order->order_number} is {$order->order_status} and has nothing left to allocate."]);
        }

        $this->allocation->allocateOrder($order);
    }
}
