<?php

namespace App\Integrations\Push;

/**
 * A Finovo variant as the store knows it: the store's variant ID, its product's ID and the SKU on the store.
 */
final class LinkedVariant
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $productExternalId,
        public readonly ?string $sku,
    ) {}
}
