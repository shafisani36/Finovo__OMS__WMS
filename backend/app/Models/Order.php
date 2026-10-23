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

    /** Orders in these statuses can (re)try reserving stock. */
    public const ALLOCATABLE_STATUSES = ['imported', 'pending', 'partially_allocated', 'exception'];

    /** Orders in these statuses have left the warehouse and can no longer be cancelled. */
    public const SHIPPED_STATUSES = ['partially_shipped', 'shipped', 'delivered', 'returned'];

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'order_number',
        'invoice_number',
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
        'invoiced_at',
    ];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'total_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'invoiced_at' => 'datetime',
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

    /**
     * The order with its fulfillment state and the next steps it allows, as shown on the order screen and
     * after a barcode scan.
     *
     * @return array<string, mixed>
     */
    public function fulfillmentSummary(): array
    {
        $this->loadMissing(['store', 'items', 'pickTasks', 'shipments']);
        $task = $this->pickTasks->first();
        $shipment = $this->shipments->sortByDesc('id')->first();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'customer' => $this->customer_name ?: 'Unknown',
            'customer_email' => $this->customer_email,
            'store' => $this->store?->name,
            'order_status' => $this->order_status,
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'items' => $this->items->map(fn (OrderItem $item) => [
                'product_name' => $item->product_name,
                'sku' => $item->external_sku,
                'quantity' => $item->quantity,
            ]),
            'can_allocate' => in_array($this->order_status, self::ALLOCATABLE_STATUSES, true),
            'can_cancel' => $this->order_status !== 'cancelled' && ! in_array($this->order_status, self::SHIPPED_STATUSES, true),
            // The picking step can still be changed only while the order has not left the warehouse.
            'pick_task' => $task && ! in_array($task->status, PickTask::CLOSED, true)
                ? ['id' => $task->id, 'status' => $task->status]
                : null,
            'shipment' => $shipment ? [
                'id' => $shipment->id,
                'status' => $shipment->status,
                'carrier' => $shipment->carrier,
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->trackingLink(),
            ] : null,
        ];
    }
}
