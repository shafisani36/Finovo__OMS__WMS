<?php

namespace App\Services;

use App\Integrations\ConnectorErrors;
use App\Integrations\Push\LinkedVariant;
use App\Integrations\Push\StoreWriter;
use App\Integrations\Push\StoreWriterFactory;
use App\Jobs\RunStoreSyncTasksJob;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Models\StoreSyncTask;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Updates Finovo owes the connected stores, kept as an outbox:
 * - stock: the available quantity of a variant on every store selling it, from the store's linked warehouses
 *   (once a store's products are imported, Finovo's warehouses are the source of truth for its stock);
 * - status: an order's warehouse progress (allocated, picking, picked, packed) shown on its store;
 * - return: a completed return noted on the order;
 * - fulfillment: a shipped order marked fulfilled with its tracking, and later delivered.
 *
 * Tasks are written with the stock or shipment change, so no change is lost. A worker then sends them; a failure
 * is retried with growing waits and never repeats the warehouse work. The quantity is read when the task is sent,
 * so many quick stock changes become one update with the latest number.
 */
class StoreSyncOutbox
{
    /** Minutes to wait before each retry; after the last one the task is marked failed. */
    private const RETRY_MINUTES = [1, 5, 15, 60, 180];

    /** A task left "processing" this long (the worker stopped) is picked up again. */
    private const STALE_MINUTES = 10;

    /** Shipment statuses the store is told about. */
    private const SENT_SHIPMENT_STATUSES = ['shipped', 'delivered'];

    /** One send is scheduled per request, however many changes it makes (the service is request-scoped). */
    private bool $runScheduled = false;

    /** The store whose own stock is being imported; sending that stock back to it would only echo it. */
    private ?int $importingFromStoreId = null;

    public function __construct(
        protected StoreWriterFactory $writers,
        protected ConnectorErrors $errors,
    ) {}

    /**
     * A variant's stock changed: queue it for every store that sells it and takes its stock from a Finovo warehouse.
     */
    public function stockChanged(int $variantId): void
    {
        $stores = Store::whereIn('id', SkuMapping::where('product_variant_id', $variantId)->pluck('store_id'))
            ->whereHas('warehouses')
            ->when($this->importingFromStoreId, fn ($q, int $storeId) => $q->whereKeyNot($storeId))
            ->get()
            ->filter(fn (Store $store) => $this->writers->for($store));

        foreach ($stores as $store) {
            $this->queue($store, StoreSyncTask::TYPE_STOCK, $variantId);
        }
    }

    /**
     * Run a stock change that came from the store itself (its stock imported as opening stock): the other stores
     * selling the variant are updated, the store it came from is not.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function importingFrom(Store $store, Closure $call): mixed
    {
        $this->importingFromStoreId = $store->id;

        try {
            return $call();
        } finally {
            $this->importingFromStoreId = null;
        }
    }

    /**
     * The warehouse moved an order a step on (see Order::PROCESSING_STATUSES): queue it for the order's store.
     */
    public function orderStatusChanged(Order $order): void
    {
        $store = $order->store;

        if ($store && $order->external_order_id && $this->writers->for($store)) {
            $this->queue($store, StoreSyncTask::TYPE_STATUS, $order->id);
        }
    }

    /**
     * A return was completed (inspected, and restocked or disposed of): queue it for the order's store.
     */
    public function returnCompleted(OrderReturn $return): void
    {
        $order = $return->order()->with('store')->first();
        $store = $order?->store;

        if ($store && $order->external_order_id && $this->writers->for($store)) {
            $this->queue($store, StoreSyncTask::TYPE_RETURN, $return->id);
        }
    }

    /**
     * A shipment was dispatched or delivered: queue it for the order's store.
     */
    public function shipmentChanged(Shipment $shipment): void
    {
        $order = $shipment->order()->with('store')->first();
        $store = $order?->store;

        if ($store && $order->external_order_id && $this->writers->for($store)) {
            $this->queue($store, StoreSyncTask::TYPE_FULFILLMENT, $shipment->id);
        }
    }

    /**
     * Send the order's dispatched and delivered shipments to its store now (the order's Push action).
     * Shipments the store already has are skipped; a refusal is thrown to the caller.
     *
     * @throws Throwable
     */
    public function pushShipments(Order $order, StoreWriter $writer): void
    {
        $shipments = $order->shipments()->whereIn('status', self::SENT_SHIPMENT_STATUSES)->get();

        foreach ($shipments as $shipment) {
            $task = StoreSyncTask::firstOrNew(
                ['store_id' => $order->store_id, 'type' => StoreSyncTask::TYPE_FULFILLMENT, 'subject_id' => $shipment->id],
                ['organization_id' => $order->organization_id, 'attempts' => 0],
            );

            // Sent after the shipment's latest change: the store is up to date.
            if ($task->status === StoreSyncTask::STATUS_DONE && $task->processed_at?->gte($shipment->updated_at)) {
                continue;
            }

            $this->sendFulfillment($order->store, $task, $shipment->setRelation('order', $order), $writer);
            $task->fill([
                'status' => StoreSyncTask::STATUS_DONE,
                'attempts' => $task->attempts + 1,
                'available_at' => now(),
                'last_error' => null,
                'processed_at' => now(),
            ])->save();
        }
    }

