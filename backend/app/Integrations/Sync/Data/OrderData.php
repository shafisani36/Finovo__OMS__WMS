<?php

namespace App\Integrations\Sync\Data;

use Carbon\CarbonImmutable;

final readonly class OrderData
{
    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_FAILED = 'failed';

    public const FULFILLMENT_UNFULFILLED = 'unfulfilled';

    public const FULFILLMENT_PARTIAL = 'partially_fulfilled';

    public const FULFILLMENT_FULFILLED = 'fulfilled';

    /**
     * @param  array<string, string|null>|null  $shippingAddress
     * @param  list<OrderLineData>  $lines
     */
    public function __construct(
        public string $externalId,
        /** Order number the merchant sees, e.g. "#1001" */
        public string $number,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public ?array $shippingAddress,
        /** One of the PAYMENT_* constants */
        public string $paymentStatus,
        /** One of the FULFILLMENT_* constants */
        public string $fulfillmentStatus,
        public bool $cancelled,
        public float $total,
        public string $currency,
        public ?string $note,
        public ?CarbonImmutable $placedAt,
        public array $lines,
    ) {}
}
