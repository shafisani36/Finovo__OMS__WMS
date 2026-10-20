<?php

namespace App\Models;

use App\Enums\StorePlatform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETUP_INCOMPLETE = 'setup_incomplete';

    public const STATUS_DISCONNECTED = 'disconnected';

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
        'credentials',
        'external_reference',
        'connection_error',
        'connected_at',
        'settings',
        'last_sync_at',
    ];

    protected $hidden = [
        'api_secret',
        'webhook_secret',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'platform' => StorePlatform::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'orders_awaiting_sync' => 'integer',
            'connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * Read one stored credential value.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
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
