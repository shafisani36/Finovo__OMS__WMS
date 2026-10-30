<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLocationBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'warehouse_location_id',
        'product_variant_id',
        'lot_number',
        'expiry_date',
        'condition',
        'quantity',
        'reserved',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved' => 'integer',
            'expiry_date' => 'date',
        ];
    }

    /**
     * Sellable stock of a warehouse in the order it should leave (FEFO, first expired first out): the nearest
     * expiry first, undated stock after dated stock, and the fullest bin first among equals. Expired lots are
     * left out, so they are never picked or shipped.
     *
     * @param  int|list<int>  $variantIds
     */
    public function scopeFefo(Builder $query, int $warehouseId, int|array $variantIds): Builder
    {
        return $query->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', (array) $variantIds)
            ->where('condition', 'available')
            ->where('quantity', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderByDesc('quantity')
            ->orderBy('id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
