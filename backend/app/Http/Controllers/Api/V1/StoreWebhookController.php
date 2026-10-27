<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStoreWebhookJob;
use App\Models\Store;
use App\Services\StoreWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Notifications from connected stores (webhooks). Answers at once and does the work after the answer is sent.
 */
class StoreWebhookController extends Controller
{
    public function __construct(
        protected StoreWebhookService $webhooks
    ) {}

    public function receive(Request $request, string $platform, Store $store): JsonResponse
    {
        abort_unless($store->platform->value === $platform, 404);

        // WooCommerce checks a new subscription with an unsigned ping ("webhook_id=…"); it only needs a 200.
        if ($request->has('webhook_id') && ! $request->hasHeader('X-WC-Webhook-Topic')) {
            return response()->json(['status' => 'ok']);
        }

        if (! $this->webhooks->hasValidSignature($request, $store)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        $event = $this->webhooks->record($request, $store);

        if ($event) {
            ProcessStoreWebhookJob::dispatchAfterResponse($event->id);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Wix sends every site's notifications to the Finovo app's one address, as a JWT signed by Wix.
     */
    public function wix(Request $request): JsonResponse
    {
        try {
            $event = $this->webhooks->recordWix($request->getContent());
        } catch (RuntimeException) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        if ($event) {
            ProcessStoreWebhookJob::dispatchAfterResponse($event->id);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Shopify's mandatory privacy notifications for apps (customers/data_request, customers/redact, shop/redact).
     */
    public function shopifyCompliance(Request $request): JsonResponse
    {
        if (! $this->webhooks->hasValidAppSignature($request)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        return response()->json(['status' => 'ok']);
    }
}
