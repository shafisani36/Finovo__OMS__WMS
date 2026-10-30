<?php

namespace App\Services;

use App\Enums\StorePlatform;
use App\Integrations\Authorization\AuthorizationState;
use App\Integrations\Authorization\BigCommerceAuthorization;
use App\Integrations\Authorization\EbayAuthorization;
use App\Integrations\Authorization\ModulePackage;
use App\Integrations\Authorization\ShopifyAuthorization;
use App\Integrations\Authorization\WixAuthorization;
use App\Integrations\Authorization\WooCommerceAuthorization;
use App\Integrations\ConnectionResult;
use App\Integrations\ConnectorErrors;
use App\Integrations\StoreConnectorFactory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates store connections, links them to their warehouses and keeps their connection status current.
 */
class StoreConnectionService
{
    public function __construct(
        protected StoreConnectorFactory $connectors,
        protected AuthorizationState $state,
        protected ShopifyAuthorization $shopify,
        protected WooCommerceAuthorization $wooCommerce,
        protected BigCommerceAuthorization $bigCommerce,
        protected WixAuthorization $wix,
        protected EbayAuthorization $ebay,
        protected ModulePackage $modules,
        protected AuditService $auditService,
        protected StoreWarehouseService $links,
        protected ConnectorErrors $errors,
        protected StoreWebhookService $webhooks,
    ) {}

