<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a shipment's timeline: dispatched, a courier status update, delivered, returned or dispatch cancelled.
 */
class ShipmentEvent extends Model
{
    public const DISPATCHED = 'dispatched';

    public const COURIER = 'courier';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const DISPATCH_CANCELLED = 'dispatch_cancelled';

    protected $fillable = [
        'shipment_id',
        'type',
        'courier_status',
        'description',
        'user_id',
        'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
