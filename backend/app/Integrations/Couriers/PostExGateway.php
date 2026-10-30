<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * PostEx merchant API (merchant token in the "token" header). Replies come as
 * {statusCode, statusMessage, dist}; anything but statusCode 200 is a refusal.
 */
class PostExGateway extends HttpCourierGateway
{
    private const PATH = 'services/integration/api/order';

    protected function liveUrl(): string
    {
        return 'https://api.postex.pk';
    }

    protected function sandboxUrl(): ?string
    {
        return null;
    }

    protected function name(): string
    {
        return 'PostEx';
    }

    public function book(CourierAccount $account, Parcel $parcel): CourierBooking
    {
        $dist = $this->dist($this->send($account, fn ($http) => $this->authorised($http, $account)->post(self::PATH.'/v3/create-order', array_filter([
            'orderRefNumber' => $parcel->reference,
            'invoicePayment' => (string) $parcel->codAmount,
            'customerName' => $parcel->customerName,
            'customerPhone' => $parcel->customerPhone,
            'deliveryAddress' => $parcel->address,
            'cityName' => $parcel->city,
            'invoiceDivision' => 1,
            'items' => $parcel->pieces,
            'orderType' => 'Normal',
            'orderDetail' => $parcel->description,
            'transactionNotes' => $parcel->notes,
            'pickupAddressCode' => $account->setting('pickup_address_code'),
        ], fn ($value) => $value !== null && $value !== ''))));

        return new CourierBooking((string) ($dist['trackingNumber'] ?? $this->fail('no tracking number was returned.')));
    }

    public function track(CourierAccount $account, array $trackingNumbers): array
    {
        $statuses = [];

        foreach ($trackingNumbers as $number) {
            $response = $this->send($account, fn ($http) => $this->authorised($http, $account)->get(self::PATH.'/v1/track-order/'.rawurlencode($number)));
            if ($response->clientError()) {
                continue;
            }

            $dist = $this->dist($response);
            $last = collect($dist['transactionStatusHistory'] ?? [])->last();
            $statuses[$number] = CourierStatus::fromText(
                (string) ($dist['transactionStatus'] ?? $last['transactionStatusMessage'] ?? ''),
                isset($last['modifiedDatetime']) ? Carbon::parse($last['modifiedDatetime']) : null,
            );
        }

        return $statuses;
    }

    public function label(CourierAccount $account, string $trackingNumber): CourierLabel
    {
        $response = $this->send($account, fn ($http) => $this->authorised($http, $account)->accept('application/pdf')->get(self::PATH.'/v1/get-invoice', ['trackingNumbers' => $trackingNumber]));

        if (! str_contains((string) $response->header('Content-Type'), 'pdf')) {
            $this->fail((string) ($response->json('statusMessage') ?? 'the airway bill could not be generated.'));
        }

        return new CourierLabel(pdf: $response->body());
    }

    public function cancel(CourierAccount $account, string $trackingNumber): void
    {
        $this->dist($this->send($account, fn ($http) => $this->authorised($http, $account)->put(self::PATH.'/v1/cancel-order', ['trackingNumber' => $trackingNumber])));
    }

    public function test(CourierAccount $account): void
    {
        $this->dist($this->send($account, fn ($http) => $this->authorised($http, $account)->get(self::PATH.'/v2/get-operational-city')));
    }

    private function authorised($http, CourierAccount $account)
    {
        return $http->withHeaders(['token' => $account->credential('api_token')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dist(Response $response): array
    {
        $code = (string) ($response->json('statusCode') ?? $response->status());

        if ($response->failed() || ! in_array($code, ['200', 'SUCCESS'], true)) {
            $this->fail((string) ($response->json('statusMessage') ?? $response->json('message') ?? 'the request was refused.'));
        }

        return (array) $response->json('dist', []);
    }
}
