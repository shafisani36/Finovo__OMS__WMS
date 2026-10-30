<?php

namespace App\Integrations\Couriers;

/** A booked parcel: its tracking number and, when the courier gives one, a link to its label. */
final class CourierBooking
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $labelUrl = null,
    ) {}
}
