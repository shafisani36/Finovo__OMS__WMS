<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReorderRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'product_variant_id',
        'min_quantity',
        'reorder_point',
        'reorder_quantity',
        'safety_stock',
        'lead_time_days',
        'preferred_supplier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'reorder_point' => 'integer',
            'reorder_quantity' => 'integer',
            'safety_stock' => 'integer',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
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
