<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer, built from the orders imported from connected stores (the same email in two stores is one customer).
 */
class Customer extends Model
{
    protected $fillable = [
        'organization_id',
        'identity_key',
        'name',
        'email',
        'phone',
        'default_address',
        'last_order_at',
    ];

    protected $hidden = ['identity_key'];

    protected function casts(): array
    {
        return [
            'default_address' => 'array',
            'last_order_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
