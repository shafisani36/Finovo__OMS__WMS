<?php

namespace App\Console\Commands;

use App\Services\CustomerService;
use Illuminate\Console\Command;

class LinkCustomersCommand extends Command
{
    protected $signature = 'customers:link';

    protected $description = 'Build customers from orders that are not linked to a customer yet (run once after upgrading)';

    public function handle(CustomerService $customers): int
    {
        $linked = $customers->linkUnassignedOrders();
        $this->info("Linked {$linked} orders to customers.");

        return self::SUCCESS;
    }
}
