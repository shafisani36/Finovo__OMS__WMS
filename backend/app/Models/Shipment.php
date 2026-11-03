<?php

namespace App\Models;

use App\Services\StoreSyncOutbox;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An order on its way to the customer: pending (packed, waiting for dispatch) → shipped → delivered, or
 * returned when the courier brings the parcel back. Cancelling a dispatch puts a shipped parcel back to pending.
 */
class Shipment extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const STATUSES = [self::PENDING, self::SHIPPED, self::DELIVERED, self::RETURNED];

    /**
     * Carriers offered when the details are entered by hand, with their tracking page ({number} is replaced),
     * or null when the carrier has no tracking page by number. The single list for the app and the API.
     */
    public const CARRIERS = [
        'TCS' => 'https://www.tcsexpress.com/track/{number}',
        'Leopards Courier' => 'https://www.leopardscourier.com/tracking?cn={number}',
        'M&P' => 'https://www.mulphilog.com/tracking/{number}',
        'PostEx' => 'https://merchant.postex.pk/?cn={number}',
        'Trax (Sonic)' => 'https://sonic.pk/tracking?tracking_number={number}',
        'Call Courier' => 'https://callcourier.com.pk/tracking/?tc={number}',
        'BlueEx' => null,
        'DHL' => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id={number}',
        'FedEx' => 'https://www.fedex.com/fedextrack/?trknbr={number}',
        'UPS' => 'https://www.ups.com/track?tracknum={number}',
    ];

    /** Other spellings people type for the carriers above. */
    private const CARRIER_ALIASES = [
        'leopard' => 'Leopards Courier',
        'leopards' => 'Leopards Courier',
        'tcs express' => 'TCS',
        'post ex' => 'PostEx',
        'trax' => 'Trax (Sonic)',
        'sonic' => 'Trax (Sonic)',
        'mnp' => 'M&P',
        'callcourier' => 'Call Courier',
        'blue ex' => 'BlueEx',
    ];

    protected static function booted(): void
    {
        // A dispatched or delivered shipment is reported to the order's store.
        static::saved(function (Shipment $shipment) {
            if (($shipment->wasRecentlyCreated || $shipment->wasChanged('status')) && in_array($shipment->status, ['shipped', 'delivered'], true)) {
                app(StoreSyncOutbox::class)->shipmentChanged($shipment);
            }
        });
    }

    /** Generated tracking IDs: FNV followed by random letters and digits. */
    private const TRACKING_PREFIX = 'FNV';

    private const TRACKING_RANDOM_LENGTH = 10;

    /** Random IDs tried before falling back to one built from the shipment's own ID. */
    private const TRACKING_ATTEMPTS = 5;

    protected $fillable = [
        'organization_id',
        'order_id',
        'warehouse_id',
        'tracking_number',
        'tracking_url',
        'carrier',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'pieces',
        'cod_amount',
        'status',
        'shipped_at',
        'delivered_at',
        'returned_at',
        'dispatched_by_user_id',
        'courier_account_id',
        'label_url',
        'courier_status',
        'courier_status_text',
        'courier_status_at',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'courier_status_at' => 'datetime',
            'weight_kg' => 'decimal:2',
            'length_cm' => 'decimal:1',
            'width_cm' => 'decimal:1',
            'height_cm' => 'decimal:1',
            'pieces' => 'integer',
            'cod_amount' => 'decimal:2',
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

    /** The courier account the parcel was booked with, when it was booked through Finovo. */
    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    /** Cash the courier collects: as entered at dispatch, otherwise the order total unless it is already paid. */
    public function cashToCollect(): float
    {
        if ($this->cod_amount !== null) {
            return (float) $this->cod_amount;
        }

        return $this->order?->payment_status === 'paid' ? 0.0 : round((float) $this->order?->total_amount, 2);
    }

    /** The shipment's timeline, oldest first. */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('happened_at')->orderBy('id');
    }

    public function record(string $type, string $description, ?int $userId = null, ?string $courierStatus = null, mixed $at = null): ShipmentEvent
    {
        return $this->events()->create([
            'type' => $type,
            'description' => mb_substr($description, 0, 255),
            'courier_status' => $courierStatus,
            'user_id' => $userId,
            'happened_at' => $at ?? now(),
        ]);
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

        $page = self::CARRIERS[self::carrierName((string) $this->carrier)] ?? null;

        return $page && $this->tracking_number ? str_replace('{number}', rawurlencode($this->tracking_number), $page) : null;
    }

    /**
     * The carrier's name as listed in CARRIERS (any spelling or case), or the name as given when it is not listed.
     */
    public static function carrierName(string $carrier): string
    {
        $key = strtolower(trim($carrier));

        foreach (array_keys(self::CARRIERS) as $name) {
            if (strtolower($name) === $key) {
                return $name;
            }
        }

        return self::CARRIER_ALIASES[$key] ?? trim($carrier);
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
