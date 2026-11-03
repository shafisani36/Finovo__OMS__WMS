<?php

namespace App\Services;

use App\Models\CycleCount;
use App\Models\InventoryBalance;
use App\Models\InventoryLocationBalance;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Starts cycle counts: a snapshot of what the system holds in the counted area, for the counters to compare with
 * the shelves. The area is the whole warehouse, a zone (bins whose code starts with the zone code, e.g. A01 for
 * A01-R01-S1-B03) or one bin.
 */
class CycleCountService
{
    public const SCOPES = ['full', 'zone', 'location'];

    public function start(Warehouse $warehouse, string $title, string $scopeType, ?string $scopeValue, ?string $notes, ?int $userId): CycleCount
    {
        return DB::transaction(function () use ($warehouse, $title, $scopeType, $scopeValue, $notes, $userId) {
            $count = CycleCount::create([
                'organization_id' => $warehouse->organization_id,
                'warehouse_id' => $warehouse->id,
                'count_number' => 'CYC-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -5)),
                'title' => $title,
                'scope_type' => $scopeType,
                'scope_value' => $scopeType === 'full' ? null : $scopeValue,
                'notes' => $notes,
                'status' => 'in_progress',
                'created_by_user_id' => $userId,
            ]);

            $bins = InventoryLocationBalance::where('warehouse_id', $warehouse->id)
                ->when($scopeType !== 'full', fn (Builder $q) => $q->whereHas('location', fn (Builder $l) => $scopeType === 'location'
                    ? $l->where('code', $scopeValue)
                    : $l->where(fn (Builder $z) => $z->where('code', $scopeValue)->orWhere('code', 'like', "{$scopeValue}-%"))))
                ->get();

            foreach ($bins as $bin) {
                $count->items()->create($this->line($bin->product_variant_id, $bin->warehouse_location_id, $bin->lot_number, $bin->quantity));
            }

            // A whole-warehouse count of stock not yet placed in bins counts the warehouse totals.
            if ($bins->isEmpty() && $scopeType === 'full') {
                foreach (InventoryBalance::where('warehouse_id', $warehouse->id)->get() as $balance) {
                    $count->items()->create($this->line($balance->product_variant_id, null, null, $balance->on_hand));
                }
            }

            return $count->load('items.variant.product');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $variantId, ?int $locationId, ?string $lotNumber, int $quantity): array
    {
        return [
            'product_variant_id' => $variantId,
            'warehouse_location_id' => $locationId,
            'lot_number' => $lotNumber,
            'snapshot_quantity' => $quantity,
            'counted_quantity' => null,
            'variance_quantity' => 0,
            'status' => 'pending',
        ];
    }
}
