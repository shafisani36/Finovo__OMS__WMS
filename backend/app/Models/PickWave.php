<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Several orders of one warehouse picked together in one round: one route-sorted list, each scanned unit put
 * into its order's tote.
 */
class PickWave extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['organization_id', 'warehouse_id', 'wave_number', 'status', 'created_by', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The wave's orders in tote order (tote 1 is the first task). */
    public function tasks(): HasMany
    {
        return $this->hasMany(PickTask::class)->orderBy('id');
    }

    /**
     * Next wave number for the organization, e.g. WAVE-2026-0007. Call inside a transaction.
     */
    public static function nextNumber(int $organizationId): string
    {
        $prefix = 'WAVE-'.now()->year.'-';
        $last = static::where('organization_id', $organizationId)
            ->where('wave_number', 'like', $prefix.'%')
            ->orderByDesc('wave_number')
            ->lockForUpdate()
            ->value('wave_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
