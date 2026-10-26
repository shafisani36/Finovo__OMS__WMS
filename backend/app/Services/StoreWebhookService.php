<?php

namespace App\Services;

use App\Enums\StorePlatform;
use App\Integrations\ConnectorErrors;
use App\Integrations\Push\StoreWriterFactory;
use App\Integrations\Sync\Contracts\FetchesSingleRecords;
use App\Integrations\Sync\StoreDataSourceFactory;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Automatic import: stores notify Finovo the moment an order or product is created, changed or deleted
 * (webhooks), and Finovo reads that one record and saves it, without anyone pressing Sync.
 *
 * Every notification is checked against the store's signature, recorded once per delivery (a repeated
 * delivery is not processed twice) and processed after the store has had its answer. The scheduled sync
 * stays as a safety net for notifications a store never sent.
 */
class StoreWebhookService
{
    /** What Finovo does for each platform topic. */
    private const TOPICS = [
        'orders/create' => 'order.saved',
        'orders/updated' => 'order.saved',
        'orders/cancelled' => 'order.saved',
        'products/create' => 'product.saved',
        'products/update' => 'product.saved',
        'products/delete' => 'product.deleted',
        'app/uninstalled' => 'app.uninstalled',
        'order.created' => 'order.saved',
        'order.updated' => 'order.saved',
        'order.restored' => 'order.saved',
        'order.deleted' => 'order.deleted',
        'product.created' => 'product.saved',
        'product.updated' => 'product.saved',
        'product.restored' => 'product.saved',
        'product.deleted' => 'product.deleted',
    ];

    public function __construct(
        protected StoreWriterFactory $writers,
        protected StoreDataSourceFactory $sources,
        protected StoreSyncService $sync,
        protected OrderService $orders,
        protected ConnectorErrors $errors,
    ) {}

    /**
     * Whether Finovo can switch on automatic import for this platform.
     */
    public function supports(StorePlatform $platform): bool
    {
        return $this->writers->supports($platform);
    }

    /**
     * The address the store sends notifications to, or null while Finovo runs on an address stores cannot
     * reach (a local or plain-HTTP address).
     */
    public function callbackUrl(Store $store): ?string
    {
        $base = rtrim((string) config('services.webhooks.base_url'), '/');
        $host = parse_url($base, PHP_URL_HOST);

        if (! str_starts_with($base, 'https://') || ! $host || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return $base.route('webhooks.store', ['platform' => $store->platform->value, 'store' => $store->id], false);
    }

    /**
     * Subscribe Finovo to the store's notifications and remember the outcome on the store.
     *
     * @return array{status: string, message: string}
     */
    public function register(Store $store): array
    {
        $writer = $this->writers->for($store);
        $url = $this->callbackUrl($store);

        $outcome = match (true) {
            ! $writer => ['status' => 'unsupported', 'message' => "Automatic import is not available for {$store->platform->label()} stores yet; the scheduled sync imports them."],
            ! $url => ['status' => 'needs_public_url', 'message' => 'Automatic import needs Finovo on a public HTTPS address (set WEBHOOK_BASE_URL). Until then the scheduled sync imports new orders.'],
            default => null,
        };

        if (! $outcome) {
            try {
                $writer->registerWebhooks($store, $url);
                $outcome = ['status' => 'active', 'message' => 'New and changed orders and products are imported automatically.'];
            } catch (Throwable $e) {
                $outcome = ['status' => 'failed', 'message' => $this->errors->record($store, 'webhooks', $e)];
            }
        }

        $store->forceFill(['settings' => [...($store->settings ?? []), 'webhooks' => [...$outcome, 'url' => $url, 'checked_at' => now()->toIso8601String()]]])->save();

        return $outcome;
    }

    /**
     * Remove Finovo's subscriptions from the store (when it is removed from Finovo). Never fails the caller.
     */
    public function unregister(Store $store): void
    {
        $writer = $this->writers->for($store);
        $url = $this->callbackUrl($store);

        if ($writer && $url) {
            rescue(fn () => $writer->removeWebhooks($store, $url), report: false);
        }
    }

    /**
     * Whether the notification really comes from the store: Shopify signs with the app secret, WooCommerce
     * with the secret Finovo gave the store when it subscribed.
     */
    public function hasValidSignature(Request $request, Store $store): bool
    {
        [$header, $secret] = match ($store->platform) {
            StorePlatform::SHOPIFY => ['X-Shopify-Hmac-Sha256', (string) config('services.shopify.client_secret')],
            StorePlatform::WOOCOMMERCE => ['X-WC-Webhook-Signature', (string) $store->webhook_secret],
            default => [null, ''],
        };

        $signature = $header ? (string) $request->header($header) : '';

        if ($signature === '' || $secret === '') {
            return false;
        }

        if ($store->platform === StorePlatform::SHOPIFY && strcasecmp((string) $request->header('X-Shopify-Shop-Domain'), (string) $store->external_reference) !== 0) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $signature);
    }

