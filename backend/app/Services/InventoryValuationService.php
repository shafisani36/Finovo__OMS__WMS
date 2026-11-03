<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inventory valuation from the stock ledger with FIFO, LIFO or weighted average cost (AVCO).
 *
 * Every unit received enters a cost layer (its purchase cost, or the product's cost price when none was recorded).
 * Perpetual: each issue (shipment, transfer out, negative adjustment) takes its cost from the layers at that moment:
 * the oldest layer first (FIFO), the newest first (LIFO), or the moving average (AVCO).
 * Periodic: the closing stock of the period is valued once, at the end: at the latest costs (FIFO), at the earliest
 * costs (LIFO) or at the period's weighted average (AVCO); cost of goods sold = opening + received - closing.
 * Units that come back (a cancelled dispatch, a transfer received) return at the cost they left with.
 */
class InventoryValuationService
{
    public const METHODS = ['fifo', 'lifo', 'avco'];

    public const MODES = ['perpetual', 'periodic'];

    /** Report periods offered, in months back from today. */
    public const PERIOD_MONTHS = [1, 3, 6, 12];

    public const DEFAULT_METHOD = 'fifo';

    private const SETTING = 'inventory_valuation_method';

    /** Inbound movements that bring back units issued by the paired outbound movement. */
    private const RETURNING = ['transfer_in' => 'transfer_out', 'shipment_cancelled' => 'shipment'];

    /** The company's chosen valuation method. */
    public function method(Organization $organization): string
    {
        $method = $organization->settings[self::SETTING] ?? null;

        return in_array($method, self::METHODS, true) ? $method : self::DEFAULT_METHOD;
    }

    public function setMethod(Organization $organization, string $method): void
    {
        $organization->update(['settings' => [...($organization->settings ?? []), self::SETTING => $method]]);
    }

    /**
     * One row per product: opening stock, received, issued with its cost, and closing stock for the period.
     *
     * @param  list<int>|null  $warehouseIds  only these warehouses (null: all)
     * @param  list<int>|null  $variantIds  only these products, e.g. the products of one store (null: all)
     * @return array{data: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function report(int $organizationId, string $method, string $mode, Carbon $from, Carbon $to, ?array $warehouseIds = null, ?array $variantIds = null): array
    {
        $stocks = $this->replay($organizationId, $method, $from, $to);
        $products = [];

        foreach ($stocks as $stock) {
            if (($warehouseIds !== null && ! in_array($stock['warehouse_id'], $warehouseIds, true)) || ($variantIds !== null && ! in_array($stock['variant_id'], $variantIds, true))) {
                continue;
            }

            $products[$stock['variant_id']] = $this->sum($products[$stock['variant_id']] ?? $this->emptyRow(), $this->summarise($stock, $method, $mode));
        }

        $products = array_filter($products, fn (array $row) => array_filter($row) !== []);
        $variants = ProductVariant::with('product:id,name')->whereIn('id', array_keys($products))->get()->keyBy('id');

        $data = [];
        foreach ($products as $variantId => $row) {
            $variant = $variants->get($variantId);
            $data[] = [
                'variant_id' => $variantId,
                'sku' => $variant?->sku,
                'product_name' => $variant?->product?->name,
                ...$this->rounded($row),
                'unit_cost' => $row['closing_quantity'] > 0 ? round($row['closing_value'] / $row['closing_quantity'], 2) : 0.0,
            ];
        }
        usort($data, fn (array $a, array $b) => $b['closing_value'] <=> $a['closing_value'] ?: strcmp((string) $a['sku'], (string) $b['sku']));

        $totals = $this->rounded(array_reduce($products, fn (array $sum, array $row) => $this->sum($sum, $row), $this->emptyRow()));

        return ['data' => $data, 'totals' => $totals];
    }

    /**
     * Replays the ledger up to $to: the cost layers of every warehouse and product, as they stood at $from and at $to,
     * with what was received and issued in between.
     *
     * @return array<string, array{warehouse_id: int, variant_id: int, layers: list<array{float, float}>, opening: list<array{float, float}>|null, received: list<array{float, float}>, issued_quantity: int, issued_cost: float}>
     */
    private function replay(int $organizationId, string $method, Carbon $from, Carbon $to): array
    {
        $costPrices = ProductVariant::where('organization_id', $organizationId)->pluck('cost_price', 'id')->map(fn ($cost) => (float) $cost);
        $stocks = [];
        $issuedBy = [];

        $entries = DB::table('inventory_ledger_entries')
            ->where('organization_id', $organizationId)
            ->whereIn('movement_type', InventoryEngine::ON_HAND_MOVEMENTS)
            ->where('quantity', '!=', 0)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'warehouse_id', 'product_variant_id', 'movement_type', 'quantity', 'unit_cost', 'reference_type', 'reference_id', 'reference_number', 'created_at'])
            ->cursor();

        foreach ($entries as $entry) {
            $key = "{$entry->warehouse_id}:{$entry->product_variant_id}";
            $stock = &$stocks[$key];
            $stock ??= ['warehouse_id' => (int) $entry->warehouse_id, 'variant_id' => (int) $entry->product_variant_id, 'layers' => [], 'opening' => null, 'received' => [], 'issued_quantity' => 0, 'issued_cost' => 0.0];

            $inPeriod = Carbon::parse($entry->created_at)->gte($from);
            if ($inPeriod && $stock['opening'] === null) {
                $stock['opening'] = $stock['layers'];
            }

            $fallback = $costPrices[$entry->product_variant_id] ?? 0.0;
            $quantity = (int) $entry->quantity;

            if ($quantity > 0) {
                $returning = isset(self::RETURNING[$entry->movement_type]);
                $parts = $returning
                    ? $this->take($issuedBy[$this->link($entry)][$entry->product_variant_id], $quantity, 'fifo', $fallback)
                    : [[$quantity, $entry->unit_cost !== null ? (float) $entry->unit_cost : $fallback]];
                $this->add($stock['layers'], $parts, $method);
                if ($inPeriod) {
                    array_push($stock['received'], ...$parts);
                }
            } else {
                $parts = $this->take($stock['layers'], -$quantity, $method, $fallback);
                if (in_array($entry->movement_type, self::RETURNING, true)) {
                    foreach ($parts as $part) {
                        $issuedBy[$this->link($entry)][$entry->product_variant_id][] = $part;
                    }
                }
                if ($inPeriod) {
                    $stock['issued_quantity'] += -$quantity;
                    $stock['issued_cost'] += $this->value($parts);
                }
            }
            unset($stock);
        }

        foreach ($stocks as &$stock) {
            $stock['opening'] ??= $stock['layers'];
        }

        return $stocks;
    }

    /**
     * @return array<string, int|float>
     */
    private function summarise(array $stock, string $method, string $mode): array
    {
        $openingQuantity = $this->quantity($stock['opening']);
        $openingValue = $this->value($stock['opening']);
        $inQuantity = $this->quantity($stock['received']);
        $inValue = $this->value($stock['received']);
        $closingQuantity = $openingQuantity + $inQuantity - $stock['issued_quantity'];

        // Periodic FIFO keeps the latest costs in stock, LIFO the earliest.
        $available = $method === 'fifo' ? array_reverse([...$stock['opening'], ...$stock['received']]) : [...$stock['opening'], ...$stock['received']];
        $closingValue = match (true) {
            $mode === 'perpetual' => $this->value($stock['layers']),
            $closingQuantity <= 0 => 0.0,
            $method === 'avco' => ($openingValue + $inValue) / ($openingQuantity + $inQuantity) * $closingQuantity,
            default => $this->value($this->take($available, $closingQuantity, 'fifo', 0.0)),
        };

        return [
            'opening_quantity' => (int) $openingQuantity,
            'opening_value' => $openingValue,
            'in_quantity' => (int) $inQuantity,
            'in_value' => $inValue,
            'out_quantity' => $stock['issued_quantity'],
            'cost_of_goods_sold' => $mode === 'perpetual' ? $stock['issued_cost'] : $openingValue + $inValue - $closingValue,
            'closing_quantity' => (int) $closingQuantity,
            'closing_value' => $closingValue,
        ];
    }

    /**
     * Adds received units to the layers; with AVCO all units share one average cost.
     *
     * @param  list<array{float, float}>  $layers
     * @param  list<array{float, float}>  $parts
     */
    private function add(array &$layers, array $parts, string $method): void
    {
        foreach ($parts as $part) {
            if ($method === 'avco' && $layers !== []) {
                $quantity = $layers[0][0] + $part[0];
                $layers = [[$quantity, $quantity > 0 ? ($this->value($layers) + $this->value([$part])) / $quantity : $part[1]]];
            } else {
                $layers[] = $part;
            }
        }
    }

    /**
     * Takes units out of the layers: the first layer first (FIFO, AVCO) or the last (LIFO). Units the layers cannot
     * cover (stock recorded below zero) are costed at $fallback.
     *
     * @param  list<array{float, float}>|null  $layers
     * @return list<array{float, float}>
     */
    private function take(?array &$layers, int|float $quantity, string $method, float $fallback): array
    {
        $layers ??= [];
        $taken = [];

        while ($quantity > 0 && $layers !== []) {
            $index = $method === 'lifo' ? array_key_last($layers) : array_key_first($layers);
            $units = min($quantity, $layers[$index][0]);
            $taken[] = [$units, $layers[$index][1]];
            $layers[$index][0] -= $units;
            $quantity -= $units;
            if ($layers[$index][0] <= 0) {
                unset($layers[$index]);
                $layers = array_values($layers);
            }
        }

        if ($quantity > 0) {
            $taken[] = [$quantity, $fallback];
        }

        return $taken;
    }

    /** Pairs an inbound movement with the outbound one it reverses. */
    private function link(object $entry): string
    {
        return $entry->reference_type === 'InventoryTransfer' ? "transfer:{$entry->reference_id}" : (string) $entry->reference_number;
    }

    /**
     * @param  list<array{float, float}>  $layers
     */
    private function quantity(array $layers): float
    {
        return array_sum(array_column($layers, 0));
    }

    /**
     * @param  list<array{float, float}>  $layers
     */
    private function value(array $layers): float
    {
        return array_sum(array_map(fn (array $layer) => $layer[0] * $layer[1], $layers));
    }

    /**
     * @return array<string, int>
     */
    private function emptyRow(): array
    {
        return array_fill_keys(['opening_quantity', 'opening_value', 'in_quantity', 'in_value', 'out_quantity', 'cost_of_goods_sold', 'closing_quantity', 'closing_value'], 0);
    }

    /**
     * @param  array<string, int|float>  $a
     * @param  array<string, int|float>  $b
     * @return array<string, int|float>
     */
    private function sum(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] += $value;
        }

        return $a;
    }

    /**
     * @param  array<string, int|float>  $row
     * @return array<string, int|float>
     */
    private function rounded(array $row): array
    {
        return array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $row);
    }
}
