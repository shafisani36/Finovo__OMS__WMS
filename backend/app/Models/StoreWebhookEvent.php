<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification a store sent to Finovo (a new order, a changed product...). The delivery ID is unique per
 * store, so a notification the store sends twice is processed once.
 */
class StoreWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    /** Nothing to do for this notification (a topic Finovo does not use, or a test ping). */
    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['organization_id', 'store_id', 'topic', 'delivery_id', 'resource_id', 'status', 'attempts', 'error', 'processed_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
