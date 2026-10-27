<?php

namespace App\Jobs;

use App\Services\StoreSyncOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the updates Finovo owes connected stores (stock and shipped orders) that are due.
 */
class RunStoreSyncTasksJob implements ShouldQueue
{
    use Queueable;

    public function handle(StoreSyncOutbox $outbox): void
    {
        $outbox->runDue();
    }
}
