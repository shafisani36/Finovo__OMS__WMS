<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'platform',
        'status',
        'sync_status',
        'orders_awaiting_sync',
        'api_key',
        'api_secret',
        'webhook_secret',
        'bridge_url',
        'settings',
        'last_sync_at',
    ];

    protected $hidden = [
        'api_secret',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'orders_awaiting_sync' => 'integer',
            'last_sync_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'store_warehouse_assignments')
            ->withPivot(['is_default', 'priority'])
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function skuMappings(): HasMany
    {
        return $this->hasMany(SkuMapping::class);
    }

    public function integrationJobs(): HasMany
    {
        return $this->hasMany(IntegrationJob::class);
    }

    public function integrationErrors(): HasMany
    {
        return $this->hasMany(IntegrationError::class);
    }
}
