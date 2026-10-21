<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price offer to a customer. It can be downloaded as a PDF and, once accepted, converted into an order.
 */
class Quotation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    /** Statuses in which the quotation can still be edited. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_SENT];

    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'quotation_number',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'billing_address',
        'currency',
        'issue_date',
        'valid_until',
        'subtotal',
        'discount_total',
        'tax_total',
        'total_amount',
        'notes',
        'terms',
        'created_by',
        'order_id',
        'sent_at',
        'responded_at',
    ];

    protected $appends = ['is_expired'];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'issue_date' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** An open quotation past its validity date; the customer can no longer accept it. */
    protected function isExpired(): Attribute
    {
        return Attribute::get(fn () => in_array($this->status, self::EDITABLE, true)
            && $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast());
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('position');
    }
}