    /**
     * Shopify's own privacy notifications (customer data request / erasure, shop erasure), signed with the
     * app secret. Finovo keeps no data the merchant has not seen, so they are acknowledged.
     */
    public function hasValidAppSignature(Request $request): bool
    {
        $secret = (string) config('services.shopify.client_secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256');

        return $secret !== '' && $signature !== '' && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $signature);
    }

    /**
     * Record a verified notification once. Returns null for one that needs no work (a test ping, a topic
     * Finovo does not use, or a delivery already received).
     */
    public function record(Request $request, Store $store): ?StoreWebhookEvent
    {
        $topic = (string) ($request->header('X-Shopify-Topic') ?? $request->header('X-WC-Webhook-Topic'));
        $deliveryId = (string) ($request->header('X-Shopify-Webhook-Id')
            ?? trim($request->header('X-WC-Webhook-ID').'-'.$request->header('X-WC-Webhook-Delivery-ID'), '-'))
            ?: hash('sha256', $topic.$request->getContent());

        if (! isset(self::TOPICS[$topic])) {
            return null;
        }

        try {
            return StoreWebhookEvent::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'topic' => $topic,
                'delivery_id' => substr($deliveryId, 0, 150),
                'resource_id' => (string) ($request->json('id') ?? $request->json('admin_graphql_api_id')) ?: null,
                'status' => StoreWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Bring the notified record into Finovo. Failures are kept on the event; the scheduled sync catches up.
     */
    public function process(StoreWebhookEvent $event): void
    {
        $store = $event->store;
        $event->increment('attempts');

        try {
            $handled = $this->handle($store, self::TOPICS[$event->topic] ?? null, (string) $event->resource_id);
            $event->update([
                'status' => $handled ? StoreWebhookEvent::STATUS_PROCESSED : StoreWebhookEvent::STATUS_IGNORED,
                'error' => null,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $event->update(['status' => StoreWebhookEvent::STATUS_FAILED, 'error' => $this->errors->record($store, 'webhook', $e)]);
        }
    }

    /**
     * @return bool false when there was nothing to do
     */
    private function handle(Store $store, ?string $action, string $resourceId): bool
    {
        if ($action === 'app.uninstalled') {
            $store->update([
                'status' => Store::STATUS_DISCONNECTED,
                'connection_error' => 'The Finovo app was removed from the shop. Connect the store again to resume importing.',
            ]);

            return true;
        }

        $source = $this->sources->supports($store->platform) ? $this->sources->for($store->platform) : null;

        if (! $store->isConnected() || ! $source instanceof FetchesSingleRecords || $resourceId === '' || $action === null) {
            return false;
        }

        switch ($action) {
            case 'product.saved':
                $product = $source->product($store, $resourceId);
                $product ? $this->sync->importProduct($store, $product) : $this->sync->removeProduct($store, $resourceId);

                return true;

            case 'product.deleted':
                $this->sync->removeProduct($store, $resourceId);

                return true;

            case 'order.saved':
                $order = $source->order($store, $resourceId);
                if ($order) {
                    $this->sync->importOrder($store, $order);
                }

                return $order !== null;

            case 'order.deleted':
                $order = Order::where('store_id', $store->id)->where('external_order_id', $resourceId)->first();
                if ($order && $order->order_status !== 'cancelled' && ! in_array($order->order_status, Order::SHIPPED_STATUSES, true)) {
                    $this->orders->cancel($order, onStore: false);
                }

                return $order !== null;
        }

        return false;
    }
}
