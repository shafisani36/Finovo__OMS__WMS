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
}
