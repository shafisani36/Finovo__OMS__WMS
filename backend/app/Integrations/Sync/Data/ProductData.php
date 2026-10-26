<?php

namespace App\Integrations\Sync\Data;

final readonly class ProductData
{
    /**
     * @param  list<VariantData>  $variants
     * @param  list<string>  $images  every product photo, main one first
     * @param  array<string, scalar|list<scalar>|null>  $details  other facts the platform gives, by label
     *                                                            (e.g. "Vendor" => "Acme", "Tags" => [...], "Weight" => "1.2 kg")
     * @param  array<string, mixed>  $raw  the platform's complete product record, kept as received
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
        public array $images = [],
        public array $details = [],
        public array $raw = [],
    ) {}
}
