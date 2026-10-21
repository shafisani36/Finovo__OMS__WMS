<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Links every order to its customer. Orders are matched by email, then phone; an order with only a name
 * is matched by name within its own store, so two different people with the same name are not merged.
 */
class CustomerService
{
    public const GUEST_NAME = 'Guest customer';

    public function linkOrder(Order $order): ?Customer
    {
        $key = $this->identityKey($order);
        $customer = null;

        if ($key !== null) {
            $customer = Customer::firstOrCreate(
                ['organization_id' => $order->organization_id, 'identity_key' => $key],
                ['name' => $this->displayName($order)],
            );

            $this->refreshDetails($customer, $order);
        }

        if ($order->customer_id !== $customer?->id) {
            $order->customer_id = $customer?->id;
            $order->saveQuietly();
        }

        return $customer;
    }

    /**
     * Link orders imported before customers existed (or whose customer was removed).
     */
    public function linkUnassignedOrders(?int $organizationId = null): int
    {
        $linked = 0;

        Order::whereNull('customer_id')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('placed_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$linked) {
                foreach ($orders as $order) {
                    if ($this->linkOrder($order)) {
                        $linked++;
                    }
                }
            });

        return $linked;
    }

    public function identityKey(Order $order): ?string
    {
        $email = Str::lower(trim((string) $order->customer_email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'email:'.$email;
        }

        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone);

        if (strlen($phone) >= 7) {
            return 'phone:'.$phone;
        }

        $name = Str::lower(Str::squish((string) $order->customer_name));

        if ($name !== '' && $name !== Str::lower(self::GUEST_NAME)) {
            return Str::limit('name:'.$order->store_id.':'.$name, 191, '');
        }

        return null;
    }

    /**
     * The newest order carries the customer's current name, phone and address.
     */
    private function refreshDetails(Customer $customer, Order $order): void
    {
        $placedAt = $order->placed_at ?? $order->created_at ?? now();

        if ($customer->last_order_at && $placedAt->lt($customer->last_order_at)) {
            // An older order only fills details that are still missing.
            $customer->fill(array_filter([
                'email' => $customer->email ? null : $order->customer_email,
                'phone' => $customer->phone ? null : $order->customer_phone,
                'default_address' => $customer->default_address ? null : $order->shipping_address,
            ]));
        } else {
            $customer->fill(array_filter([
                'name' => $this->displayName($order) !== self::GUEST_NAME ? $this->displayName($order) : null,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'default_address' => $order->shipping_address ?: null,
            ]) + ['last_order_at' => $placedAt]);
        }

        if ($customer->isDirty()) {
            $customer->save();
        }
    }

    private function displayName(Order $order): string
    {
        $name = Str::squish((string) $order->customer_name);

        if ($name !== '' && $name !== self::GUEST_NAME) {
            return $name;
        }

        // A guest with an email still gets a readable name.
        return $order->customer_email ? Str::before($order->customer_email, '@') : self::GUEST_NAME;
    }
}
