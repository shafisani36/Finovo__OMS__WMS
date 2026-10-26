<?php

namespace App\Integrations\Push;

/**
 * The store's IDs for a product Finovo created or updated there.
 */
final readonly class PushedProduct
{
    /**
     * @param  array<int, string>  $variantIds  the store's variant ID by Finovo variant ID
     */
    public function __construct(
        public string $externalId,
        public array $variantIds,
    ) {}
}
