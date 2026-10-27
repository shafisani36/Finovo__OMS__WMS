<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInvoiceLine extends Model
{
    /** A line added by hand; recalculating a draft keeps it. */
    public const TYPE_MANUAL = 'manual';

    protected $fillable = ['client_invoice_id', 'type', 'description', 'quantity', 'rate', 'amount'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'rate' => 'float',
            'amount' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }
}
