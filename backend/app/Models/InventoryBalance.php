<?php

namespace App\Models;

use App\Services\StoreSyncOutbox;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    use HasFactory;

    /** Changes to these change what can be sold, so the stores selling the item are updated. */
    private const SELLABLE_FIELDS = ['on_hand', 'reserved', 'available', 'damaged', 'quarantined'];

    protected static function booted(): void
    {
        static::saved(function (InventoryBalance $balance) {
            if ($balance->wasRecentlyCreated || $balance->wasChanged(self::SELLABLE_FIELDS)) {
                app(StoreSyncOutbox::class)->stockChanged($balance->product_variant_id);
            }
        });
        static::deleted(fn (InventoryBalance $balance) => app(StoreSyncOutbox::class)->stockChanged($balance->product_variant_id));
    }

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'product_variant_id',
        'on_hand',
        'reserved',
        'available',
        'damaged',
        'in_transit',
        'quarantined',
        'picked',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'available' => 'integer',
            'damaged' => 'integer',
            'in_transit' => 'integer',
            'quarantined' => 'integer',
            'picked' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
