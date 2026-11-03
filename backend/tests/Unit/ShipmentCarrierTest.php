<?php

namespace Tests\Unit;

use App\Models\Shipment;
use PHPUnit\Framework\TestCase;

/**
 * One carrier list: typed spellings become the display name, and known carriers get their tracking page.
 */
class ShipmentCarrierTest extends TestCase
{
    public function test_typed_carrier_names_are_normalised(): void
    {
        $this->assertSame('TCS', Shipment::carrierName(' tcs '));
        $this->assertSame('Leopards Courier', Shipment::carrierName('leopards'));
        $this->assertSame('Trax (Sonic)', Shipment::carrierName('Sonic'));
        $this->assertSame('Local Rider', Shipment::carrierName(' Local Rider '));
    }

    public function test_known_carriers_get_a_tracking_link(): void
    {
        $shipment = new Shipment(['carrier' => 'dhl', 'tracking_number' => 'JD0001']);

        $this->assertStringContainsString('JD0001', (string) $shipment->trackingLink());
        $this->assertNull((new Shipment(['carrier' => 'Local Rider', 'tracking_number' => 'X1']))->trackingLink());
    }
}
