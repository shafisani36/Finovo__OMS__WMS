<?php

namespace App\Integrations\Couriers;

use App\Models\CourierAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Leopards Courier merchant API (api_key and api_password sent with each request).
 * Cities are sent as Leopards city IDs, looked up by name from its city list.
 */
class LeopardsGateway extends HttpCourierGateway
{
    protected function liveUrl(): string
    {
        return 'https://merchantapi.leopardscourier.com/api';
    }

    protected function sandboxUrl(): ?string
    {
        return 'https://merchantapistaging.leopardscourier.com/api';
    }

    protected function name(): string
    {
        return 'Leopards';
    }

    public function book(CourierAccount $account, Parcel $parcel): CourierBooking
    {
        $body = $this->call($account, 'bookPacket', [
            // Leopards takes the weight in grams.
            'booked_packet_weight' => (int) round($parcel->weightKg * 1000),
            'booked_packet_no_piece' => $parcel->pieces,
            'booked_packet_collect_amount' => (int) round($parcel->codAmount),
            'booked_packet_order_id' => $parcel->reference,
            // "self" uses the shipper details and city saved on the Leopards account.
            'origin_city' => $account->setting('origin_city_id', 'self'),
            'destination_city' => $this->cityId($account, $parcel->city),
            'shipment_name_eng' => $account->setting('shipper_name', 'self'),
            'shipment_email' => $account->setting('shipper_email', 'self'),
            'shipment_phone' => $account->setting('shipper_phone', 'self'),
            'shipment_address' => $account->setting('shipper_address', 'self'),
            'consignment_name_eng' => $parcel->customerName,
            'consignment_email' => $parcel->customerEmail,
            'consignment_phone' => $parcel->customerPhone,
            'consignment_address' => $parcel->address,
            'special_instructions' => $parcel->notes ?: $parcel->description,
        ]);

        return new CourierBooking((string) $body['track_number'], $body['slip_link'] ?? null);
    }

    public function track(CourierAccount $account, array $trackingNumbers): array
    {
        $body = $this->call($account, 'trackBookedPacket', ['track_numbers' => implode(',', $trackingNumbers)]);

        return collect($body['packet_list'] ?? [])
            ->filter(fn ($packet) => ! empty($packet['track_number']))
            ->mapWithKeys(fn (array $packet) => [(string) $packet['track_number'] => CourierStatus::fromText(
                (string) ($packet['booked_packet_status'] ?? ''),
                $this->time(collect($packet['Tracking Detail'] ?? [])->last()['Activity_datetime'] ?? null),
            )])
            ->all();
    }

    public function label(CourierAccount $account, string $trackingNumber): CourierLabel
    {
        // The slip link is returned at booking and kept on the shipment.
        $this->fail('the label link was given at booking time and is not available afterwards.');
    }

    public function cancel(CourierAccount $account, string $trackingNumber): void
    {
        $this->call($account, 'cancelBookedPackets', ['cn_numbers' => $trackingNumber]);
    }

    public function test(CourierAccount $account): void
    {
        $this->cities($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function call(CourierAccount $account, string $endpoint, array $data): array
    {
        $response = $this->send($account, fn ($http) => $http->asForm()->post("{$endpoint}/format/json/", [
            'api_key' => $account->credential('api_key'),
            'api_password' => $account->credential('api_password'),
            ...$data,
        ]));
        $body = (array) $response->json();

        if ((int) ($body['status'] ?? 0) !== 1) {
            $this->fail((string) (is_array($body['error'] ?? null) ? json_encode($body['error']) : ($body['error'] ?? 'the request was refused.')));
        }

        return $body;
    }

    /**
     * Leopards city ID for a city name (the city list is kept for a day).
     */
    private function cityId(CourierAccount $account, string $city): int|string
    {
        $match = collect($this->cities($account))->first(fn (array $row) => strcasecmp(trim((string) ($row['name'] ?? '')), $city) === 0);

        return $match['id'] ?? $this->fail("no Leopards city matches \"{$city}\". Check the city in the order's shipping address.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cities(CourierAccount $account): array
    {
        return Cache::remember("couriers.leopards.{$account->id}.cities", now()->addDay(), fn () => $this->call($account, 'getAllCities', [])['city_list'] ?? []);
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
