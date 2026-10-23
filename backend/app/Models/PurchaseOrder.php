<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'store_id',
        'po_number',
        'purchase_requisition_id',
        'supplier_name',
        'supplier_email',
        'supplier_contact',
        'warehouse_id',
        'created_by_user_id',
        'status',
        'order_date',
        'expected_delivery_date',
        'payment_terms',
        'currency',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'issued_at',
        'cancelled_at',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class);
    }

    /**
     * Next purchase order number for the organization: PO-2026-0001, PO-2026-0002, ...
     * The highest existing number is locked, so two orders created together get different numbers.
     */
    public static function nextNumber(int $organizationId): string
    {
        $prefix = 'PO-'.now()->year.'-';
        $last = static::where('organization_id', $organizationId)
            ->where('po_number', 'like', $prefix.'%')
            ->orderByDesc('po_number')
            ->lockForUpdate()
            ->value('po_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
