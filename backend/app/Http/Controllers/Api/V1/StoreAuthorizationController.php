<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StorePlatform;
use App\Http\Controllers\Controller;
use App\Integrations\Authorization\AuthorizationState;
use App\Integrations\Authorization\BigCommerceAuthorization;
use App\Integrations\Authorization\EbayAuthorization;
use App\Integrations\Authorization\ModulePackage;
use App\Integrations\Authorization\ShopifyAuthorization;
use App\Integrations\Authorization\WixAuthorization;
use App\Integrations\ConnectorErrors;
use App\Models\Store;
use App\Services\StoreConnectionService;
use App\Services\StoreWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Public endpoints the platforms call after a merchant approves Finovo's access.
 * None of these use a Finovo login; each request is verified with a signed state or the platform's own signature.
 */
class StoreAuthorizationController extends Controller
{
    public function __construct(
        protected StoreConnectionService $connections,
        protected AuthorizationState $state,
        protected ConnectorErrors $errors,
        protected StoreWebhookService $webhooks,
    ) {}

    public function shopifyCallback(Request $request, ShopifyAuthorization $shopify): RedirectResponse
    {
        $store = null;

        try {
            // The store first, so even a failed check sends the merchant back to their own portal.
            $store = $this->state->resolve($request->query('state'), StorePlatform::SHOPIFY);

            if (! $shopify->hasValidSignature($request->query())) {
                throw new RuntimeException('Shopify signature check failed.');
            }
            $shop = ShopifyAuthorization::normalizeShopDomain((string) $request->query('shop'));

            if ($shop === null || $shop !== $store->external_reference) {
                throw new RuntimeException('The Shopify store does not match the one you started connecting.');
            }

            $token = $shopify->exchangeCode($shop, (string) $request->query('code'));
            $result = $this->connections->completeAuthorization($store, ['access_token' => $token]);
        } catch (Throwable $e) {
            return $this->backToStores(StorePlatform::SHOPIFY, $this->failure(StorePlatform::SHOPIFY, $e, $store), $store);
        }

        return $this->backToStores(StorePlatform::SHOPIFY, $result->successful ? null : $result->message, $store);
    }

