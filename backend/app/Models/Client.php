<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand whose stock the company keeps and ships (3PL client). Its stores' orders, and the products sold in
 * them, are billed to it with its own rate card.
 */
class Client extends Model
{
    /** Rate card fields, in the order they appear on an invoice. */
    public const RATES = ['storage_per_unit_day', 'receiving_per_unit', 'order_fee', 'additional_unit_fee', 'return_fee', 'monthly_minimum'];

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'contact_name',
        'email',
        'phone',
        'billing_address',
        'currency',
        'payment_terms_days',
        'storage_per_unit_day',
        'receiving_per_unit',
        'order_fee',
        'additional_unit_fee',
        'return_fee',
        'monthly_minimum',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'storage_per_unit_day' => 'float',
            'receiving_per_unit' => 'float',
            'order_fee' => 'float',
            'additional_unit_fee' => 'float',
            'return_fee' => 'float',
            'monthly_minimum' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }
}
