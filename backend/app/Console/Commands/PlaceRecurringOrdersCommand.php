<?php

namespace App\Console\Commands;

use App\Services\RecurringOrderService;
use Illuminate\Console\Command;

/**
 * Places the recurring orders that are due.
 */
class PlaceRecurringOrdersCommand extends Command
{
    protected $signature = 'orders:recurring';

    protected $description = 'Place the recurring orders that are due';

    public function handle(RecurringOrderService $plans): int
    {
        $result = $plans->runDue();
        $this->info("{$result['created']} recurring order(s) placed, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
