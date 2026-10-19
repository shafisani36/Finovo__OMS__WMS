<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceivedNoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_received_note_id',
        'purchase_order_item_id',
        'product_variant_id',
        'sku',
        'product_name',
        'ordered_quantity',
        'previously_received_quantity',
        'received_quantity',
        'accepted_quantity',
        'damaged_quantity',
        'quarantined_quantity',
        'rejected_quantity',
        'pending_inspection_quantity',
        'destination_location_id',
        'lot_number',
        'expiry_date',
        'manufacturing_date',
        'discrepancy_reason',
        'notes',
        'status',
    ];

    protected $casts = [
        'ordered_quantity' => 'integer',
        'previously_received_quantity' => 'integer',
        'received_quantity' => 'integer',
        'accepted_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'quarantined_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'pending_inspection_quantity' => 'integer',
        'expiry_date' => 'date',
        'manufacturing_date' => 'date',
    ];

    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'destination_location_id');
    }
}
