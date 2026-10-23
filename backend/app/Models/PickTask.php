<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PickTask extends Model
{
    use HasFactory;

    /** Statuses a picker can set, in order. Packing an order hands it over to Shipments for dispatch. */
    public const STATUSES = ['not_started', 'in_progress', 'picked', 'packed'];

    /** Tasks in these statuses are finished with and no longer appear on the picking list. */
    public const CLOSED = ['shipped', 'cancelled'];

    protected $fillable = [
        'organization_id',
        'order_id',
        'warehouse_id',
        'status',
        'assigned_to_user_id',
        'items_count',
        'priority',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'items_count' => 'integer',
            'started_at' => 'datetime',
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

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** The order's shipment that is waiting to be dispatched. */
    public function pendingShipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'order_id', 'order_id')->where('status', 'pending');
    }

    /**
     * Move the task to another picking status and keep the order in step. A packed order gets a shipment
     * waiting for dispatch; moving it back out of packed withdraws that shipment again.
     */
    public function moveTo(string $status, User $user): self
    {
        if (in_array($this->status, self::CLOSED, true)) {
            throw ValidationException::withMessages(['status' => "Order {$this->order?->order_number} is already {$this->status} and can no longer be picked."]);
        }

        return DB::transaction(function () use ($status, $user) {
            $this->status = $status;

            if ($status === 'in_progress' && ! $this->started_at) {
                $this->started_at = now();
                $this->assigned_to_user_id = $user->id;
            }

            $this->completed_at = in_array($status, ['picked', 'packed'], true) ? ($this->completed_at ?? now()) : null;
            $this->save();

            $orderStatus = ['not_started' => 'allocated', 'in_progress' => 'picking', 'picked' => 'picked', 'packed' => 'packed'][$status];
            $this->order?->update(['order_status' => $orderStatus]);

            if ($status === 'packed') {
                Shipment::firstOrCreate(
                    ['organization_id' => $this->organization_id, 'order_id' => $this->order_id, 'status' => 'pending'],
                    ['warehouse_id' => $this->warehouse_id],
                );
            } else {
                $this->pendingShipment()->delete();
            }

            return $this;
        });
    }

    /** The status after this one, used by the quick-advance action. */
    public function nextStatus(): string
    {
        $index = array_search($this->status, self::STATUSES, true);

        return self::STATUSES[min(count(self::STATUSES) - 1, $index === false ? 0 : $index + 1)];
    }
}
