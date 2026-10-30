<?php

namespace App\Integrations\Couriers;

use DateTimeInterface;

/**
 * A courier's status of a parcel, as its own wording and as one of Finovo's statuses, so every courier reads
 * the same in Finovo.
 */
final class CourierStatus
{
    public const BOOKED = 'booked';

    public const IN_TRANSIT = 'in_transit';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $status,
        public readonly string $text,
        public readonly ?DateTimeInterface $at = null,
    ) {}

    /**
     * Read a courier's status text into a Finovo status. Couriers word statuses differently ("Delivered",
     * "Returned to shipper", "Out For Delivery", "Attempt Made"), so the meaning is taken from key words.
     */
    public static function fromText(string $text, ?DateTimeInterface $at = null): self
    {
        $words = strtolower($text);

        $status = match (true) {
            str_contains($words, 'cancel') => self::CANCELLED,
            str_contains($words, 'return') => self::RETURNED,
            str_contains($words, 'undeliver'), str_contains($words, 'attempt'), str_contains($words, 'refused'), str_contains($words, 'under review') => self::DELIVERY_FAILED,
            str_contains($words, 'out for delivery'), str_contains($words, 'on route'), str_contains($words, 'on root') => self::OUT_FOR_DELIVERY,
            str_contains($words, 'deliver') => self::DELIVERED,
            str_contains($words, 'pending'), str_contains($words, 'unbooked'), str_contains($words, 'booked'), str_contains($words, 'merchant') => self::BOOKED,
            default => self::IN_TRANSIT,
        };

        return new self($status, trim($text), $at);
    }
}
