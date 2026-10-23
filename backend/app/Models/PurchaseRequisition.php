<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'store_id',
        'pr_number',
        'requester_id',
        'department',
        'warehouse_id',
        'delivery_location_id',
        'priority',
        'status',
        'required_by_date',
        'purpose',
        'justification',
        'notes',
        'subtotal',
        'tax_amount',
        'total_amount',
        'currency',
        'submitted_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
    ];

    protected $casts = [
        'required_by_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveryLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'delivery_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionApproval::class)->orderBy('created_at', 'asc');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Status after purchase orders were raised or cancelled: ordered when nothing approved is left to order,
     * partially ordered when some is, approved again when every purchase order was cancelled.
     */
    public function refreshOrderStatus(): void
    {
        $items = $this->items()->get();

        $this->update(['status' => match (true) {
            $items->every(fn (PurchaseRequisitionItem $item) => $item->remaining_quantity === 0) => 'ordered',
            $items->contains(fn (PurchaseRequisitionItem $item) => $item->ordered_quantity > 0) => 'partially_ordered',
            default => 'approved',
        }]);
    }

    /**
     * Next requisition number for the organization: PR-2026-0001, PR-2026-0002, ...
     * The highest existing number is locked, so two requisitions created together get different numbers.
     */
    public static function nextNumber(int $organizationId): string
    {
        $prefix = 'PR-'.now()->year.'-';
        $last = static::where('organization_id', $organizationId)
            ->where('pr_number', 'like', $prefix.'%')
            ->orderByDesc('pr_number')
            ->lockForUpdate()
            ->value('pr_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
