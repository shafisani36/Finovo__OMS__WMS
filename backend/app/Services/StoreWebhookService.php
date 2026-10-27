<?php

namespace App\Services;

use App\Enums\StorePlatform;
use App\Integrations\ConnectorErrors;
use App\Integrations\Push\BigCommerceWriter;
use App\Integrations\Push\StoreWriterFactory;
use App\Integrations\Sync\Contracts\FetchesSingleRecords;
use App\Integrations\Sync\StoreDataSourceFactory;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use RuntimeException;
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
        // BigCommerce
        'store/order/created' => 'order.saved',
        'store/order/updated' => 'order.saved',
        'store/order/statusUpdated' => 'order.saved',
        'store/product/created' => 'product.saved',
        'store/product/updated' => 'product.saved',
        'store/product/deleted' => 'product.deleted',
        // Wix (sent to the Finovo app, see recordWix())
        'wix.ecom.v1.order_created' => 'order.saved',
        'wix.ecom.v1.order_updated' => 'order.saved',
        'wix.ecom.v1.order_approved' => 'order.saved',
        'wix.ecom.v1.order_canceled' => 'order.saved',
        'wix.ecom.v1.order_payment_status_updated' => 'order.saved',
        'wix.stores.catalog.v3.product_created' => 'product.saved',
        'wix.stores.catalog.v3.product_updated' => 'product.saved',
        'wix.stores.catalog.v3.product_deleted' => 'product.deleted',
    ];

    /** Header the Finovo PrestaShop and OpenCart modules sign their notifications with. */
    public const MODULE_SIGNATURE_HEADER = 'X-Finovo-Signature';

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
        return $this->writers->supportsWebhooks($platform);
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
        $writer = $this->writers->webhooks($store);
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
        $writer = $this->writers->webhooks($store);
        $url = $this->callbackUrl($store);

        if ($writer && $url) {
            rescue(fn () => $writer->removeWebhooks($store, $url), report: false);
        }
    }

    /**
     * Whether the notification really comes from the store: Shopify signs with the app secret, WooCommerce with the
     * secret Finovo gave the store when it subscribed, the Finovo PrestaShop and OpenCart modules with their key or
     * secret; BigCommerce sends back the secret header set on its subscription.
     */
    public function hasValidSignature(Request $request, Store $store): bool
    {
        $signed = function (string $secret, ?string $signature) use ($request): bool {
            return $secret !== '' && filled($signature) && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), (string) $signature);
        };

        return match ($store->platform) {
            StorePlatform::SHOPIFY => strcasecmp((string) $request->header('X-Shopify-Shop-Domain'), (string) $store->external_reference) === 0
                && $signed((string) config('services.shopify.client_secret'), $request->header('X-Shopify-Hmac-Sha256')),
            StorePlatform::WOOCOMMERCE => $signed((string) $store->webhook_secret, $request->header('X-WC-Webhook-Signature')),
            StorePlatform::BIGCOMMERCE => filled($store->webhook_secret) && hash_equals((string) $store->webhook_secret, (string) $request->header(BigCommerceWriter::SECRET_HEADER)),
            StorePlatform::PRESTASHOP => $signed((string) $store->credential('webservice_key'), $request->header(self::MODULE_SIGNATURE_HEADER)),
            StorePlatform::OPENCART => $signed((string) $store->credential('api_secret'), $request->header(self::MODULE_SIGNATURE_HEADER)),
            default => false,
        };
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
        [$topic, $deliveryId, $resourceId] = match ($store->platform) {
            StorePlatform::BIGCOMMERCE => [(string) $request->json('scope'), (string) $request->json('hash'), $request->json('data.id')],
            StorePlatform::PRESTASHOP, StorePlatform::OPENCART => [(string) $request->header('X-Finovo-Topic'), (string) $request->header('X-Finovo-Delivery'), $request->json('id')],
            default => [
                (string) ($request->header('X-Shopify-Topic') ?? $request->header('X-WC-Webhook-Topic')),
                (string) ($request->header('X-Shopify-Webhook-Id') ?? trim($request->header('X-WC-Webhook-ID').'-'.$request->header('X-WC-Webhook-Delivery-ID'), '-')),
                $request->json('id') ?? $request->json('admin_graphql_api_id'),
            ],
        };

        return $this->saveEvent($store, $topic, $deliveryId ?: hash('sha256', $topic.$request->getContent()), $resourceId);
    }

    /**
     * A notification Wix sends to the Finovo app (one address for every site): a JWT signed with the app's key,
     * carrying the site's app instance and the changed record. Returns null when it needs no work.
     *
     * @throws RuntimeException when the signature is not Wix's
     */
    public function recordWix(string $token): ?StoreWebhookEvent
    {
        $payload = $this->verifiedJwt($token, (string) config('services.wix.webhook_public_key'));
        $event = json_decode((string) ($payload['data'] ?? ''), true) ?: [];
        $data = is_string($event['data'] ?? null) ? (json_decode($event['data'], true) ?: []) : (array) ($event['data'] ?? []);
        $instanceId = (string) ($event['instanceId'] ?? '');

        $store = Store::where('platform', StorePlatform::WIX)->get()
            ->first(fn (Store $store) => $instanceId !== '' && $store->credential('instance_id') === $instanceId);

        if (! $store) {
            return null;
        }

        $topic = (string) ($event['eventType'] ?? '');

        return $this->saveEvent($store, $topic, (string) ($event['id'] ?? hash('sha256', $token)), $data['entityId'] ?? null);
    }

    private function saveEvent(Store $store, string $topic, string $deliveryId, mixed $resourceId): ?StoreWebhookEvent
    {
        if (! isset(self::TOPICS[$topic])) {
            return null;
        }

        try {
            return StoreWebhookEvent::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'topic' => $topic,
                'delivery_id' => substr($deliveryId, 0, 150),
                'resource_id' => filled($resourceId) ? (string) $resourceId : null,
                'status' => StoreWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * The claims of an RS256 JSON web token after checking its signature with the public key.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function verifiedJwt(string $token, string $publicKey): array
    {
        $parts = explode('.', trim($token));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
        $key = openssl_pkey_get_public(str_contains($publicKey, '-----BEGIN') ? str_replace('\\n', "\n", $publicKey) : "-----BEGIN PUBLIC KEY-----\n".chunk_split($publicKey, 64, "\n")."-----END PUBLIC KEY-----\n");

        if (count($parts) !== 3 || ! $key || (json_decode((string) $decode($parts[0]), true)['alg'] ?? null) !== 'RS256'
            || openssl_verify($parts[0].'.'.$parts[1], (string) $decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('The notification is not signed by Wix.');
        }

        return json_decode((string) $decode($parts[1]), true) ?: [];
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
