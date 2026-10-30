<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's account with a courier. Credentials are stored encrypted and never sent to the browser;
 * settings hold the booking defaults (origin city, pickup address, parcel weight).
 */
class CourierAccount extends Model
{
    public const CARRIERS = ['leopards', 'postex', 'tcs', 'trax'];

    protected $fillable = [
        'organization_id',
        'carrier',
        'name',
        'credentials',
        'settings',
        'sandbox',
        'is_active',
        'last_error',
        'last_tracked_at',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'sandbox' => 'boolean',
            'is_active' => 'boolean',
            'last_tracked_at' => 'datetime',
        ];
    }

    /** A credential value, or an empty string when it is not set. */
    public function credential(string $key): string
    {
        return trim((string) ($this->credentials[$key] ?? ''));
    }

    /** A booking default from the settings. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
