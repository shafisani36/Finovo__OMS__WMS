<?php

namespace App\Models;

use App\Services\StoreSyncOutbox;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer return (RMA): requested → received at the warehouse → completed after inspection, or rejected.
 */
class OrderReturn extends Model
{
    use HasFactory;

    /** What happens to returned goods after inspection. */
    public const DISPOSITIONS = ['sellable', 'damaged', 'quarantine', 'disposed'];

    protected $table = 'returns';

    protected static function booted(): void
    {
        // A completed return is noted on the order's store.
        static::saved(function (OrderReturn $return) {
            if ($return->wasChanged('status') && $return->status === 'completed') {
                app(StoreSyncOutbox::class)->returnCompleted($return);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'order_id',
        'warehouse_id',
        'created_by_user_id',
        'return_number',
        'status',
        'reason',
        'disposition',
        'notes',
        'items_count',
        'received_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'items_count' => 'integer',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    /**
     * Next return number for the organization, e.g. RMA-2026-0007. Call inside a transaction.
     */
    public static function nextNumber(int $organizationId): string
    {
        $prefix = 'RMA-'.now()->year.'-';
        $last = static::where('organization_id', $organizationId)
            ->where('return_number', 'like', $prefix.'%')
            ->orderByDesc('return_number')
            ->lockForUpdate()
            ->value('return_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
