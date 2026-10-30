<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use App\Models\Shipment;

/**
 * What a courier needs to book one order's parcel, taken from the order and the account's defaults.
 */
final class Parcel
{
    public function __construct(
        public readonly string $reference,
        public readonly string $customerName,
        public readonly string $customerPhone,
        public readonly string $customerEmail,
        public readonly string $address,
        public readonly string $city,
        public readonly float $codAmount,
        public readonly string $currency,
        public readonly int $pieces,
        public readonly float $weightKg,
        public readonly string $description,
        public readonly string $notes,
    ) {}

    public static function fromShipment(Shipment $shipment, CourierAccount $account): self
    {
        $order = $shipment->order()->with('items')->firstOrFail();
        $address = (array) ($order->shipping_address ?? []);
        $items = $order->items->where('status', '!=', 'cancelled');
        // Cash on delivery collects the order total unless the customer has already paid.
        $cod = $order->payment_status === 'paid' ? 0.0 : (float) $order->total_amount;

        return new self(
            reference: (string) $order->order_number,
            customerName: trim((string) ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')))) ?: 'Customer',
            customerPhone: self::phone((string) ($order->customer_phone ?: ($address['phone'] ?? ''))),
            customerEmail: (string) ($order->customer_email ?? ''),
            address: implode(', ', array_filter([$address['address1'] ?? null, $address['address2'] ?? null, $address['zip'] ?? null])),
            city: trim((string) ($address['city'] ?? '')),
            codAmount: round($cod, 2),
            currency: (string) ($order->currency ?: 'PKR'),
            pieces: 1,
            weightKg: max(0.1, (float) $account->setting('default_weight_kg', 0.5)),
            description: $items->map(fn ($item) => "{$item->quantity} x {$item->product_name}")->implode(', '),
            notes: trim((string) ($order->notes ?? '')),
        );
    }

    /** Pakistani mobile numbers as couriers expect them: 03XXXXXXXXX. */
    private static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '92') && strlen($digits) === 12 ? '0'.substr($digits, 2) : $digits;
    }
}
