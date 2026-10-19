<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'product_id',
        'sku',
        'barcode',
        'option_name',
        'option_value',
        'price',
        'cost_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function locationBalances(): HasMany
    {
        return $this->hasMany(InventoryLocationBalance::class);
    }

    public function reorderRules(): HasMany
    {
        return $this->hasMany(ReorderRule::class);
    }

    public function skuMappings(): HasMany
    {
        return $this->hasMany(SkuMapping::class);
    }
}
