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
 * Pakistani courier integrations: booking parcels at dispatch, labels, and following each parcel's status until
 * it is delivered (a delivered parcel marks the shipment and order delivered by itself).
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
     * Ask the couriers for the latest status of every parcel still on its way. A delivered parcel marks the
     * shipment delivered; the status of the others is kept on the shipment.
     *
     * @return array{checked: int, updated: int, delivered: int, failed_accounts: int}
     */
    public function syncTracking(?CourierAccount $only = null): array
    {
        $result = ['checked' => 0, 'updated' => 0, 'delivered' => 0, 'failed_accounts' => 0];
        $accounts = $only ? collect([$only]) : CourierAccount::where('is_active', true)->get();

        foreach ($accounts as $account) {
            $account->shipments()
                ->where('status', 'shipped')
                ->whereNotNull('tracking_number')
                ->chunkById(50, function ($shipments) use ($account, &$result) {
                    try {
                        $statuses = $this->gateways->for($account)->track($account, $shipments->pluck('tracking_number')->all());
                    } catch (CourierException $e) {
                        $account->update(['last_error' => $e->getMessage()]);
                        $result['failed_accounts']++;

                        return false;
                    }

                    foreach ($shipments as $shipment) {
                        $result['checked']++;
                        $status = $statuses[$shipment->tracking_number] ?? null;
                        if ($status && $this->apply($shipment, $status)) {
                            $result['updated']++;
                            $result['delivered'] += $status->status === CourierStatus::DELIVERED ? 1 : 0;
                        }
                    }
                });

            $account->update(['last_tracked_at' => now()]);
        }

        return $result;
    }

    /**
     * Keep the courier's status on the shipment; a delivered parcel is marked delivered in Finovo.
     */
    private function apply(Shipment $shipment, CourierStatus $status): bool
    {
        if ($shipment->courier_status === $status->status && $shipment->courier_status_text === $status->text) {
            return false;
        }

        $shipment->update([
            'courier_status' => $status->status,
            'courier_status_text' => mb_substr($status->text, 0, 255),
            'courier_status_at' => $status->at ?? now(),
        ]);

        if ($status->status === CourierStatus::DELIVERED) {
            $shipment->markDelivered();
        }

        return true;
    }
}
