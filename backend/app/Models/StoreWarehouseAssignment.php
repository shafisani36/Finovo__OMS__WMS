<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreWarehouseAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'warehouse_id',
        'is_default',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
