<?php

namespace App\Jobs;

use App\Integrations\ConnectorErrors;
use App\Models\Store;
use App\Services\StoreSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;

/**
 * Imports one store's products and orders in the background (a large catalog can take minutes).
 * Only one sync per store runs at a time.
 */
class SyncStoreJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /** A failed sync is recorded on the store; the next scheduled run tries again. */
    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly Store $store
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->store->id;
    }

    public function handle(StoreSyncService $sync, ConnectorErrors $errors): void
    {
        if (! $sync->canSync($this->store)) {
            return;
        }

        try {
            // Skipped when a sync of this store is already running (e.g. started with the Sync button).
            $errors->run($this->store, 'sync', fn () => $sync->syncNow($this->store));
        } catch (ValidationException) {
            // Already recorded on the store, where the Stores page shows it; the next sync tries again.
        }
    }
}
