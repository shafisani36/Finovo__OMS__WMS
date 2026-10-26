<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Finovo product and the same product on one connected store (its ID there).
 */
class StoreProductLink extends Model
{
    protected $fillable = ['organization_id', 'store_id', 'product_id', 'external_id'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