    /**
     * Create a store for the user's organization and link it to the warehouses that fulfil it.
     *
     * Platforms with keys entered in Finovo are tested first and only saved when the test passes.
     * Platforms that authorize on their own site are saved as "setup_incomplete" and return a next step.
     *
     * Adding a store that is already in Finovo but not connected yet (e.g. awaiting authorization) continues with
     * that store instead of creating a duplicate; a store that is already connected is refused.
     *
     * @param  array{name: string, platform: string, warehouse_ids: list<int>, credentials?: array<string, mixed>}  $data  warehouse_ids in priority order
     * @param  string|null  $portalUrl  Finovo address the merchant is using, where the platform sends them back
     * @return array{store: Store, reused: bool, connection: ConnectionResult|null, next_action: array<string, string>|null, credentials: array<string, string>|null}
     */
    public function connect(array $data, User $user, ?string $portalUrl = null): array
    {
        $organization = $user->currentOrganization();
        $platform = StorePlatform::from($data['platform']);
        $input = $data['credentials'] ?? [];
        $authorizeOnPlatform = $platform->authorizesOnPlatform() && ! $this->hasDirectKeys($platform, $input);

        if ($authorizeOnPlatform) {
            $this->ensurePlatformConfigured($platform);
        }

        // Without one-click install, the store hash is the only way to match the app install to this store.
        if ($authorizeOnPlatform && $platform === StorePlatform::BIGCOMMERCE && ! $this->bigCommerce->supportsOneClickInstall() && blank($input['store_hash'] ?? null)) {
            throw ValidationException::withMessages([
                'store_hash' => 'Enter the store hash from your BigCommerce admin address (store-HASH.mybigcommerce.com), or paste a store-level API token.',
            ]);
        }

        // Without the Finovo Wix app on this server, a Wix store is connected by site ID and API key.
        if ($authorizeOnPlatform && $platform === StorePlatform::WIX && ! $this->wix->supportsOneClickInstall()) {
            throw ValidationException::withMessages([
                'site_id' => 'Enter the Wix site ID from your dashboard address (dashboard/SITE-ID/...).',
            ]);
        }

        // Wix only returns to an HTTPS address after the app is installed, so a local (http) Finovo must use the site ID.
        if ($authorizeOnPlatform && $platform === StorePlatform::WIX && ! str_starts_with(route('integrations.wix.callback'), 'https://')) {
            throw ValidationException::withMessages([
                'site_id' => 'Wix can only return to Finovo over HTTPS. Enter the Wix site ID from your dashboard address (dashboard/SITE-ID/...) instead.',
            ]);
        }

        // WooCommerce only posts keys to an HTTPS callback, so a local (http) Finovo must use REST API keys.
        if ($authorizeOnPlatform && $platform === StorePlatform::WOOCOMMERCE && ! str_starts_with(route('integrations.woocommerce.callback'), 'https://')) {
            throw ValidationException::withMessages([
                'consumer_key' => 'WooCommerce can only send keys to Finovo over HTTPS. Create keys in WooCommerce → Settings → Advanced → REST API (Read/Write) and paste them here.',
            ]);
        }

        $warehouseIds = $this->links->warehousesOf($organization->id, $data['warehouse_ids'], 'warehouse_ids')->pluck('id')->all();

        $externalReference = $this->externalReference($platform, $input);
        $existing = $this->existingStore($organization->id, $platform, $externalReference, $data['name']);

        if ($existing?->isConnected()) {
            throw ValidationException::withMessages(['credentials' => "This {$platform->label()} store is already connected as \"{$existing->name}\"."]);
        }

        $store = $existing ?? new Store([
            'organization_id' => $organization->id,
            'code' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'platform' => $platform,
            'orders_awaiting_sync' => 0,
        ]);
        $store->fill([
            'name' => $data['name'],
            'sync_status' => 'healthy',
            'connection_error' => null,
            'external_reference' => $externalReference ?? $store->external_reference,
            'credentials' => $this->initialCredentials($platform, $input) ?? $store->credentials,
        ]);

        $generated = null;
        if ($platform === StorePlatform::CUSTOM_BRIDGE) {
            $store->bridge_url = rtrim($input['bridge_url'], '/');
            $store->api_key = 'finovo_'.Str::random(32);
            $store->api_secret = Str::random(64);
            $generated = ['api_key' => $store->api_key, 'api_secret' => $store->api_secret];
        }

        $connection = null;
        if ($authorizeOnPlatform) {
            $store->status = Store::STATUS_SETUP_INCOMPLETE;
        } elseif ($platform === StorePlatform::CUSTOM_BRIDGE) {
            // The website can only answer once the generated keys are installed on it.
            $store->status = Store::STATUS_SETUP_INCOMPLETE;
        } else {
            $connection = $this->errors->run($store, 'connect', fn () => $this->connectors->for($platform)->testConnection($store));

            if (! $connection->successful) {
                throw ValidationException::withMessages(['credentials' => $connection->message]);
            }

            $store->status = Store::STATUS_ACTIVE;
            $store->connected_at = now();
        }

        DB::transaction(function () use ($store, $warehouseIds, $existing) {
            $store->save();
            // A store connected again keeps the warehouses it already ships from; the chosen ones are added after them.
            $existing
                ? $this->links->addStoreWarehouses($store, $warehouseIds)
                : $this->links->setLinks($store, $warehouseIds);
        });

        if ($store->isConnected()) {
            $this->enableAutomaticImport($store);
        }

        $this->auditService->record($existing ? 'store.connection_restarted' : 'store.created', $user->id, [
            'store_id' => $store->id,
            'platform' => $platform->value,
            'warehouse_ids' => $warehouseIds,
        ]);

        return [
            'store' => $store->load('warehouses'),
            'reused' => $existing !== null,
            'connection' => $connection,
            'next_action' => $authorizeOnPlatform ? $this->authorizationAction($store, $portalUrl) : null,
            'credentials' => $generated,
        ];
    }

