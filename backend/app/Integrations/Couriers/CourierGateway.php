<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;

/**
 * One courier's API: book a parcel, get its label, follow its status and cancel a booking.
 * Every method throws CourierException with the courier's own message when the courier refuses.
 */
interface CourierGateway
{
    /** Book the parcel and return the courier's tracking (consignment) number. */
    public function book(CourierAccount $account, Parcel $parcel): CourierBooking;

    /**
     * Latest status of each tracking number; numbers the courier does not know are left out.
     *
     * @param  list<string>  $trackingNumbers
     * @return array<string, CourierStatus>
     */
    public function track(CourierAccount $account, array $trackingNumbers): array;

    /** The printable label (airway bill) of a booked parcel. */
    public function label(CourierAccount $account, string $trackingNumber): CourierLabel;

    public function cancel(CourierAccount $account, string $trackingNumber): void;

    /** Check that the credentials work (a light read-only call). */
    public function test(CourierAccount $account): void;
}
