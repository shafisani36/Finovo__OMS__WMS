<?php

namespace App\Integrations\Sync;

/**
 * Counts and warnings from one store sync, shown to the user and kept on the sync job record.
 */
class SyncResult
{
    public int $productsCreated = 0;

    public int $productsUpdated = 0;

    public int $variantsSynced = 0;

    public int $openingStockLines = 0;

    public int $ordersCreated = 0;

    public int $ordersUpdated = 0;

    /** @var list<string> */
    public array $warnings = [];

    public function summary(): string
    {
        $summary = sprintf(
            '%d products (%d new), %d variants, %d orders (%d new)',
            $this->productsCreated + $this->productsUpdated,
            $this->productsCreated,
            $this->variantsSynced,
            $this->ordersCreated + $this->ordersUpdated,
            $this->ordersCreated,
        );

        if ($this->openingStockLines > 0) {
            $summary .= ", opening stock set for {$this->openingStockLines} variants";
        }

        return $summary.'.';
    }

    /**
     * @return array<string, int|list<string>>
     */
    public function toArray(): array
    {
        return [
            'products_created' => $this->productsCreated,
            'products_updated' => $this->productsUpdated,
            'variants_synced' => $this->variantsSynced,
            'opening_stock_lines' => $this->openingStockLines,
            'orders_created' => $this->ordersCreated,
            'orders_updated' => $this->ordersUpdated,
            'warnings' => $this->warnings,
        ];
    }
}
