<?php

namespace App\Console\Commands;

use App\Integrations\ConnectorErrors;
use App\Models\Store;
use App\Services\StoreSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Imports new and changed orders of every connected store, so orders arrive within a minute even on platforms
 * that cannot notify Finovo (Magento, eBay, osCommerce) or when a notification was missed.
 */
class ImportStoreOrdersCommand extends Command
{
    protected $signature = 'stores:import-orders';

    protected $description = 'Import new and changed orders of every connected store';

    public function handle(StoreSyncService $sync, ConnectorErrors $errors): int
    {
        $imported = 0;

        foreach (Store::where('status', Store::STATUS_ACTIVE)->get() as $store) {
            if (! $sync->canSync($store)) {
                continue;
            }

            try {
                $result = $sync->syncOrders($store);
                $imported += $result ? $result->ordersCreated + $result->ordersUpdated : 0;
            } catch (Throwable $e) {
                $this->warn("{$store->name}: ".$errors->record($store, 'order import', $e));
            }
        }

        $this->info("Imported {$imported} new or changed orders.");

        return self::SUCCESS;
    }
}
