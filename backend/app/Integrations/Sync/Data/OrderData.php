<?php

namespace App\Integrations\Sync\Data;

use Carbon\CarbonImmutable;

final readonly class OrderData
{
    /** Key under which Finovo stores its own order ID on orders it creates on a store. */
    public const FINOVO_REFERENCE_KEY = 'finovo_order_id';

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
     * @param  array<string, string|null>|null  $billingAddress  same keys as the shipping address
     * @param  array<string, scalar|list<scalar>|null>  $details  other order facts the platform gives, by label
     * @param  array<string, mixed>  $raw  the platform's complete order record, kept as received
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
        public ?array $billingAddress = null,
        public ?float $subtotal = null,
        public ?float $shippingTotal = null,
        public ?float $taxTotal = null,
        public ?float $discountTotal = null,
        /** How the customer paid, e.g. "Cash on Delivery" or "Shopify Payments". */
        public ?string $paymentMethod = null,
        public array $details = [],
        public array $raw = [],
        /** ID of the Finovo order this store order was created from, when Finovo created it on the store. */
        public ?string $finovoOrderId = null,
    ) {}
}
