<?php

namespace App\Console\Commands;

use App\Services\StoreSyncOutbox;
use Illuminate\Console\Command;

/**
 * Sends due store updates (stock and shipped orders), including retries of earlier failures.
 */
class PushStoreUpdatesCommand extends Command
{
    protected $signature = 'stores:push-updates {--limit=200 : Most updates to send in one run}';

    protected $description = 'Send stock and shipment updates to connected stores, retrying earlier failures';

    public function handle(StoreSyncOutbox $outbox): int
    {
        $result = $outbox->runDue((int) $this->option('limit'));
        $this->info("Sent {$result['sent']} store updates; {$result['failed']} will be retried.");

        return self::SUCCESS;
    }
}
