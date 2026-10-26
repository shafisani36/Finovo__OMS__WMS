<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    // Supported Warehouse Types
    public const TYPE_DISTRIBUTION_CENTER = 'distribution_center';

    public const TYPE_FULFILLMENT_CENTER = 'fulfillment_center';

    public const TYPE_STORAGE = 'storage_warehouse';

    public const TYPE_RETAIL_STORE = 'retail_store';

    public const TYPE_MANUFACTURING = 'manufacturing';

    public const TYPE_CROSS_DOCK = 'cross_dock';

    public const TYPE_3PL = '3pl';

    public const TYPE_QUARANTINE = 'quarantine';

    public const TYPES = [
        self::TYPE_DISTRIBUTION_CENTER, self::TYPE_FULFILLMENT_CENTER, self::TYPE_STORAGE, self::TYPE_RETAIL_STORE,
        self::TYPE_MANUFACTURING, self::TYPE_CROSS_DOCK, self::TYPE_3PL, self::TYPE_QUARANTINE,
    ];

    // Lifecycle States
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SETUP_INCOMPLETE = 'setup_incomplete';

    public const STATUS_READY_FOR_ACTIVATION = 'ready_for_activation';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_CLOSING = 'closing';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'warehouse_type',
        'description',
        'status',
        'is_active',
        'is_default',
        'priority',
        'external_reference',
        'parent_warehouse_id',
        'address',
        'address_line_2',
        'city',
        'state',
        'country',
        'postal_code',
        'timezone',
        'contact_name',
        'contact_email',
        'contact_phone',
        'latitude',
        'longitude',
        'operating_hours',
        'inventory_policies',
        'inbound_policies',
        'outbound_policies',
        'integration_settings',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'operating_hours' => 'array',
            'inventory_policies' => 'array',
            'inbound_policies' => 'array',
            'outbound_policies' => 'array',
            'integration_settings' => 'array',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_active;
    }

    public function isReadyForActivation(): bool
    {
        return $this->status === self::STATUS_READY_FOR_ACTIVATION;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'parent_warehouse_id');
    }

    public function childWarehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class, 'parent_warehouse_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    /**
     * Staff currently assigned to work here (revoked and expired assignments are kept only as history).
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WarehouseMembership::class)->current();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_memberships')
            ->withPivot(['role', 'permissions', 'status', 'assigned_at', 'expires_at'])
            ->withTimestamps();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(WarehouseAuditLog::class)->latest();
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_warehouse_assignments')
            ->withPivot(['is_default', 'priority'])
            ->withTimestamps();
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedgerEntry::class);
    }
}
