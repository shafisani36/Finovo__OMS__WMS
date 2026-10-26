<?php

namespace App\Jobs;

use App\Models\StoreWebhookEvent;
use App\Services\StoreWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings the record a store notified Finovo about into Finovo. Dispatched to run right after the store has
 * received its answer, so the store never waits for Finovo's work.
 */
class ProcessStoreWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $eventId
    ) {}

    public function handle(StoreWebhookService $webhooks): void
    {
        $event = StoreWebhookEvent::with('store')->find($this->eventId);

        if ($event && $event->status !== StoreWebhookEvent::STATUS_PROCESSED) {
            $webhooks->process($event);
        }
    }
}
