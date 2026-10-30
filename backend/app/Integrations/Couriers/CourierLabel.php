<?php

namespace App\Integrations\Couriers;

/** A printable label: either a link on the courier's site or the PDF itself. */
final class CourierLabel
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $pdf = null,
    ) {}
}