    /**
     * WooCommerce posts the generated keys server-to-server; the merchant's browser goes to return_url separately.
     */
    public function wooCommerceCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
        ]);

        try {
            $store = $this->state->resolve($request->query('state'), StorePlatform::WOOCOMMERCE);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->failure(StorePlatform::WOOCOMMERCE, $e)], 403);
        }

        $result = $this->connections->completeAuthorization($store, [
            'consumer_key' => $validated['consumer_key'],
            'consumer_secret' => $validated['consumer_secret'],
        ]);

        return response()->json(['status' => $result->successful ? 'success' : 'error', 'message' => $result->message]);
    }

    /**
     * One-click install: remember which Finovo store asked, then send the merchant to BigCommerce to approve the app.
     * BigCommerce's install link carries no state of its own, so the signed state travels in a short-lived cookie.
     */
    public function bigCommerceInstall(Request $request, BigCommerceAuthorization $bigCommerce): RedirectResponse
    {
        try {
            $this->state->resolve($request->query('state'), StorePlatform::BIGCOMMERCE);
        } catch (Throwable $e) {
            return $this->backToStores(StorePlatform::BIGCOMMERCE, $this->failure(StorePlatform::BIGCOMMERCE, $e));
        }

        $secure = $request->isSecure();

        return redirect()->away($bigCommerce->installUrl())->withCookie(new Cookie(
            BigCommerceAuthorization::INSTALL_COOKIE,
            (string) $request->query('state'),
            now()->addMinutes(30),
            '/api/v1/integrations/bigcommerce',
            null,
            $secure,
            true,
            false,
            // The callback opens inside BigCommerce's install window (a cross-site frame), which only receives SameSite=None cookies over HTTPS.
            $secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * BigCommerce app installed. The store is found from the one-click install cookie, or by store hash when the
     * merchant installed from the BigCommerce control panel. The page renders inside BigCommerce's window.
     */
    public function bigCommerceCallback(Request $request, BigCommerceAuthorization $bigCommerce): Response
    {
        $externalInstall = $request->has('external_install');
        $store = null;

        try {
            $grant = $bigCommerce->exchangeCode(
                (string) $request->query('code'),
                (string) $request->query('scope'),
                (string) $request->query('context'),
            );

            $store = $this->bigCommerceStoreFor($request, $grant['store_hash']);
            $result = $this->connections->completeAuthorization($store, ['access_token' => $grant['access_token']], $grant['store_hash']);

            if (! $result->successful) {
                throw new RuntimeException($result->message);
            }
        } catch (Throwable $e) {
            if ($externalInstall && $bigCommerce->supportsOneClickInstall()) {
                rescue(fn () => $bigCommerce->reportExternalInstall(false), report: true);
            }

            return $this->connectionPage(StorePlatform::BIGCOMMERCE, $this->failure(StorePlatform::BIGCOMMERCE, $e, $store), store: $store)
                ->withoutCookie(BigCommerceAuthorization::INSTALL_COOKIE, '/api/v1/integrations/bigcommerce');
        }

        if ($externalInstall) {
            rescue(fn () => $bigCommerce->reportExternalInstall(true), report: true);
        }

        return $this->connectionPage(StorePlatform::BIGCOMMERCE, null, $store->name, $store)
            ->withoutCookie(BigCommerceAuthorization::INSTALL_COOKIE, '/api/v1/integrations/bigcommerce');
    }

    /**
     * @throws RuntimeException when no Finovo store is waiting for this BigCommerce store
     */
    private function bigCommerceStoreFor(Request $request, string $storeHash): Store
    {
        $store = $request->cookie(BigCommerceAuthorization::INSTALL_COOKIE)
            ? $this->state->resolve($request->cookie(BigCommerceAuthorization::INSTALL_COOKIE), StorePlatform::BIGCOMMERCE)
            : Store::where('platform', StorePlatform::BIGCOMMERCE)->where('external_reference', $storeHash)->first();

        if (! $store) {
            throw new RuntimeException("No Finovo store is waiting for BigCommerce store \"{$storeHash}\". Add the store in Finovo and click Connect, then approve the app.");
        }

        $this->ensureNotLinkedToAnotherStore($store, $storeHash);

        return $store;
    }

    /**
     * Merchant opened the app from the BigCommerce control panel.
     */
    public function bigCommerceLoad(Request $request, BigCommerceAuthorization $bigCommerce): RedirectResponse
    {
        abort_if($bigCommerce->storeHashFromSignedPayload((string) $request->query('signed_payload_jwt')) === null, 403);

        return redirect()->away(config('app.frontend_url').'/stores');
    }

    /**
     * Merchant uninstalled the app: forget the token and mark the store disconnected.
     */
    public function bigCommerceUninstall(Request $request, BigCommerceAuthorization $bigCommerce): JsonResponse
    {
        $storeHash = $bigCommerce->storeHashFromSignedPayload((string) $request->query('signed_payload_jwt'));
        abort_if($storeHash === null, 403);

        Store::where('platform', StorePlatform::BIGCOMMERCE)
            ->where('external_reference', $storeHash)
            ->each(fn (Store $store) => $store->update([
                'status' => Store::STATUS_DISCONNECTED,
                'credentials' => null,
                'connection_error' => 'The Finovo app was uninstalled from BigCommerce.',
            ]));

        return response()->json(['status' => 'success']);
    }

    /**
     * Wix sends the browser back after the merchant installed the Finovo app on a site.
     * The signed instance proves the install; the site ID is read from Wix, not from the URL.
     */
    public function wixCallback(Request $request, WixAuthorization $wix): RedirectResponse
    {
        $store = null;

        try {
            $store = $this->state->resolve($request->query('state'), StorePlatform::WIX);
            $instanceId = $wix->instanceIdFromSignedInstance((string) $request->query('signedInstance'));

            if ($instanceId === null) {
                throw new RuntimeException('The Finovo app was not installed on Wix, or Wix could not confirm the installation.');
            }

            $siteId = $wix->siteId($instanceId);
            $this->ensureNotLinkedToAnotherStore($store, $siteId);

            $result = $this->connections->completeAuthorization($store, ['instance_id' => $instanceId], $siteId);
        } catch (Throwable $e) {
            return $this->backToStores(StorePlatform::WIX, $this->failure(StorePlatform::WIX, $e, $store), $store);
        }

        return $this->backToStores(StorePlatform::WIX, $result->successful ? null : $result->message, $store);
    }

    /**
     * The merchant's browser downloads the Finovo module built for this store (link signed by Finovo).
     */
    public function moduleDownload(Store $store, ModulePackage $modules): BinaryFileResponse
    {
        abort_unless(ModulePackage::supports($store->platform), 404);

        return response()
            ->download($modules->build($store), $modules->filename($store), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * The installed Finovo module posts its new webservice key server-to-server.
     * The shop address comes from PrestaShop itself, so the merchant never types it.
     */
    public function prestaShopCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string'],
            'webservice_key' => ['required', 'string', 'max:64'],
            'shop_url' => ['required', 'url', 'max:255'],
            'notifications' => ['sometimes', 'boolean'],
        ]);

        return $this->completeModuleInstall(StorePlatform::PRESTASHOP, $validated, ['webservice_key' => $validated['webservice_key']]);
    }

    /**
     * The installed Finovo OpenCart module posts the API key and secret it created for Finovo.
     */
    public function openCartCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', 'string'],
            'api_key' => ['required', 'string', 'max:100'],
            'api_secret' => ['required', 'string', 'min:32', 'max:255'],
            'shop_url' => ['required', 'url', 'max:255'],
            'notifications' => ['sometimes', 'boolean'],
        ]);

        return $this->completeModuleInstall(StorePlatform::OPENCART, $validated, [
            'api_key' => $validated['api_key'],
            'api_secret' => $validated['api_secret'],
        ]);
    }

    /**
     * Save what a shop module sent and confirm Finovo can reach the shop with it.
     * The module shows the returned message to the merchant. A module that can notify Finovo of changes gets the
     * address to send them to (none while Finovo is not on a public HTTPS address).
     *
     * @param  array{state: string, shop_url: string, notifications?: bool}  $validated
     * @param  array<string, string>  $credentials
     */
    private function completeModuleInstall(StorePlatform $platform, array $validated, array $credentials): JsonResponse
    {
        try {
            $store = $this->state->resolve($validated['state'], $platform);
            $shopUrl = rtrim($validated['shop_url'], '/');
            $this->ensureNotLinkedToAnotherStore($store, $shopUrl);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $this->failure($platform, $e)], 403);
        }

        // Recorded first: connecting switches on automatic import, which checks the module has this address.
        $webhookUrl = ! empty($validated['notifications']) ? $this->webhooks->callbackUrl($store) : null;
        $store->forceFill(['settings' => [...($store->settings ?? []), 'module_webhook_url' => $webhookUrl]])->save();

        $result = $this->connections->completeAuthorization($store, $credentials, $shopUrl);

        return response()->json(
            ['status' => $result->successful ? 'success' : 'error', 'message' => $result->message, 'webhook_url' => $result->successful ? $webhookUrl : null],
            $result->successful ? 200 : 422,
        );
    }

    public function ebayCallback(Request $request, EbayAuthorization $ebay): RedirectResponse
    {
        $store = null;

        try {
            $store = $this->state->resolve($request->query('state'), StorePlatform::EBAY);

            if (! $request->filled('code')) {
                throw new RuntimeException('eBay access was not granted.');
            }

            $result = $this->connections->completeAuthorization($store, $ebay->exchangeCode((string) $request->query('code')));
        } catch (Throwable $e) {
            return $this->backToStores(StorePlatform::EBAY, $this->failure(StorePlatform::EBAY, $e, $store), $store);
        }

        return $this->backToStores(StorePlatform::EBAY, $result->successful ? null : $result->message, $store);
    }

    /**
     * One platform store (site, store hash...) can only be linked to one Finovo store per organization.
     *
     * @throws RuntimeException
     */
    private function ensureNotLinkedToAnotherStore(Store $store, string $externalReference): void
    {
        $linked = Store::where('organization_id', $store->organization_id)
            ->where('platform', $store->platform)
            ->where('external_reference', $externalReference)
            ->whereKeyNot($store->id)
            ->exists();

        if ($linked) {
            throw new RuntimeException("This {$store->platform->label()} store ({$externalReference}) is already connected to another Finovo store.");
        }
    }

    /**
     * Small result page for callbacks that open inside the platform's own window.
     */
    private function connectionPage(StorePlatform $platform, ?string $error, ?string $storeName = null, ?Store $store = null): Response
    {
        return response()->view('integrations.connection-result', [
            'platform' => $platform->label(),
            'error' => $error,
            'storeName' => $storeName,
            'storesUrl' => $store?->portalUrl('/stores') ?? config('app.frontend_url').'/stores',
        ], $error === null ? 200 : 422);
    }

    /**
     * The merchant goes back to the Stores page of the portal they started from, which plays the connecting
     * animation and then shows the result.
     */
    private function backToStores(StorePlatform $platform, ?string $error, ?Store $store = null): RedirectResponse
    {
        $query = array_filter([
            'connected' => $error === null ? $platform->value : null,
            'connection_error' => $error,
            'platform' => $platform->value,
            'store' => $store?->name,
        ]);

        return redirect()->away(($store?->portalUrl('/stores') ?? config('app.frontend_url').'/stores').'?'.http_build_query($query));
    }

    /**
     * Readable reason for a failed callback, kept against the store when it is known (any platform, any error).
     */
    private function failure(StorePlatform $platform, Throwable $e, ?Store $store = null): string
    {
        return $store ? $this->errors->record($store, 'authorize', $e) : $this->errors->reason($e, $platform->label());
    }
}
