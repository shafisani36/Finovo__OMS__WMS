<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An order on its way to the customer: pending (packed, waiting for dispatch) → shipped → delivered.
 */
class Shipment extends Model
{
    use HasFactory;

    /** Generated tracking IDs: FNV followed by random letters and digits. */
    private const TRACKING_PREFIX = 'FNV';

    private const TRACKING_RANDOM_LENGTH = 10;

    /** Random IDs tried before falling back to one built from the shipment's own ID. */
    private const TRACKING_ATTEMPTS = 5;

    /** Carriers whose tracking page takes the tracking number in the address; {number} is replaced. */
    private const TRACKING_PAGES = [
        'dhl' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={number}',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr={number}',
        'ups' => 'https://www.ups.com/track?tracknum={number}',
    ];

    protected $fillable = [
        'organization_id',
        'order_id',
        'warehouse_id',
        'tracking_number',
        'tracking_url',
        'carrier',
        'status',
        'shipped_at',
        'delivered_at',
        'dispatched_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    /**
     * Where the customer can follow the parcel: the link given at dispatch, otherwise the carrier's own
     * tracking page when it is known.
     */
    public function trackingLink(): ?string
    {
        if ($this->tracking_url) {
            return $this->tracking_url;
        }

        $page = self::TRACKING_PAGES[strtolower((string) $this->carrier)] ?? null;

        return $page && $this->tracking_number ? str_replace('{number}', rawurlencode($this->tracking_number), $page) : null;
    }

    /**
     * A tracking ID no other shipment of the organization uses. A random ID is tried a few times; if each one
     * is already taken, the ID is built from this shipment's own (unique) ID, so a free one is always returned.
     */
    public function generateTrackingNumber(): string
    {
        for ($attempt = 0; $attempt < self::TRACKING_ATTEMPTS; $attempt++) {
            $candidate = self::TRACKING_PREFIX.Str::upper(Str::random(self::TRACKING_RANDOM_LENGTH));

            if (! self::trackingNumberTaken($this->organization_id, $candidate)) {
                return $candidate;
            }
        }

        return self::TRACKING_PREFIX.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT).Str::upper(Str::random(4));
    }

    public static function trackingNumberTaken(int $organizationId, string $trackingNumber, ?int $exceptId = null): bool
    {
        return self::where('organization_id', $organizationId)
            ->where('tracking_number', $trackingNumber)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }
}