    /**
     * What the merchant has to do next to authorize a redirect-based platform (the platform's approval page,
     * or the app/module to install). Works the same for a new store and one already awaiting authorization.
     *
     * @param  string|null  $portalUrl  Finovo address the merchant is using, where the platform sends them back
     * @return array<string, string>
     */
    public function authorizationAction(Store $store, ?string $portalUrl = null): array
    {
        $this->ensurePlatformConfigured($store->platform);

        if ($portalUrl !== null) {
            $store->forceFill(['settings' => [...($store->settings ?? []), 'portal_url' => $portalUrl]])->save();
        }

        return match ($store->platform) {
            StorePlatform::SHOPIFY => ['type' => 'redirect', 'url' => $this->shopify->authorizationUrl($store, $this->state->issue($store))],
            StorePlatform::WOOCOMMERCE => ['type' => 'redirect', 'url' => $this->wooCommerce->authorizationUrl($store, $this->state->issue($store))],
            StorePlatform::EBAY => ['type' => 'redirect', 'url' => $this->ebay->authorizationUrl($this->state->issue($store))],
            StorePlatform::WIX => ['type' => 'redirect', 'url' => $this->wix->installUrl($this->state->issue($store))],
            StorePlatform::PRESTASHOP => [
                'type' => 'install_module',
                'url' => $this->modules->downloadUrl($store),
                'message' => 'Download the Finovo module, then in PrestaShop admin open Modules → Module Manager → Upload a module and drop the file in. The store connects as soon as the module is installed.',
            ],
            StorePlatform::OPENCART => [
                'type' => 'install_module',
                'url' => $this->modules->downloadUrl($store),
                'message' => 'Download the Finovo module, upload it in OpenCart admin → Extensions → Installer, then open Extensions → Extensions → Modules and click Install next to Finovo. The store connects as soon as the module is installed.',
            ],
            StorePlatform::BIGCOMMERCE => $this->bigCommerce->supportsOneClickInstall()
                ? ['type' => 'redirect', 'url' => route('integrations.bigcommerce.install', ['state' => $this->state->issue($store)])]
                : [
                    'type' => 'install_app',
                    'message' => 'Open your BigCommerce control panel, go to Apps → My Apps, and install the Finovo app. The store connects automatically.',
                ],
            default => throw ValidationException::withMessages(['store' => 'This platform does not use an authorization step.']),
        };
    }

    /**
     * Save credentials returned by a platform's authorization callback and verify them.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function completeAuthorization(Store $store, array $credentials, ?string $externalReference = null): ConnectionResult
    {
        $store->fill([
            'credentials' => array_merge($store->credentials ?? [], $credentials),
            'external_reference' => $externalReference ?? $store->external_reference,
        ])->save();

        $this->auditService->record('store.authorized', null, [
            'store_id' => $store->id,
            'platform' => $store->platform->value,
        ]);

        return $this->test($store);
    }

    /**
     * Run the platform connection check and record the outcome on the store.
     */
    public function test(Store $store): ConnectionResult
    {
        try {
            $result = $this->connectors->for($store->platform)->testConnection($store);
        } catch (Throwable $e) {
            $result = ConnectionResult::failure($this->errors->record($store, 'test', $e));
        }

        $store->fill($result->successful
            ? [
                'status' => Store::STATUS_ACTIVE,
                'sync_status' => 'healthy',
                'connection_error' => null,
                'connected_at' => $store->connected_at ?? now(),
            ]
            : [
                'status' => Store::STATUS_SETUP_INCOMPLETE,
                'sync_status' => 'sync_issues',
                'connection_error' => $result->message,
            ]
        )->save();

        if ($result->successful) {
            $this->enableAutomaticImport($store);
        }

        return $result;
    }

    /**
     * Subscribe to the store's order and product notifications once the merchant has had their answer,
     * so connecting never waits on the platform.
     */
    private function enableAutomaticImport(Store $store): void
    {
        if ($this->webhooks->supports($store->platform)) {
            app()->terminating(fn () => $this->webhooks->register($store->fresh()));
        }
    }

    /**
     * The value that identifies the store on its platform (shop domain, site URL, store hash...).
     *
     * @param  array<string, mixed>  $input
     */
    private function externalReference(StorePlatform $platform, array $input): ?string
    {
        return match ($platform) {
            StorePlatform::SHOPIFY => ShopifyAuthorization::normalizeShopDomain((string) $input['shop_domain'])
                ?? throw ValidationException::withMessages(['credentials.shop_domain' => 'Enter a valid Shopify store address, e.g. my-shop.myshopify.com.']),
            StorePlatform::WOOCOMMERCE, StorePlatform::MAGENTO => rtrim((string) $input['site_url'], '/'),
            StorePlatform::PRESTASHOP => filled($input['site_url'] ?? null) ? rtrim((string) $input['site_url'], '/') : null,
            StorePlatform::BIGCOMMERCE => filled($input['store_hash'] ?? null) ? (string) $input['store_hash'] : null,
            StorePlatform::WIX => filled($input['site_id'] ?? null) ? (string) $input['site_id'] : null,
            StorePlatform::OPENCART => filled($input['db_name'] ?? null) ? (string) $input['db_name'] : null,
            StorePlatform::OSCOMMERCE => (string) $input['db_name'],
            StorePlatform::CUSTOM_BRIDGE => rtrim((string) $input['bridge_url'], '/'),
            StorePlatform::EBAY, StorePlatform::MANUAL => null,
        };
    }

