<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisitionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_requisition_id',
        'product_id',
        'product_variant_id',
        'sku',
        'product_name',
        'requested_quantity',
        'approved_quantity',
        'ordered_quantity',
        'estimated_unit_price',
        'estimated_subtotal',
        'uom',
        'notes',
    ];

    protected $casts = [
        'requested_quantity' => 'integer',
        'approved_quantity' => 'integer',
        'ordered_quantity' => 'integer',
        'estimated_unit_price' => 'decimal:2',
        'estimated_subtotal' => 'decimal:2',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function poItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Approved quantity not yet on a purchase order (nothing can be ordered before approval).
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->approved_quantity - $this->ordered_quantity);
    }
}
