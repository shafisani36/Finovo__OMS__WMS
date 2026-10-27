<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One update Finovo owes a connected store: the stock of a variant, an order's warehouse progress, a
 * shipped / delivered order, or a completed return.
 */
class StoreSyncTask extends Model
{
    /** Send the variant's available stock (subject: product_variants.id). */
    public const TYPE_STOCK = 'stock';

    /** Show the order's warehouse progress (subject: orders.id). */
    public const TYPE_STATUS = 'status';

    /** Show a completed return on the order (subject: returns.id). */
    public const TYPE_RETURN = 'return';

    /** Mark the order shipped or delivered with its tracking (subject: shipments.id). */
    public const TYPE_FULFILLMENT = 'fulfillment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    /** Gave up after repeated failures or a failure retrying cannot fix; can be retried by hand. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = ['organization_id', 'store_id', 'type', 'subject_id', 'status', 'attempts', 'available_at', 'last_error', 'processed_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
