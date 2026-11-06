<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitSerialNumber extends Model
{
    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_ALLOCATED = 'allocated';

    public const STATUS_PICKED = 'picked';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_DAMAGED = 'damaged';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'organization_id',
        'product_variant_id',
        'product_id',
        'warehouse_id',
        'location_id',
        'serial_number',
        'lot_number',
        'status',
        'grn_id',
        'grn_item_id',
        'order_id',
        'order_item_id',
        'pick_task_id',
        'order_return_id',
        'return_item_id',
        'received_at',
        'shipped_at',
        'returned_at',
        'history',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'shipped_at' => 'datetime',
            'returned_at' => 'datetime',
            'history' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function goodsReceivedNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function pickTask(): BelongsTo
    {
        return $this->belongsTo(PickTask::class);
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(ReturnItem::class);
    }

    /**
     * Append a lifecycle event to this serial number's audit trail.
     */
    public function recordEvent(string $event, ?int $userId = null, ?string $reference = null, array $details = []): self
    {
        $events = $this->history ?? [];
        $events[] = [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'user_id' => $userId,
            'reference' => $reference,
            'details' => $details,
        ];
        $this->history = $events;

        return $this;
    }
}
