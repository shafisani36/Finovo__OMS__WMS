<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceivedNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'grn_number',
        'purchase_order_id',
        'warehouse_id',
        'receiving_location_id',
        'supplier_name',
        'supplier_delivery_note',
        'carrier',
        'tracking_or_vehicle_number',
        'received_by_user_id',
        'inspected_by_user_id',
        'status',
        'received_at',
        'inspected_at',
        'posted_at',
        'posted_by_user_id',
        'total_received_units',
        'total_accepted_units',
        'total_damaged_units',
        'total_quarantined_units',
        'total_rejected_units',
        'notes',
        'discrepancy_summary',
        'pdf_path',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
        'posted_at' => 'datetime',
        'total_received_units' => 'integer',
        'total_accepted_units' => 'integer',
        'total_damaged_units' => 'integer',
        'total_quarantined_units' => 'integer',
        'total_rejected_units' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivingLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'receiving_location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class);
    }
}
