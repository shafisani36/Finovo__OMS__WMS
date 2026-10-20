<?php

namespace App\Integrations\Sync\Data;

final readonly class ProductData
{
    /**
     * @param  list<VariantData>  $variants
     */
    public function __construct(
        public string $externalId,
        public string $name,
        /** Store's own product handle / slug, used to build a SKU when the store has none. */
        public ?string $handle,
        public ?string $description,
        public ?string $category,
        public ?string $imageUrl,
        public bool $isActive,
        public array $variants,
    ) {}
}
