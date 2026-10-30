<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * TCS e-commerce (ENVIO) API. Two tokens are needed: a bearer token from the client ID and secret, and an
 * e-commerce access token from the account's user name and password, which goes in the request body.
 */
class TcsGateway extends HttpCourierGateway
{
    protected function liveUrl(): string
    {
        return 'https://ociconnect.tcscourier.com';
    }

    protected function sandboxUrl(): ?string
    {
        return 'https://devconnect.tcscourier.com';
    }

    protected function name(): string
    {
        return 'TCS';
    }

    public function book(CourierAccount $account, Parcel $parcel): CourierBooking
    {
        $body = $this->call($account, 'post', 'ecom/api/booking/create', [
            'accesstoken' => $this->accessToken($account),
            'consignmentno' => '',
            'shipperinfo' => [
                'tcsaccount' => $account->credential('account_number'),
                'shippername' => (string) $account->setting('shipper_name', ''),
                'address1' => (string) $account->setting('shipper_address', ''),
                'countrycode' => 'PK',
                'countryname' => 'Pakistan',
                'cityname' => (string) $account->setting('origin_city', ''),
                'mobile' => (string) $account->setting('shipper_phone', ''),
            ],
            'consigneeinfo' => [
                'firstname' => $parcel->customerName,
                'middlename' => '',
                'address1' => $parcel->address,
                'countrycode' => 'PK',
                'countryname' => 'Pakistan',
                'cityname' => $parcel->city,
                'mobile' => $parcel->customerPhone,
                'email' => $parcel->customerEmail,
            ],
            'shipmentinfo' => [
                'costcentercode' => (string) $account->setting('cost_center_code', ''),
                'servicecode' => (string) $account->setting('service_code', 'O'),
                'referenceno' => $parcel->reference,
                'contentdesc' => $parcel->description,
                'currency' => $parcel->currency,
                'codamount' => $parcel->codAmount,
                'weightinkg' => $parcel->weightKg,
                'pieces' => $parcel->pieces,
                'fragile' => 'N',
                'remarks' => $parcel->notes,
            ],
        ]);

        return new CourierBooking((string) ($body['consignmentNo'] ?? $this->fail('no consignment number was returned.')));
    }

    public function track(CourierAccount $account, array $trackingNumbers): array
    {
        $statuses = [];

        foreach ($trackingNumbers as $number) {
            $body = $this->call($account, 'get', 'tracking/api/Tracking/GetDynamicTrackDetail', ['consignee' => $number]);
            $latest = collect($body['deliveryinfo'] ?? [])->first() ?? collect($body['checkpoints'] ?? [])->first();

            if ($latest) {
                $statuses[$number] = CourierStatus::fromText((string) ($latest['status'] ?? ''), isset($latest['datetime']) ? Carbon::parse($latest['datetime']) : null);
            }
        }

        return $statuses;
    }

    public function label(CourierAccount $account, string $trackingNumber): CourierLabel
    {
        $this->fail('labels are printed from the TCS portal for this account.');
    }

    public function cancel(CourierAccount $account, string $trackingNumber): void
    {
        $this->call($account, 'post', 'ecom/api/booking/cancel', ['accesstoken' => $this->accessToken($account), 'consignmentNumber' => $trackingNumber]);
    }

    public function test(CourierAccount $account): void
    {
        $this->accessToken($account, fresh: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(CourierAccount $account, string $method, string $path, array $data): array
    {
        $response = $this->send($account, fn ($http) => $http->withToken($this->bearer($account))->{$method}($path, $data));
        $body = (array) $response->json();

        if ($response->failed() || (isset($body['status']) && ! in_array(strtolower((string) $body['status']), ['success', 'ok', '200', 'true', '1'], true))) {
            $this->fail((string) ($body['message'] ?? 'the request was refused.'));
        }

        return $body;
    }

    private function bearer(CourierAccount $account, bool $fresh = false): string
    {
        $key = "couriers.tcs.{$account->id}.bearer";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(50), function () use ($account) {
            $response = $this->send($account, fn ($http) => $http->get('auth/api/auth', [
                'clientid' => $account->credential('client_id'),
                'clientsecret' => $account->credential('client_secret'),
            ]));

            return (string) ($response->json('result.accessToken') ?? $response->json('accesstoken') ?? $this->fail('the client ID or secret was refused.'));
        });
    }

    private function accessToken(CourierAccount $account, bool $fresh = false): string
    {
        $key = "couriers.tcs.{$account->id}.access";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(50), function () use ($account, $fresh) {
            $response = $this->send($account, fn ($http) => $http->withToken($this->bearer($account, $fresh))->get('ecom/api/authentication/token', [
                'username' => $account->credential('username'),
                'password' => $account->credential('password'),
            ]));

            return (string) ($response->json('accesstoken') ?? $this->fail('the user name or password was refused.'));
        });
    }
}
