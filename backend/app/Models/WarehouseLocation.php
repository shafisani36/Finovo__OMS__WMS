<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'parent_id',
        'code',
        'location_type',
        'barcode',
        'aisle',
        'rack',
        'shelf',
        'bin',
        'picking_eligible',
        'putaway_eligible',
        'capacity',
        'capacity_unit',
        'weight_capacity',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'picking_eligible' => 'boolean',
            'putaway_eligible' => 'boolean',
            'is_active' => 'boolean',
            'capacity' => 'decimal:2',
            'weight_capacity' => 'decimal:2',
            'sort_order' => 'integer',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'parent_id')->orderBy('sort_order');
    }
}
