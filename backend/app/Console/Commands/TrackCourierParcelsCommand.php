<?php

namespace App\Console\Commands;

use App\Services\CourierService;
use Illuminate\Console\Command;

/**
 * Ask every courier for the latest status of the parcels on their way; delivered parcels are marked delivered.
 */
class TrackCourierParcelsCommand extends Command
{
    protected $signature = 'couriers:track';

    protected $description = 'Update the courier status of shipped parcels and mark delivered ones';

    public function handle(CourierService $couriers): int
    {
        $result = $couriers->syncTracking();
        $this->info("Checked {$result['checked']} parcels: {$result['updated']} updated, {$result['delivered']} delivered, {$result['failed_accounts']} account(s) failed.");

        return self::SUCCESS;
    }
}
