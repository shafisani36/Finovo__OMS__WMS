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
        'quantity',
        'unit_price',
        'tax_amount',
        'total_amount',
        'allocated_warehouse_id',
        'fulfilled_quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'fulfilled_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
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
