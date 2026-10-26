<?php

namespace App\Models;

use App\Enums\StorePlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /** What the Stores page shows and filters by, in the order a store moves through them. [state => label] */
    public const CONNECTION_STATES = [
        'connected' => 'Connected',
        'awaiting_authorization' => 'Awaiting Authorization',
        'setup_incomplete' => 'Setup Incomplete',
        'disconnected' => 'Disconnected',
    ];

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

    protected $appends = ['connection_state'];

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

    /**
     * One of CONNECTION_STATES. A store is awaiting authorization while the merchant still has to approve Finovo on
     * the platform (or install its app/module); after a failed attempt it shows as setup incomplete with the reason.
     */
    protected function connectionState(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            $this->status === self::STATUS_ACTIVE => 'connected',
            $this->status === self::STATUS_DISCONNECTED => 'disconnected',
            $this->platform?->authorizesOnPlatform() && blank($this->connection_error) => 'awaiting_authorization',
            default => 'setup_incomplete',
        });
    }

    /**
     * Stores in one of CONNECTION_STATES (same rules as the connection_state attribute).
     *
     * @param  Builder<Store>  $query
     */
    public function scopeInConnectionState(Builder $query, string $state): void
    {
        $authorizing = collect(StorePlatform::cases())->filter->authorizesOnPlatform()->map->value->values()->all();
        $awaiting = fn (Builder $q) => $q->whereIn('platform', $authorizing)->where(fn ($q) => $q->whereNull('connection_error')->orWhere('connection_error', ''));

        match ($state) {
            'connected' => $query->where('status', self::STATUS_ACTIVE),
            'disconnected' => $query->where('status', self::STATUS_DISCONNECTED),
            'awaiting_authorization' => $query->where('status', self::STATUS_SETUP_INCOMPLETE)->where($awaiting),
            'setup_incomplete' => $query->where('status', self::STATUS_SETUP_INCOMPLETE)->whereNot($awaiting),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Address of the Finovo portal the merchant started connecting from, so the platform sends them back
     * to the same portal (falls back to FRONTEND_URL).
     */
    public function portalUrl(string $path = ''): string
    {
        return rtrim((string) ($this->settings['portal_url'] ?? config('app.frontend_url')), '/').$path;
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
