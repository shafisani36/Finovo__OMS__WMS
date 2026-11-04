<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    protected $fillable = [
        'return_id',
        'order_item_id',
        'product_variant_id',
        'sku',
        'product_name',
        'quantity',
        'serial_numbers',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'serial_numbers' => 'array',
        ];
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }
}
