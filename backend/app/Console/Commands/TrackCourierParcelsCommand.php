<?php

namespace App\Console\Commands;

use App\Services\ShipmentService;
use Illuminate\Console\Command;

/**
 * Ask every courier for the latest status of the parcels on their way: delivered parcels are marked delivered and
 * parcels the courier brings back are marked returned.
 */
class TrackCourierParcelsCommand extends Command
{
    protected $signature = 'couriers:track';

    protected $description = 'Update the courier status of shipped parcels (delivered and returned ones included)';

    public function handle(ShipmentService $shipments): int
    {
        $result = $shipments->syncCourierTracking();
        $this->info("Checked {$result['checked']} parcels: {$result['updated']} updated, {$result['delivered']} delivered, {$result['returned']} returned, {$result['failed_accounts']} account(s) failed.");

        return self::SUCCESS;
    }
}