    /**
     * Send the tasks that are due.
     *
     * @return array{sent: int, failed: int}
     */
    public function runDue(int $limit = 100): array
    {
        $counts = ['sent' => 0, 'failed' => 0];

        $due = StoreSyncTask::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', StoreSyncTask::STATUS_PENDING)->where('available_at', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', StoreSyncTask::STATUS_PROCESSING)->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            // Claimed with a conditional update, so two workers never send the same task.
            $claimed = StoreSyncTask::whereKey($id)
                ->whereIn('status', [StoreSyncTask::STATUS_PENDING, StoreSyncTask::STATUS_PROCESSING])
                ->update(['status' => StoreSyncTask::STATUS_PROCESSING, 'updated_at' => now()]);

            if ($claimed) {
                $this->send(StoreSyncTask::with('store')->find($id)) ? $counts['sent']++ : $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Available units of the variant in the warehouses linked to the store.
     */
    public function publishableQuantity(Store $store, int $variantId): int
    {
        $warehouseIds = DB::table('store_warehouse_assignments')->where('store_id', $store->id)->pluck('warehouse_id');

        return max(0, (int) InventoryBalance::where('product_variant_id', $variantId)->whereIn('warehouse_id', $warehouseIds)->sum('available'));
    }

    private function queue(Store $store, string $type, int $subjectId): void
    {
        StoreSyncTask::updateOrCreate(
            ['store_id' => $store->id, 'type' => $type, 'subject_id' => $subjectId],
            ['organization_id' => $store->organization_id, 'status' => StoreSyncTask::STATUS_PENDING, 'attempts' => 0, 'available_at' => now(), 'last_error' => null],
        );

        $this->scheduleRun();
    }

    /**
     * Send queued tasks once the current request has answered (the scheduler also runs them every minute).
     */
    private function scheduleRun(): void
    {
        if (! $this->runScheduled) {
            $this->runScheduled = true;
            RunStoreSyncTasksJob::dispatchAfterResponse();
        }
    }

    private function send(StoreSyncTask $task): bool
    {
        $task->increment('attempts');

        try {
            // A store disconnected since the task was queued has nothing to receive.
            if ($writer = $this->writers->for($task->store)) {
                match ($task->type) {
                    StoreSyncTask::TYPE_STOCK => $this->sendStock($task->store, $task->subject_id, $writer),
                    StoreSyncTask::TYPE_STATUS => $this->sendStatus($task->store, $task->subject_id, $writer),
                    StoreSyncTask::TYPE_RETURN => $this->sendReturn($task->store, $task->subject_id, $writer),
                    StoreSyncTask::TYPE_FULFILLMENT => $this->sendQueuedFulfillment($task, $writer),
                };
            }

            $task->update(['status' => StoreSyncTask::STATUS_DONE, 'last_error' => null, 'processed_at' => now()]);

            return true;
        } catch (Throwable $e) {
            $message = $this->errors->record($task->store, "{$task->type} update", $e);
            $wait = self::RETRY_MINUTES[$task->attempts - 1] ?? null;

            $task->update($wait === null
                ? ['status' => StoreSyncTask::STATUS_FAILED, 'last_error' => $message]
                : ['status' => StoreSyncTask::STATUS_PENDING, 'last_error' => $message, 'available_at' => now()->addMinutes($wait)]);

            return false;
        }
    }

    private function sendStock(Store $store, int $variantId, StoreWriter $writer): void
    {
        $mapping = SkuMapping::where('store_id', $store->id)->where('product_variant_id', $variantId)->first();

        // The variant is no longer sold on this store: nothing to send.
        if (! $mapping) {
            return;
        }

        $productId = StoreProductLink::where('store_id', $store->id)
            ->where('product_id', ProductVariant::whereKey($variantId)->value('product_id'))
            ->value('external_id');

        $writer->setStock(
            $store,
            new LinkedVariant($mapping->external_id, $productId, $mapping->external_sku ?: ProductVariant::whereKey($variantId)->value('sku')),
            $this->publishableQuantity($store, $variantId),
        );
    }

    /**
     * The order's latest warehouse step; an order that has since shipped or been cancelled reports that instead.
     */
    private function sendStatus(Store $store, int $orderId, StoreWriter $writer): void
    {
        $order = Order::find($orderId);

        if ($order && in_array($order->order_status, Order::PROCESSING_STATUSES, true)) {
            $writer->updateStatus($store, $order->setRelation('store', $store));
        }
    }

    private function sendReturn(Store $store, int $returnId, StoreWriter $writer): void
    {
        $return = OrderReturn::with('order')->find($returnId);

        if ($return?->order?->external_order_id && $return->status === 'completed') {
            $writer->reportReturn($store, $return->order, $return);
        }
    }

    private function sendQueuedFulfillment(StoreSyncTask $task, StoreWriter $writer): void
    {
        $shipment = Shipment::with('order')->find($task->subject_id);

        if ($shipment?->order?->external_order_id && in_array($shipment->status, self::SENT_SHIPMENT_STATUSES, true)) {
            $this->sendFulfillment($task->store, $task, $shipment, $writer);
        }
    }

    /**
     * Mark the order fulfilled on the store once, then report delivery when the shipment reached the customer.
     */
    private function sendFulfillment(Store $store, StoreSyncTask $task, Shipment $shipment, StoreWriter $writer): void
    {
        // A task sent before means the store already has the shipment; only its delivery is new.
        if ($task->processed_at === null) {
            $fulfillmentId = $writer->fulfill($store, $shipment->order, $shipment);
            $shipment->forceFill(['store_fulfillment_id' => $fulfillmentId])->saveQuietly();
        }

        if ($shipment->status === 'delivered') {
            $writer->reportDelivered($store, $shipment->order, $shipment);
        }
    }
}
