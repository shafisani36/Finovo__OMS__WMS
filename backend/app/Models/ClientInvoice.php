<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A 3PL client's invoice for one period: storage, receiving, orders, returns and any manual charges.
 * A draft can still be recalculated and changed; once issued it is fixed, then paid or voided.
 */
class ClientInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'organization_id',
        'client_id',
        'invoice_number',
        'period_start',
        'period_end',
        'status',
        'currency',
        'total',
        'due_date',
        'notes',
        'created_by',
        'issued_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'total' => 'float',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClientInvoiceLine::class)->orderBy('id');
    }

    /** An issued invoice past its due date that is still unpaid. */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ISSUED && $this->due_date?->isPast() && ! $this->due_date->isToday();
    }

    /**
     * Next invoice number for the organization, e.g. BILL-2026-0007. Call inside a transaction.
     */
    public static function nextNumber(int $organizationId): string
    {
        $prefix = 'BILL-'.now()->year.'-';
        $last = static::where('organization_id', $organizationId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->value('invoice_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
