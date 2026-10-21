<?php

namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'order_number',
        'external_order_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'order_status',
        'payment_status',
        'fulfillment_status',
        'total_amount',
        'currency',
        'notes',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'total_amount' => 'decimal:2',
            'placed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function pickTasks(): HasMany
    {
        return $this->hasMany(PickTask::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }
}
