<?php

namespace App\Services;

use App\Integrations\Couriers\CourierBooking;
use App\Integrations\Couriers\CourierException;
use App\Integrations\Couriers\CourierGatewayFactory;
use App\Integrations\Couriers\CourierLabel;
use App\Integrations\Couriers\CourierStatus;
use App\Integrations\Couriers\Parcel;
use App\Models\CourierAccount;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pakistani courier integrations: booking and cancelling parcels, labels, tracking and checking accounts. What a
 * courier status means for the shipment is decided in ShipmentService.
 */
class CourierService
{
    /**
     * The couriers Finovo books with, and what each account needs. "credentials" are stored encrypted;
     * "settings" are booking defaults. Fields marked required must be filled to save the account.
     */
    public const CARRIERS = [
        'leopards' => [
            'name' => 'Leopards Courier',
            'sandbox' => true,
            'labels' => true,
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'required' => true],
                ['key' => 'api_password', 'label' => 'API password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'origin_city_id', 'label' => 'Origin city ID (blank: the account\'s city)', 'required' => false],
            ],
        ],
        'postex' => [
            'name' => 'PostEx',
            'sandbox' => false,
            'labels' => true,
            'credentials' => [
                ['key' => 'api_token', 'label' => 'Merchant API token', 'required' => true],
            ],
            'settings' => [
                ['key' => 'pickup_address_code', 'label' => 'Pickup address code (blank: the default address)', 'required' => false],
            ],
        ],
        'tcs' => [
            'name' => 'TCS',
            'sandbox' => true,
            'labels' => false,
            'credentials' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Client secret', 'required' => true],
                ['key' => 'username', 'label' => 'User name', 'required' => true],
                ['key' => 'password', 'label' => 'Password', 'required' => true],
                ['key' => 'account_number', 'label' => 'TCS account number', 'required' => true],
            ],
            'settings' => [
                ['key' => 'cost_center_code', 'label' => 'Cost center code', 'required' => true],
                ['key' => 'service_code', 'label' => 'Service code (O = overnight)', 'required' => false],
                ['key' => 'origin_city', 'label' => 'Origin city', 'required' => true],
                ['key' => 'shipper_name', 'label' => 'Shipper name', 'required' => true],
                ['key' => 'shipper_phone', 'label' => 'Shipper mobile', 'required' => true],
                ['key' => 'shipper_address', 'label' => 'Shipper address', 'required' => true],
            ],
        ],
        'trax' => [
            'name' => 'Trax (Sonic)',
            'sandbox' => true,
            'labels' => true,
            'credentials' => [
                ['key' => 'api_key', 'label' => 'API key', 'required' => true],
            ],
            'settings' => [
                ['key' => 'pickup_address_id', 'label' => 'Pickup address ID', 'required' => true],
                ['key' => 'service_type_id', 'label' => 'Service type ID (1 = regular)', 'required' => false],
                ['key' => 'shipping_mode_id', 'label' => 'Shipping mode ID (1 = rush)', 'required' => false],
            ],
        ],
    ];

    /** Settings every courier account has. */
    public const COMMON_SETTINGS = [
        ['key' => 'default_weight_kg', 'label' => 'Parcel weight (kg)', 'required' => false],
        ['key' => 'api_url', 'label' => 'API address (leave blank)', 'required' => false],
    ];

    public function __construct(
        protected CourierGatewayFactory $gateways
    ) {}

    /**
     * Book the shipment's parcel with the account; the courier's message becomes a validation error.
     *
     * @throws ValidationException
     */
    public function book(Shipment $shipment, CourierAccount $account): CourierBooking
    {
        try {
            return $this->gateways->for($account)->book($account, Parcel::fromShipment($shipment, $account));
        } catch (CourierException $e) {
            throw ValidationException::withMessages(['courier_account_id' => $e->getMessage()]);
        }
    }

    /**
     * Undo a booking that could not be saved in Finovo, so the parcel is not left booked with the courier.
     */
    public function cancelQuietly(CourierAccount $account, string $trackingNumber): void
    {
        try {
            $this->gateways->for($account)->cancel($account, $trackingNumber);
        } catch (Throwable $e) {
            Log::warning("Courier booking {$trackingNumber} could not be cancelled after a failed dispatch.", ['error' => $e->getMessage()]);
        }
    }

    /**
     * The shipment's label: the courier's link, or the PDF fetched from the courier.
     *
     * @throws ValidationException
     */
    public function label(Shipment $shipment): CourierLabel
    {
        if ($shipment->label_url) {
            return new CourierLabel(url: $shipment->label_url);
        }

        $account = $shipment->courierAccount ?? throw ValidationException::withMessages(['label' => 'This parcel was not booked through a courier account, so there is no courier label.']);

        try {
            return $this->gateways->for($account)->label($account, (string) $shipment->tracking_number);
        } catch (CourierException $e) {
            throw ValidationException::withMessages(['label' => $e->getMessage()]);
        }
    }

    /**
     * Check an account's credentials with the courier.
     *
     * @throws ValidationException
     */
    public function test(CourierAccount $account): void
    {
        try {
            $this->gateways->for($account)->test($account);
            $account->update(['last_error' => null]);
        } catch (CourierException $e) {
            $account->update(['last_error' => $e->getMessage()]);

            throw ValidationException::withMessages(['credentials' => $e->getMessage()]);
        }
    }

    /**
     * Latest status of each tracking number with the account's courier.
     *
     * @param  list<string>  $trackingNumbers
     * @return array<string, CourierStatus>
     *
     * @throws CourierException
     */
    public function track(CourierAccount $account, array $trackingNumbers): array
    {
        return $this->gateways->for($account)->track($account, $trackingNumbers);
    }

    /**
     * Cancel a booking with the courier; the courier's refusal becomes a validation error.
     *
     * @throws ValidationException
     */
    public function cancel(CourierAccount $account, string $trackingNumber): void
    {
        try {
            $this->gateways->for($account)->cancel($account, $trackingNumber);
        } catch (CourierException $e) {
            throw ValidationException::withMessages(['shipment' => $e->getMessage()]);
        }
    }

    /** The courier's display name for an account's carrier code. */
    public static function carrierName(string $carrier): string
    {
        return self::CARRIERS[$carrier]['name'] ?? $carrier;
    }

    /** Whether the carrier makes printable labels. */
    public static function makesLabels(?string $carrier): bool
    {
        return (bool) (self::CARRIERS[$carrier]['labels'] ?? false);
    }
}
