<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_variant_id',
        'external_sku',
        'product_name',
        'image_url',
        'quantity',
        'unit_price',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'allocated_warehouse_id',
        'fulfilled_quantity',
        'status',
        'platform_data',
    ];

    protected $hidden = ['platform_data'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'fulfilled_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'platform_data' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function allocatedWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'allocated_warehouse_id');
    }
}
