<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'purchase_requisition_item_id',
        'product_variant_id',
        'sku',
        'product_name',
        'ordered_quantity',
        'received_quantity',
        'unit_cost',
        'total_cost',
        'uom',
        'notes',
    ];

    protected $casts = [
        'ordered_quantity' => 'integer',
        'received_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'purchase_requisition_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function grnItems(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class);
    }

    public function getOutstandingQuantityAttribute(): int
    {
        return max(0, $this->ordered_quantity - $this->received_quantity);
    }
}