    /**
     * Secrets kept (encrypted) on the store. Identifiers already stored in external_reference are left out.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function initialCredentials(StorePlatform $platform, array $input): ?array
    {
        $keys = match ($platform) {
            StorePlatform::WOOCOMMERCE => ['consumer_key', 'consumer_secret'],
            StorePlatform::BIGCOMMERCE => ['access_token'],
            StorePlatform::PRESTASHOP => ['webservice_key'],
            StorePlatform::WIX => ['api_key'],
            StorePlatform::MAGENTO => ['access_token'],
            StorePlatform::OPENCART, StorePlatform::OSCOMMERCE => ['db_host', 'db_port', 'db_name', 'db_username', 'db_password', 'table_prefix', 'site_url'],
            default => [],
        };

        $credentials = array_filter(
            array_intersect_key($input, array_flip($keys)),
            fn ($value) => $value !== null && $value !== '',
        );

        return $credentials ?: null;
    }

    /**
     * WooCommerce, BigCommerce, Wix, PrestaShop and OpenCart can also be connected with keys, IDs or database details,
     * which skips the approval step (useful for local sites the platform cannot call back).
     *
     * @param  array<string, mixed>  $input
     */
    private function hasDirectKeys(StorePlatform $platform, array $input): bool
    {
        return match ($platform) {
            StorePlatform::WOOCOMMERCE => filled($input['consumer_key'] ?? null) && filled($input['consumer_secret'] ?? null),
            StorePlatform::BIGCOMMERCE => filled($input['access_token'] ?? null),
            StorePlatform::WIX => filled($input['site_id'] ?? null),
            StorePlatform::PRESTASHOP => filled($input['webservice_key'] ?? null),
            StorePlatform::OPENCART => filled($input['db_name'] ?? null),
            default => false,
        };
    }

    /**
     * The organization's store for the same platform store: matched by its address/ID, or, for platforms that
     * only learn it during authorization (eBay, one-click BigCommerce/Wix...), an unconnected store with the same name.
     */
    private function existingStore(int $organizationId, StorePlatform $platform, ?string $externalReference, string $name): ?Store
    {
        $stores = Store::where('organization_id', $organizationId)->where('platform', $platform);

        if ($externalReference !== null) {
            return (clone $stores)->where('external_reference', $externalReference)->first()
                ?? $stores->whereNull('external_reference')->where('status', '!=', Store::STATUS_ACTIVE)
                    ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->latest()->first();
        }

        return $stores->where('status', '!=', Store::STATUS_ACTIVE)->whereRaw('LOWER(name) = ?', [Str::lower($name)])->latest()->first();
    }

    private function ensurePlatformConfigured(StorePlatform $platform): void
    {
        $missing = match ($platform) {
            StorePlatform::SHOPIFY => blank(config('services.shopify.client_id')) || blank(config('services.shopify.client_secret')) ? 'add SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET to .env' : null,
            StorePlatform::BIGCOMMERCE => blank(config('services.bigcommerce.client_id')) || blank(config('services.bigcommerce.client_secret')) ? 'add BIGCOMMERCE_CLIENT_ID and BIGCOMMERCE_CLIENT_SECRET to .env' : null,
            StorePlatform::EBAY => $this->ebay->isConfigured() ? null : 'add EBAY_APP_ID, EBAY_CERT_ID and EBAY_RUNAME to .env',
            StorePlatform::PRESTASHOP, StorePlatform::OPENCART => $this->modules->canBuild() ? null : 'enable the PHP zip extension',
            default => null,
        };

        if ($missing) {
            throw ValidationException::withMessages(['platform' => "{$platform->label()} is not set up on this server: {$missing}."]);
        }
    }
}
