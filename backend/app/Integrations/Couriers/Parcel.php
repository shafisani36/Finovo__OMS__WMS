<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use App\Models\Shipment;

/**
 * What a courier needs to book one order's parcel, taken from the order and the account's defaults.
 */
final class Parcel
{
    public const DEFAULT_WEIGHT_KG = 0.5;

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

    /**
     * The parcel of a shipment. Weight, pieces and cash to collect come from the shipment when they were entered
     * at dispatch, otherwise from the account's default weight, one piece and the order's cash on delivery.
     */
    public static function fromShipment(Shipment $shipment, CourierAccount $account): self
    {
        $order = $shipment->order()->with('items')->firstOrFail();
        $address = (array) ($order->shipping_address ?? []);
        $items = $order->items->where('status', '!=', 'cancelled');
        $cod = $shipment->cashToCollect();

        return new self(
            reference: (string) $order->order_number,
            customerName: trim((string) ($order->customer_name ?: trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')))) ?: 'Customer',
            customerPhone: self::phone((string) ($order->customer_phone ?: ($address['phone'] ?? ''))),
            customerEmail: (string) ($order->customer_email ?? ''),
            address: implode(', ', array_filter([$address['address1'] ?? null, $address['address2'] ?? null, $address['zip'] ?? null])),
            city: trim((string) ($address['city'] ?? '')),
            codAmount: round($cod, 2),
            currency: (string) ($order->currency ?: 'PKR'),
            pieces: max(1, (int) ($shipment->pieces ?: 1)),
            weightKg: max(0.1, (float) ($shipment->weight_kg ?? self::defaultWeight($account))),
            description: $items->map(fn ($item) => "{$item->quantity} x {$item->product_name}")->implode(', '),
            notes: trim((string) ($order->notes ?? '')),
        );
    }

    /** The account's parcel weight in kg (0.5 when not set). */
    public static function defaultWeight(CourierAccount $account): float
    {
        return (float) $account->setting('default_weight_kg', self::DEFAULT_WEIGHT_KG);
    }

    /** Pakistani mobile numbers as couriers expect them: 03XXXXXXXXX. */
    private static function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '92') && strlen($digits) === 12 ? '0'.substr($digits, 2) : $digits;
    }
}
