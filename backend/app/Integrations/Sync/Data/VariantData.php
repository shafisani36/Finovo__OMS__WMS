<?php

namespace App\Integrations\Sync\Data;

/**
 * One sellable variant of a store product, in the platform-neutral shape Finovo saves.
 */
final readonly class VariantData
{
    public function __construct(
        public string $externalId,
        public ?string $sku,
        public ?string $barcode,
        /** e.g. "Size / Color"; null for a product without options */
        public ?string $optionName,
        /** e.g. "M / Red" */
        public ?string $optionValue,
        public float $price,
        public ?float $costPrice,
        /** Units the store reports in stock; used once as opening stock in the store's warehouse. */
        public ?int $stockQuantity,
    ) {}
}
