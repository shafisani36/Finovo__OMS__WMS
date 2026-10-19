<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionApproval extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'purchase_requisition_id',
        'user_id',
        'action',
        'comments',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
