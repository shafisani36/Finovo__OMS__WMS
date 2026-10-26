<?php

namespace App\Integrations\Sync\Data;

final readonly class OrderLineData
{
    public function __construct(
        /** Store variant ID, matched to the Finovo variant through the SKU mappings. */
        public ?string $externalVariantId,
        public ?string $sku,
        public string $name,
        public int $quantity,
        public float $unitPrice,
        public float $taxAmount,
        public float $totalAmount,
        public float $discountAmount = 0.0,
        public ?string $imageUrl = null,
        /** The platform's complete line record, kept as received. */
        public array $raw = [],
    ) {}
}
