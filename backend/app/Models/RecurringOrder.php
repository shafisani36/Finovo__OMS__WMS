<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order placed again on a schedule (a café's weekly restock, a monthly wholesale order). The template holds
 * the order as OrderService creates it.
 */
class RecurringOrder extends Model
{
    public const INTERVALS = ['weekly', 'fortnightly', 'monthly'];

    protected $fillable = ['organization_id', 'source_order_id', 'created_by', 'template', 'interval', 'next_run_at', 'active', 'last_run_at', 'last_error'];

    protected function casts(): array
    {
        return ['template' => 'array', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime', 'active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The run after the given one. */
    public function nextAfter(CarbonInterface $run): CarbonInterface
    {
        return match ($this->interval) {
            'weekly' => $run->copy()->addWeek(),
            'fortnightly' => $run->copy()->addWeeks(2),
            default => $run->copy()->addMonthNoOverflow(),
        };
    }
}
