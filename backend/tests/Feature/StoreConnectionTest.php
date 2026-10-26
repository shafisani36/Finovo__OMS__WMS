<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Integrations\Authorization\AuthorizationState;
use App\Integrations\Authorization\BigCommerceAuthorization;
use App\Integrations\Contracts\StoreConnector;
use App\Integrations\StoreConnectorFactory;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StoreConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'http://localhost:3000',
            'services.shopify.client_id' => 'shopify-client',
            'services.shopify.client_secret' => 'shopify-secret',
            'services.bigcommerce.client_id' => 'bc-client',
            'services.bigcommerce.client_secret' => 'bc-secret',
            'services.bigcommerce.app_id' => null,
            'services.wix.app_id' => null,
            'services.wix.app_secret' => null,
            'services.wix.share_url_id' => null,
            'services.ebay.app_id' => 'ebay-app',
            'services.ebay.cert_id' => 'ebay-cert',
            'services.ebay.ru_name' => 'ebay-runame',
        ]);

        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
    }

    private function connect(array $payload)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/stores', $payload + ['warehouse_id' => $this->warehouse->id]);
    }

    public function test_manual_store_is_active_and_linked_to_the_selected_warehouse(): void
    {
        $response = $this->connect(['name' => 'Walk-in Counter', 'platform' => 'manual']);

        $response->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_ACTIVE)
            ->assertJsonPath('data.warehouses.0.id', $this->warehouse->id)
            ->assertJsonPath('next_action', null);

        $this->assertDatabaseHas('store_warehouse_assignments', [
            'store_id' => $response->json('data.id'),
            'warehouse_id' => $this->warehouse->id,
            'is_default' => true,
        ]);
    }

    public function test_warehouse_must_belong_to_the_same_organization(): void
    {
        $otherOrgWarehouse = Warehouse::create([
            'organization_id' => Organization::create(['name' => 'Other Co', 'slug' => 'other-co'])->id,
            'name' => 'Foreign DC',
            'code' => 'FOR-01',
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/stores', [
            'name' => 'Sneaky Store',
            'platform' => 'manual',
            'warehouse_id' => $otherOrgWarehouse->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        $this->assertDatabaseCount('stores', 0);
    }

    public function test_operators_cannot_connect_stores(): void
    {
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR]);

        $this->actingAs($operator)->postJson('/api/v1/stores', [
            'name' => 'Store', 'platform' => 'manual', 'warehouse_id' => $this->warehouse->id,
        ])->assertForbidden();
    }

    public function test_platform_specific_fields_are_required(): void
    {
        $this->connect(['name' => 'Magento Shop', 'platform' => 'magento', 'credentials' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credentials.site_url', 'credentials.access_token']);
    }

    public function test_key_based_store_is_tested_and_credentials_are_stored_encrypted(): void
    {
        Http::fake(['https://magento.test/rest/V1/products*' => Http::response(['items' => [], 'total_count' => 0])]);

        $response = $this->connect([
            'name' => 'Magento Shop',
            'platform' => 'magento',
            'credentials' => ['site_url' => 'https://magento.test/', 'access_token' => 'mag-token-123'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_ACTIVE)
            ->assertJsonPath('data.external_reference', 'https://magento.test')
            ->assertJsonMissingPath('data.credentials');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer mag-token-123'));

        $raw = DB::table('stores')->value('credentials');
        $this->assertStringNotContainsString('mag-token-123', $raw);
        $this->assertSame('mag-token-123', Store::first()->credential('access_token'));
    }

    public function test_store_is_not_saved_when_the_platform_rejects_the_credentials(): void
    {
        Http::fake(['https://shop.test/api/products*' => Http::response(['errors' => []], 401)]);

        $this->connect([
            'name' => 'Presta Shop',
            'platform' => 'prestashop',
            'credentials' => ['site_url' => 'https://shop.test', 'webservice_key' => 'WRONGKEY'],
        ])->assertUnprocessable()->assertJsonValidationErrors('credentials');

        $this->assertDatabaseCount('stores', 0);
    }

    public function test_same_platform_store_cannot_be_connected_twice(): void
    {
        Http::fake(['https://www.wixapis.com/*' => Http::response(['products' => []])]);
        $payload = [
            'name' => 'Wix Site',
            'platform' => 'wix',
            'credentials' => ['site_id' => '1a2b3c4d-1234-4567-89ab-1a2b3c4d5e6f', 'api_key' => 'IST.key'],
        ];

        $this->connect($payload)->assertCreated();
        $this->connect($payload)->assertUnprocessable()->assertJsonValidationErrors('credentials');
    }

    public function test_shopify_authorization_round_trip_connects_the_store(): void
    {
        $response = $this->connect([
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'credentials' => ['shop_domain' => 'https://Finovo-Demo.myshopify.com/'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_SETUP_INCOMPLETE)
            ->assertJsonPath('data.external_reference', 'finovo-demo.myshopify.com')
            ->assertJsonPath('next_action.type', 'redirect');

        $this->assertStringStartsWith('https://finovo-demo.myshopify.com/admin/oauth/authorize?', $response->json('next_action.url'));
        parse_str(parse_url($response->json('next_action.url'), PHP_URL_QUERY), $authorize);

        Http::fake([
            'https://finovo-demo.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_live']),
            'https://finovo-demo.myshopify.com/admin/api/*/shop.json' => Http::response(['shop' => ['name' => 'Finovo Demo']]),
        ]);

        // Shopify signs the decoded values (the encrypted state contains "=" and "/"), joined as key=value&...
        $query = ['code' => 'auth-code', 'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvZmlub3Zv', 'shop' => 'finovo-demo.myshopify.com', 'state' => $authorize['state'], 'timestamp' => (string) time()];
        ksort($query);
        $query['hmac'] = hash_hmac('sha256', urldecode(http_build_query($query)), 'shopify-secret');

        $this->get('/api/v1/integrations/shopify/callback?'.http_build_query($query))
            ->assertRedirectContains('http://localhost:3000/stores?connected=shopify&platform=shopify');

        $store = Store::first();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('shpat_live', $store->credential('access_token'));
    }

    public function test_shopify_callback_with_bad_signature_is_rejected(): void
    {
        $this->connect(['name' => 'Shopify Store', 'platform' => 'shopify', 'credentials' => ['shop_domain' => 'finovo-demo']]);
        $state = app(AuthorizationState::class)->issue(Store::first());

        $this->get('/api/v1/integrations/shopify/callback?'.http_build_query([
            'code' => 'x', 'shop' => 'finovo-demo.myshopify.com', 'state' => $state, 'hmac' => 'forged',
        ]))->assertRedirectContains('connection_error=');

        $this->assertSame(Store::STATUS_SETUP_INCOMPLETE, Store::first()->status);
        Http::assertNothingSent();
    }

    public function test_woocommerce_approve_flow_requires_finovo_on_https(): void
    {
        $this->connect([
            'name' => 'Woo Store',
            'platform' => 'woocommerce',
            'credentials' => ['site_url' => 'http://localhost/wordpress'],
        ])->assertUnprocessable()->assertJsonValidationErrors('consumer_key');

        $this->assertDatabaseCount('stores', 0);
    }

    public function test_woocommerce_callback_saves_keys_from_a_valid_state_only(): void
    {
        // The Approve flow is only offered when Finovo itself is served over HTTPS.
        URL::forceScheme('https');

        $response = $this->connect([
            'name' => 'Woo Store',
            'platform' => 'woocommerce',
            'credentials' => ['site_url' => 'https://woo.test'],
        ])->assertCreated();

        $this->assertStringStartsWith('https://woo.test/wc-auth/v1/authorize?', $response->json('next_action.url'));
        parse_str(parse_url($response->json('next_action.url'), PHP_URL_QUERY), $authorize);
        parse_str(parse_url($authorize['callback_url'], PHP_URL_QUERY), $callback);

        $keys = ['consumer_key' => 'ck_live', 'consumer_secret' => 'cs_live'];

        $this->postJson('/api/v1/integrations/woocommerce/callback?state=forged', $keys)->assertForbidden();

        Http::fake(['https://woo.test/wp-json/wc/v3/products*' => Http::response([])]);

        $this->postJson('/api/v1/integrations/woocommerce/callback?state='.urlencode($callback['state']), $keys)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $store = Store::first();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('cs_live', $store->credential('consumer_secret'));
    }

    public function test_woocommerce_can_connect_directly_with_rest_api_keys(): void
    {
        Http::fake(['http://localhost/wordpress/wp-json/wc/v3/products*' => Http::response([])]);

        $this->connect([
            'name' => 'Local Woo',
            'platform' => 'woocommerce',
            'credentials' => ['site_url' => 'http://localhost/wordpress', 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'],
        ])->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_ACTIVE)
            ->assertJsonPath('next_action', null);

        // Plain HTTP sites are signed with OAuth 1.0a instead of Basic Auth.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth_signature=') && str_contains($request->url(), 'oauth_consumer_key=ck_1'));
    }

    public function test_woocommerce_keys_must_be_entered_as_a_pair(): void
    {
        $this->connect([
            'name' => 'Local Woo',
            'platform' => 'woocommerce',
            'credentials' => ['site_url' => 'http://localhost/wordpress', 'consumer_key' => 'ck_1'],
        ])->assertUnprocessable()->assertJsonValidationErrors('credentials.consumer_secret');
    }

    public function test_bigcommerce_can_connect_with_a_store_level_api_token(): void
    {
        config(['services.bigcommerce.client_id' => null]);
        Http::fake(['https://api.bigcommerce.com/stores/abc123/v2/store' => Http::response(['name' => 'BC Demo'])]);

        $this->connect([
            'name' => 'BC Store',
            'platform' => 'bigcommerce',
            'credentials' => ['store_hash' => 'abc123', 'access_token' => 'bc-api-token'],
        ])->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_ACTIVE)
            ->assertJsonPath('next_action', null);
    }

    public function test_bigcommerce_install_links_to_the_waiting_store_by_store_hash(): void
    {
        $this->connect([
            'name' => 'BC Store',
            'platform' => 'bigcommerce',
            'credentials' => ['store_hash' => 'abc123'],
        ])->assertCreated()->assertJsonPath('next_action.type', 'install_app');

        Http::fake([
            'https://login.bigcommerce.com/oauth2/token' => Http::response(['access_token' => 'bc-token', 'context' => 'stores/abc123']),
            'https://api.bigcommerce.com/stores/abc123/v2/store' => Http::response(['name' => 'BC Demo']),
        ]);

        $this->get('/api/v1/integrations/bigcommerce/callback?code=c&scope=s&context=stores/abc123')
            ->assertOk()
            ->assertSee('BigCommerce store connected');

        $this->assertSame(Store::STATUS_ACTIVE, Store::first()->status);
    }

    public function test_bigcommerce_without_one_click_install_needs_a_store_hash(): void
    {
        $this->connect(['name' => 'BC Store', 'platform' => 'bigcommerce', 'credentials' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_hash');
    }

    public function test_bigcommerce_one_click_install_connects_without_a_store_hash(): void
    {
        config(['services.bigcommerce.app_id' => '54321']);

        $response = $this->connect(['name' => 'BC Store', 'platform' => 'bigcommerce'])
            ->assertCreated()
            ->assertJsonPath('next_action.type', 'redirect');

        // Finovo remembers the store in a cookie, then hands the merchant to BigCommerce's approval page.
        $install = $this->get($response->json('next_action.url'))
            ->assertRedirect('https://login.bigcommerce.com/app/54321/install')
            ->assertCookie(BigCommerceAuthorization::INSTALL_COOKIE, null, false);

        Http::fake([
            'https://login.bigcommerce.com/oauth2/token' => Http::response(['access_token' => 'bc-token', 'context' => 'stores/xyz789']),
            'https://api.bigcommerce.com/stores/xyz789/v2/store' => Http::response(['name' => 'BC Demo']),
            'https://login.bigcommerce.com/app/54321/install/succeeded' => Http::response('', 200),
        ]);

        $this->withUnencryptedCookie(BigCommerceAuthorization::INSTALL_COOKIE, $install->getCookie(BigCommerceAuthorization::INSTALL_COOKIE, false)->getValue())
            ->get('/api/v1/integrations/bigcommerce/callback?code=c&scope=s&context=stores/xyz789&external_install=1')
            ->assertOk()
            ->assertSee('BigCommerce store connected');

        $store = Store::first();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('xyz789', $store->external_reference);
        Http::assertSent(fn ($request) => $request->url() === 'https://login.bigcommerce.com/app/54321/install/succeeded');
    }

    public function test_bigcommerce_install_for_an_unknown_store_shows_an_error_page(): void
    {
        Http::fake([
            'https://login.bigcommerce.com/oauth2/token' => Http::response(['access_token' => 'bc-token', 'context' => 'stores/nobody1']),
        ]);

        $this->get('/api/v1/integrations/bigcommerce/callback?code=c&scope=s&context=stores/nobody1')
            ->assertStatus(422)
            ->assertSee('could not be connected');
    }

    public function test_wix_without_the_finovo_app_needs_a_site_id(): void
    {
        $this->connect(['name' => 'Wix Site', 'platform' => 'wix', 'credentials' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');
    }

    public function test_wix_app_install_needs_finovo_on_https(): void
    {
        config(['services.wix.app_id' => 'wix-app', 'services.wix.app_secret' => 'wix-secret']);

        $this->connect(['name' => 'Wix Site', 'platform' => 'wix', 'credentials' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');
    }

    public function test_wix_one_click_install_connects_the_site(): void
    {
        URL::forceScheme('https');
        config(['services.wix.app_id' => 'wix-app', 'services.wix.app_secret' => 'wix-secret', 'services.wix.share_url_id' => 'share-1']);

        $url = $this->connect(['name' => 'Wix Site', 'platform' => 'wix', 'credentials' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_SETUP_INCOMPLETE)
            ->assertJsonPath('next_action.type', 'redirect')
            ->json('next_action.url');

        $this->assertStringStartsWith('https://www.wix.com/app-installer?appId=wix-app&shareUrlId=share-1&postInstallationUrl=', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $installer);
        parse_str((string) parse_url($installer['postInstallationUrl'], PHP_URL_QUERY), $callback);

        Http::fake([
            'https://www.wixapis.com/oauth2/token' => Http::response(['access_token' => 'wix-token', 'expires_in' => 14400]),
            'https://www.wixapis.com/apps/v1/instance' => Http::response(['site' => ['siteId' => '31713e68-1111-4222-8333-444455556666']]),
            'https://www.wixapis.com/stores/v3/products/search' => Http::response(['products' => []]),
        ]);

        $this->get('/api/v1/integrations/wix/callback?'.http_build_query([
            'state' => $callback['state'],
            'instanceId' => 'instance-1',
            'signedInstance' => $this->signedWixInstance('instance-1', 'wix-secret'),
        ]))->assertRedirectContains('http://localhost:3000/stores?connected=wix&platform=wix');

        $store = Store::sole();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('31713e68-1111-4222-8333-444455556666', $store->external_reference);
        $this->assertSame('instance-1', $store->credential('instance_id'));
        Http::assertSent(fn ($request) => $request->url() === 'https://www.wixapis.com/stores/v3/products/search'
            && $request->header('Authorization') === ['wix-token']);
    }

    public function test_wix_callback_with_a_forged_instance_is_rejected(): void
    {
        URL::forceScheme('https');
        config(['services.wix.app_id' => 'wix-app', 'services.wix.app_secret' => 'wix-secret']);

        $this->connect(['name' => 'Wix Site', 'platform' => 'wix', 'credentials' => []])->assertCreated();
        $store = Store::sole();

        $response = $this->get('/api/v1/integrations/wix/callback?'.http_build_query([
            'state' => app(AuthorizationState::class)->issue($store),
            'instanceId' => 'someone-elses-instance',
            'signedInstance' => $this->signedWixInstance('someone-elses-instance', 'wrong-secret'),
        ]));

        $this->assertStringContainsString('connection_error=', $response->headers->get('Location'));
        $this->assertSame(Store::STATUS_SETUP_INCOMPLETE, $store->fresh()->status);
        $this->assertNull($store->fresh()->credential('instance_id'));
    }

    private function signedWixInstance(string $instanceId, string $secret): string
    {
        $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $payload = $encode(json_encode(['instanceId' => $instanceId, 'signDate' => now()->toIso8601String()]));

        return $encode(hash_hmac('sha256', $payload, $secret, true)).'.'.$payload;
    }

    public function test_prestashop_module_download_carries_the_store_connection(): void
    {
        $url = $this->connect(['name' => 'Presta Shop', 'platform' => 'prestashop', 'credentials' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', Store::STATUS_SETUP_INCOMPLETE)
            ->assertJsonPath('next_action.type', 'install_module')
            ->json('next_action.url');

        $response = $this->get($url)->assertOk()->assertDownload('finovo.zip');

        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('finovo/finovo.php'));
        $this->assertNotFalse($zip->locateName('finovo/logo.png'));
        $connection = (string) $zip->getFromName('finovo/connection.php');
        $zip->close();

        $this->assertStringContainsString(route('integrations.prestashop.callback'), $connection);
        $this->assertStringContainsString("if (!defined('_PS_VERSION_'))", $connection);
    }

    public function test_prestashop_module_download_needs_a_signed_link(): void
    {
        $this->connect(['name' => 'Presta Shop', 'platform' => 'prestashop', 'credentials' => []])->assertCreated();

        $this->get('/api/v1/integrations/modules/'.Store::sole()->id)->assertForbidden();
    }

    public function test_installed_prestashop_module_connects_the_shop(): void
    {
        $this->connect(['name' => 'Presta Shop', 'platform' => 'prestashop', 'credentials' => []])->assertCreated();
        $store = Store::sole();
        Http::fake(['http://localhost/prestashop/api/products*' => Http::response(['products' => []])]);

        $this->postJson('/api/v1/integrations/prestashop/callback', [
            'state' => app(AuthorizationState::class)->issue($store, 604800),
            'webservice_key' => 'ABCDEF0123456789ABCDEF0123456789',
            'shop_url' => 'http://localhost/prestashop/',
        ])->assertOk()->assertJsonPath('status', 'success');

        $store->refresh();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('http://localhost/prestashop', $store->external_reference);
        $this->assertSame('ABCDEF0123456789ABCDEF0123456789', $store->credential('webservice_key'));
    }

    public function test_prestashop_callback_rejects_an_invalid_link(): void
    {
        $this->postJson('/api/v1/integrations/prestashop/callback', [
            'state' => 'forged',
            'webservice_key' => 'ABCDEF0123456789ABCDEF0123456789',
            'shop_url' => 'http://localhost/prestashop/',
        ])->assertForbidden()->assertJsonPath('status', 'error');
    }

    public function test_opencart_module_download_is_an_ocmod_package(): void
    {
        $url = $this->connect(['name' => 'OpenCart Shop', 'platform' => 'opencart', 'credentials' => []])
            ->assertCreated()
            ->assertJsonPath('next_action.type', 'install_module')
            ->json('next_action.url');

        $response = $this->get($url)->assertOk()->assertDownload('finovo.ocmod.zip');

        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($zip->locateName('upload/admin/controller/extension/module/finovo.php'));
        $this->assertNotFalse($zip->locateName('upload/catalog/controller/extension/finovo/api.php'));
        $connection = (string) $zip->getFromName('upload/system/library/finovo/connection.php');
        $zip->close();

        $this->assertStringContainsString(route('integrations.opencart.callback'), $connection);
        $this->assertStringContainsString("if (!defined('DIR_SYSTEM'))", $connection);
    }

    public function test_installed_opencart_module_connects_with_signed_requests(): void
    {
        $this->connect(['name' => 'OpenCart Shop', 'platform' => 'opencart', 'credentials' => []])->assertCreated();
        $store = Store::sole();
        Http::fake(['http://shop.test/index.php*' => Http::response(['status' => 'ok', 'orders' => 7])]);

        $this->postJson('/api/v1/integrations/opencart/callback', [
            'state' => app(AuthorizationState::class)->issue($store, 604800),
            'api_key' => 'finovo_abc',
            'api_secret' => str_repeat('s', 64),
            'shop_url' => 'http://shop.test/',
        ])->assertOk()->assertJsonPath('message', 'Connected to OpenCart at http://shop.test (7 orders found).');

        $this->assertSame(Store::STATUS_ACTIVE, $store->fresh()->status);
        Http::assertSent(fn ($request) => $request->url() === 'http://shop.test/index.php?route=extension%2Ffinovo%2Fapi%2Fhealth'
            && $request->hasHeader('X-Bridge-Api-Key', 'finovo_abc')
            && $request->header('X-Bridge-Signature')[0] === hash_hmac('sha256', $request->header('X-Bridge-Timestamp')[0].$request->header('X-Bridge-Nonce')[0], str_repeat('s', 64)));
    }

    public function test_opencart_can_still_connect_with_database_details(): void
    {
        $this->connect(['name' => 'OpenCart DB', 'platform' => 'opencart', 'credentials' => ['db_name' => 'missing_shop_db']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credentials');
    }

    public function test_ebay_authorization_saves_refresh_token(): void
    {
        $response = $this->connect(['name' => 'eBay Seller', 'platform' => 'ebay'])->assertCreated();
        parse_str(parse_url($response->json('next_action.url'), PHP_URL_QUERY), $authorize);

        Http::fake([
            'https://api.sandbox.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'v^1.token', 'expires_in' => 7200, 'refresh_token' => 'v^1.refresh']),
            'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response(['total' => 0]),
        ]);

        $this->get('/api/v1/integrations/ebay/callback?'.http_build_query(['code' => 'c', 'state' => $authorize['state']]))
            ->assertRedirectContains('http://localhost:3000/stores?connected=ebay&platform=ebay');

        $store = Store::first();
        $this->assertSame(Store::STATUS_ACTIVE, $store->status);
        $this->assertSame('v^1.refresh', $store->credential('refresh_token'));
    }

    public function test_custom_bridge_test_connection_records_success_and_failure(): void
    {
        $response = $this->connect([
            'name' => 'Client PHP Site',
            'platform' => 'custom_bridge',
            'credentials' => ['bridge_url' => 'https://client.test/api/'],
        ])->assertCreated()->assertJsonStructure(['credentials' => ['api_key', 'api_secret']]);

        $store = Store::first();
        $this->assertSame(Store::STATUS_SETUP_INCOMPLETE, $store->status);

        Http::fakeSequence('https://client.test/api/health.php')->push([], 401)->push(['status' => 'ok']);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$store->id}/test-connection")
            ->assertUnprocessable();
        $this->assertNotNull($store->fresh()->connection_error);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$store->id}/test-connection")
            ->assertOk()
            ->assertJsonPath('data.status', Store::STATUS_ACTIVE)
            ->assertJsonPath('data.connection_error', null);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Bridge-Api-Key', $response->json('credentials.api_key')));
    }

    public function test_authorization_link_can_be_requested_again_for_pending_store(): void
    {
        $this->connect(['name' => 'Shopify Store', 'platform' => 'shopify', 'credentials' => ['shop_domain' => 'finovo-demo']]);

        $this->actingAs($this->admin)->getJson('/api/v1/stores/'.Store::first()->id.'/authorization')
            ->assertOk()
            ->assertJsonPath('next_action.type', 'redirect');
    }

    public function test_platform_without_server_keys_explains_what_is_missing(): void
    {
        config(['services.shopify.client_id' => null]);

        $this->connect(['name' => 'Shopify Store', 'platform' => 'shopify', 'credentials' => ['shop_domain' => 'finovo-demo']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');
    }

    public function test_store_platform_enum_covers_every_platform_with_a_connector(): void
    {
        foreach (StorePlatform::cases() as $platform) {
            $this->assertInstanceOf(StoreConnector::class, app(StoreConnectorFactory::class)->for($platform));
        }
    }

    public function test_adding_a_store_that_is_awaiting_authorization_continues_with_it(): void
    {
        $payload = ['name' => 'Shopify Store', 'platform' => 'shopify', 'credentials' => ['shop_domain' => 'finovo-demo.myshopify.com']];
        $first = $this->connect($payload)->assertCreated()->json('data.id');

        // The same shop again: no duplicate, the waiting store is reused and sent to Shopify's approval page.
        $this->connect([...$payload, 'name' => 'Main Shopify'])
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.name', 'Main Shopify')
            ->assertJsonPath('data.connection_state', 'awaiting_authorization')
            ->assertJsonPath('next_action.type', 'redirect');
        $this->assertDatabaseCount('stores', 1);

        // Its Connect button goes to the same approval page.
        $this->getJson("/api/v1/stores/{$first}/authorization")
            ->assertOk()
            ->assertJsonPath('next_action.type', 'redirect');

        // Once connected, adding it again is refused.
        Store::find($first)->update(['status' => Store::STATUS_ACTIVE]);
        $this->connect($payload)->assertUnprocessable()->assertJsonValidationErrors('credentials');
    }

    public function test_platform_sends_the_merchant_back_to_the_portal_they_started_from(): void
    {
        $id = $this->connect(['name' => 'Shopify Store', 'platform' => 'shopify', 'credentials' => ['shop_domain' => 'finovo-demo.myshopify.com']])->json('data.id');

        $this->withHeader('Referer', 'http://localhost:3001/stores')->getJson("/api/v1/stores/{$id}/authorization")->assertOk();
        $this->assertSame('http://localhost:3001/stores', Store::find($id)->portalUrl('/stores'));

        // An address outside the portal's hosts is never trusted.
        $this->withHeader('Referer', 'https://evil.example/stores')->getJson("/api/v1/stores/{$id}/authorization")->assertOk();
        $this->assertSame('http://localhost:3001/stores', Store::find($id)->portalUrl('/stores'));

        $state = app(AuthorizationState::class)->issue(Store::find($id));
        $this->get('/api/v1/integrations/ebay/callback?state='.urlencode($state))->assertRedirectContains('http://localhost:3000/stores?connection_error=');
        $this->get('/api/v1/integrations/shopify/callback?shop=finovo-demo.myshopify.com&state='.urlencode($state))
            ->assertRedirectContains('http://localhost:3001/stores?connection_error=');
    }

    public function test_stores_filter_by_the_status_shown_on_the_page(): void
    {
        $make = fn (string $name, string $platform, string $status, ?string $error = null) => Store::create([
            'organization_id' => $this->org->id, 'name' => $name, 'code' => $name, 'platform' => $platform, 'status' => $status, 'connection_error' => $error,
        ]);
        $make('Live', 'manual', Store::STATUS_ACTIVE);
        $make('Waiting', 'shopify', Store::STATUS_SETUP_INCOMPLETE);
        $make('Failed', 'shopify', Store::STATUS_SETUP_INCOMPLETE, 'Shopify rejected the credentials.');
        $make('Bridge', 'custom_bridge', Store::STATUS_SETUP_INCOMPLETE);
        $make('Gone', 'bigcommerce', Store::STATUS_DISCONNECTED);

        $names = fn (string $state) => collect($this->actingAs($this->admin)->getJson("/api/v1/stores?status={$state}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Live'], $names('connected'));
        $this->assertSame(['Waiting'], $names('awaiting_authorization'));
        $this->assertSame(['Bridge', 'Failed'], $names('setup_incomplete'));
        $this->assertSame(['Gone'], $names('disconnected'));
        $this->getJson('/api/v1/stores')->assertJsonPath('connection_states.1.label', 'Awaiting Authorization');
    }

    public function test_an_unexpected_platform_error_is_recorded_and_never_crashes_the_portal(): void
    {
        $store = Store::create([
            'organization_id' => $this->org->id, 'name' => 'Shop', 'code' => 'shop', 'platform' => 'shopify',
            'status' => Store::STATUS_ACTIVE, 'external_reference' => 'finovo-demo.myshopify.com', 'credentials' => ['access_token' => 'shpat'],
        ]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $message = $this->actingAs($this->admin)->postJson("/api/v1/stores/{$store->id}/sync")->assertUnprocessable()->json('message');
        $this->assertStringStartsWith('Shopify took too long to answer.', $message);
        $this->assertSame('sync_issues', $store->fresh()->sync_status);
        $this->assertDatabaseHas('integration_errors', ['store_id' => $store->id, 'error_code' => 'sync.RuntimeException']);

        // A failure Finovo did not expect (here a bug in a connector) is logged and still answered with a readable message.
        $this->app->instance(StoreConnectorFactory::class, new class extends StoreConnectorFactory
        {
            public function for(StorePlatform $platform): StoreConnector
            {
                throw new \TypeError('Argument #1 must be of type string, null given');
            }
        });
        $this->postJson("/api/v1/stores/{$store->id}/test-connection")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Something went wrong while working with Shopify. The error was logged; try again in a few minutes.');
        $this->assertDatabaseHas('integration_errors', ['store_id' => $store->id, 'error_code' => 'test.TypeError']);
    }
}
