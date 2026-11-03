<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cycle count that starts by itself every `every_days` days, from `next_run_on`.
 */
class CycleCountSchedule extends Model
{
    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'title',
        'scope_type',
        'scope_value',
        'every_days',
        'next_run_on',
        'last_cycle_count_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'every_days' => 'integer',
            'next_run_on' => 'date:Y-m-d',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
