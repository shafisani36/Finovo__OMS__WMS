<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_count_id',
        'product_variant_id',
        'warehouse_location_id',
        'lot_number',
        'snapshot_quantity',
        'counted_quantity',
        'variance_quantity',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'variance_quantity' => 'integer',
        ];
    }

    public function cycleCount(): BelongsTo
    {
        return $this->belongsTo(CycleCount::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
