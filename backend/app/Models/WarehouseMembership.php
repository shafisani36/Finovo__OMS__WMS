<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'user_id',
        'role',
        'permissions',
        'status',
        'assigned_by',
        'assigned_at',
        'revoked_at',
        'expires_at',
    ];

    public const STATUS_ACTIVE = 'active';

    /** Taken away; the row is kept as history of who worked where. */
    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Assignments that currently give access: active and not past their end date.
     *
     * @param  Builder<WarehouseMembership>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
