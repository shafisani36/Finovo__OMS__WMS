<?php

namespace App\Console\Commands;

use App\Jobs\SyncStoreJob;
use App\Models\Store;
use App\Services\StoreSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncStoresCommand extends Command
{
    protected $signature = 'stores:sync
        {store? : ID of one store; all connected stores when omitted}
        {--now : Run in this process and print the result instead of queueing background jobs}';

    protected $description = 'Import products, variants, stock and orders from connected stores';

    public function handle(StoreSyncService $sync): int
    {
        $stores = Store::query()
            ->when($this->argument('store'), fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->filter(fn (Store $store) => $sync->canSync($store));

        if ($stores->isEmpty()) {
            $this->warn('No connected store with sync support found.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($stores as $store) {
            if (! $this->option('now')) {
                SyncStoreJob::dispatch($store);
                $this->line("Queued sync for {$store->name}.");

                continue;
            }

            try {
                $result = $sync->sync($store);
                $this->info("{$store->name}: {$result->summary()}");
                array_map(fn (string $warning) => $this->warn("  {$warning}"), $result->warnings);
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$store->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
