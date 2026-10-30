<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use Illuminate\Support\Facades\Cache;

/**
 * Trax (Sonic) API, version 2: the account's API key in the Authorization header. A reply with status 0 is
 * a success; cities and the pickup address are sent as Trax IDs.
 */
class TraxGateway extends HttpCourierGateway
{
    protected function liveUrl(): string
    {
        return 'https://sonic.pk/api';
    }

    protected function sandboxUrl(): ?string
    {
        return 'https://app.sonic.pk/api';
    }

    protected function name(): string
    {
        return 'Trax';
    }

    public function book(CourierAccount $account, Parcel $parcel): CourierBooking
    {
        $body = $this->call($account, 'post', 'shipment/book', [
            'service_type_id' => (int) $account->setting('service_type_id', 1),
            'pickup_address_id' => (int) $account->setting('pickup_address_id', 0),
            'information_display' => 1,
            'consignee_city_id' => $this->cityId($account, $parcel->city),
            'consignee_name' => $parcel->customerName,
            'consignee_address' => $parcel->address,
            'consignee_phone_number_1' => $parcel->customerPhone,
            'consignee_email_address' => $parcel->customerEmail,
            'order_id' => $parcel->reference,
            'item_product_type_id' => (int) $account->setting('product_type_id', 1),
            'item_description' => $parcel->description,
            'item_quantity' => $parcel->pieces,
            'item_insurance' => 0,
            'pickup_date' => now()->toDateString(),
            'special_instructions' => $parcel->notes,
            'estimated_weight' => $parcel->weightKg,
            'shipping_mode_id' => (int) $account->setting('shipping_mode_id', 1),
            'amount' => $parcel->codAmount,
            'payment_mode_id' => $parcel->codAmount > 0 ? 1 : 4,
            'charges_mode_id' => 4,
        ]);

        return new CourierBooking((string) ($body['tracking_number'] ?? $this->fail('no tracking number was returned.')));
    }

    public function track(CourierAccount $account, array $trackingNumbers): array
    {
        $statuses = [];

        foreach ($trackingNumbers as $number) {
            $body = $this->call($account, 'get', 'shipment/track', ['tracking_number' => $number, 'type' => 0]);
            $text = $body['details']['tracking_history'][0]['status'] ?? $body['details']['current_status'] ?? $body['current_status'] ?? null;

            if ($text) {
                $statuses[$number] = CourierStatus::fromText((string) $text);
            }
        }

        return $statuses;
    }

    public function label(CourierAccount $account, string $trackingNumber): CourierLabel
    {
        $response = $this->send($account, fn ($http) => $this->authorised($http, $account)->accept('application/pdf')->get('shipment/air_waybill', ['tracking_number' => $trackingNumber, 'type' => 0]));

        if (! str_contains((string) $response->header('Content-Type'), 'pdf')) {
            $this->fail((string) ($response->json('message') ?? 'the airway bill could not be generated.'));
        }

        return new CourierLabel(pdf: $response->body());
    }

    public function cancel(CourierAccount $account, string $trackingNumber): void
    {
        $this->call($account, 'post', 'shipment/cancel', ['tracking_number' => $trackingNumber]);
    }

    public function test(CourierAccount $account): void
    {
        Cache::forget("couriers.trax.{$account->id}.cities");
        $this->cities($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(CourierAccount $account, string $method, string $path, array $data): array
    {
        $response = $this->send($account, fn ($http) => $this->authorised($http, $account)->{$method}($path, $data));
        $body = (array) $response->json();

        if ($response->failed() || (int) ($body['status'] ?? 1) !== 0) {
            $this->fail((string) ($body['message'] ?? 'the request was refused.'));
        }

        return $body;
    }

    private function authorised($http, CourierAccount $account)
    {
        return $http->withHeaders(['Authorization' => $account->credential('api_key')]);
    }

    private function cityId(CourierAccount $account, string $city): int
    {
        $match = collect($this->cities($account))->first(fn (array $row) => strcasecmp(trim((string) ($row['name'] ?? '')), $city) === 0);

        return (int) ($match['id'] ?? $this->fail("no Trax city matches \"{$city}\". Check the city in the order's shipping address."));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cities(CourierAccount $account): array
    {
        return Cache::remember("couriers.trax.{$account->id}.cities", now()->addDay(), fn () => $this->call($account, 'get', 'cities', [])['cities'] ?? []);
    }
}
