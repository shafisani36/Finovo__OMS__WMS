<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Push\BigCommerceWriter;
use App\Integrations\Push\EbayWriter;
use App\Integrations\Push\MagentoWriter;
use App\Integrations\Push\OpenCartWriter;
use App\Integrations\Push\OsCommerceWriter;
use App\Integrations\Push\PrestaShopWriter;
use App\Integrations\Push\WixWriter;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Models\StoreSyncTask;
use App\Models\StoreWebhookEvent;
use App\Models\User;
use App\Services\StoreSyncOutbox;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Products created in Finovo reach every platform, returns are noted on the store's order, and the stores that
 * can notify Finovo (BigCommerce, PrestaShop and OpenCart modules, Wix app) are imported from at once.
 */
class StoreCatalogAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->admin->currentOrganization();
        $this->product = Product::create(['organization_id' => $org->id, 'name' => 'Classic Tee', 'sku' => 'TEE', 'description' => 'Soft cotton', 'is_active' => true]);
        $this->product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'price' => 1500, 'option_name' => 'Size', 'option_value' => 'M', 'is_active' => true]);
        $this->product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-L', 'price' => 1600, 'option_name' => 'Size', 'option_value' => 'L', 'is_active' => true]);
        $this->product->load('variants');
    }

    public function test_bigcommerce_products_are_created_with_their_variants_and_kept_in_step(): void
    {
        $store = $this->store(StorePlatform::BIGCOMMERCE, 'abc123', ['access_token' => 'bc']);
        $bc = 'api.bigcommerce.com/stores/abc123/v3/catalog/products';
        Http::fake([
            $bc.'/77/variants/91' => Http::response(['data' => ['id' => 91]]),
            $bc.'/77/variants*' => fn (Request $request) => $request->method() === 'POST'
                ? Http::response(['data' => ['id' => 93]])
                : Http::response(['data' => [['id' => 91, 'sku' => 'TEE-M'], ['id' => 92, 'sku' => 'TEE-L']]]),
            $bc.'/77/options/5/values' => Http::response(['data' => ['id' => 55, 'label' => 'XL']]),
            $bc.'/77/options' => Http::response(['data' => [['id' => 5, 'display_name' => 'Size', 'option_values' => [['id' => 51, 'label' => 'M'], ['id' => 52, 'label' => 'L']]]]]),
            $bc.'/77' => Http::response(['data' => ['id' => 77]]),
            $bc => Http::response(['data' => ['id' => 77]]),
        ]);
        $writer = app(BigCommerceWriter::class);

        $pushed = $writer->saveProduct($store, $this->product, null);
        $this->assertSame('77', $pushed->externalId);
        $this->assertSame(['91', '92'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://'.$bc
            && $request['variants'][1]['option_values'] === [['option_display_name' => 'Size', 'label' => 'L']]
            && $request['inventory_tracking'] === 'variant');

        // Linked now; a new variant is added under the product's existing option, a dropped one is removed.
        $this->link($store, '77', ['91', '92']);
        $this->product->variants()->where('sku', 'TEE-L')->delete();
        $this->product->variants()->create(['organization_id' => $this->product->organization_id, 'sku' => 'TEE-XL', 'price' => 1700, 'option_name' => 'Size', 'option_value' => 'XL', 'is_active' => true]);
        $writer->saveProduct($store, $this->product->fresh('variants'), '77');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), 'variants/91') && $request['sku'] === 'TEE-M');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'options/5/values') && $request['label'] === 'XL');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '77/variants') && $request['option_values'] === [['option_id' => 5, 'id' => 55]]);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), 'variants/92'));
    }

    public function test_magento_wix_and_ebay_products(): void
    {
        $magento = $this->store(StorePlatform::MAGENTO, 'https://magento.test', ['access_token' => 'mg']);
        $wix = $this->store(StorePlatform::WIX, 'site-1', ['api_key' => 'IST.key']);
        config(['services.ebay.environment' => 'sandbox']);
        $ebay = $this->store(StorePlatform::EBAY, null, ['refresh_token' => 'rt', 'access_token' => 'at', 'access_token_expires_at' => time() + 3600]);
        $nextId = 300;
        Http::fake([
            'https://magento.test/rest/V1/products' => function () use (&$nextId) {
                return Http::response(['id' => ++$nextId]);
            },
            'https://www.wixapis.com/stores/v3/products' => Http::response(['product' => ['id' => 'prod-9', 'variantsInfo' => ['variants' => [['id' => 'var-m'], ['id' => 'var-l']]]]]),
            'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item/*' => Http::response([], 204),
        ]);

        $pushed = app(MagentoWriter::class)->saveProduct($magento, $this->product, null);
        $this->assertSame(['301', '302'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'V1/products') && $request['product']['name'] === 'Classic Tee - L' && $request['product']['sku'] === 'TEE-L' && $request['product']['type_id'] === 'simple');

        $pushed = app(WixWriter::class)->saveProduct($wix, $this->product, null);
        $this->assertSame(['var-m', 'var-l'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.wixapis.com/stores/v3/products'
            && $request['product']['options'][0]['choicesSettings']['choices'] === [['name' => 'M', 'choiceType' => 'CHOICE_TEXT'], ['name' => 'L', 'choiceType' => 'CHOICE_TEXT']]
            && $request['product']['variantsInfo']['variants'][1]['price']['actualPrice']['amount'] === '1600.00'
            && $request['product']['productType'] === 'PHYSICAL');

        $pushed = app(EbayWriter::class)->saveProduct($ebay, $this->product, null);
        $this->assertSame(['TEE-M', 'TEE-L'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), 'inventory_item/TEE-M')
            && $request['product']['title'] === 'Classic Tee - M' && $request->hasHeader('Content-Language', 'en-US'));
    }

    public function test_wix_updates_keep_the_ids_of_existing_options_and_variants(): void
    {
        $wix = $this->store(StorePlatform::WIX, 'site-1', ['api_key' => 'IST.key']);
        $this->link($wix, 'prod-9', ['var-m', 'var-l']);
        Http::fake([
            'https://www.wixapis.com/stores/v3/products/prod-9*' => fn (Request $request) => Http::response(['product' => $request->method() === 'GET'
                ? ['id' => 'prod-9', 'revision' => '3', 'options' => [['id' => 'opt-1', 'name' => 'Size', 'optionRenderType' => 'TEXT_CHOICES', 'choicesSettings' => ['choices' => [['choiceId' => 'c-m', 'name' => 'M', 'choiceType' => 'CHOICE_TEXT'], ['choiceId' => 'c-l', 'name' => 'L', 'choiceType' => 'CHOICE_TEXT']]]]], 'variantsInfo' => ['variants' => [['id' => 'var-m'], ['id' => 'var-l']]]]
                : ['id' => 'prod-9', 'variantsInfo' => ['variants' => [['id' => 'var-m'], ['id' => 'var-l']]]]]),
        ]);

        app(WixWriter::class)->saveProduct($wix, $this->product, 'prod-9');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $request['product']['revision'] === '3'
            && $request['product']['options'][0]['id'] === 'opt-1'
            && $request['product']['options'][0]['choicesSettings']['choices'][0]['choiceId'] === 'c-m'
            && array_column($request['product']['variantsInfo']['variants'], 'id') === ['var-m', 'var-l']);
    }

    public function test_prestashop_products_get_combinations_with_the_shops_attributes(): void
    {
        $store = $this->store(StorePlatform::PRESTASHOP, 'https://presta.test', ['webservice_key' => 'WSKEY']);
        $nextCombination = 40;
        Http::fake(function (Request $request) use (&$nextCombination) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $lang = fn (string $text) => [['id' => '1', 'value' => $text]];

            return match (true) {
                $request->method() === 'POST' && $path === '/api/products' => Http::response(['product' => ['id' => 12]], 201),
                $request->method() === 'POST' && $path === '/api/product_option_values' => Http::response(['product_option_value' => ['id' => 8]], 201),
                $request->method() === 'POST' && $path === '/api/combinations' => Http::response(['combination' => ['id' => ++$nextCombination]], 201),
                $request->method() === 'POST' && $path === '/api/messages' => Http::response(['message' => ['id' => 1]], 201),
                $path === '/api/languages' => Http::response(['languages' => [['id' => 1], ['id' => 2]]]),
                $path === '/api/combinations' => Http::response(['combinations' => []]),
                $path === '/api/product_options' => Http::response(['product_options' => [['id' => 3, 'name' => $lang('Size')]]]),
                $path === '/api/product_option_values' => Http::response(['product_option_values' => [['id' => 7, 'name' => $lang('M')]]]),
            };
        });
        $writer = app(PrestaShopWriter::class);
        $xml = fn (Request $request) => simplexml_load_string($request->body());

        $pushed = $writer->saveProduct($store, $this->product, null);

        $this->assertSame(['12-41', '12-42'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/products')
            && (string) $xml($request)->product->name->language[1]['id'] === '2'
            && (string) $xml($request)->product->associations->categories->category->id === '2'
            && (string) $xml($request)->product->link_rewrite->language[0] === 'classic-tee');
        // "L" is a new value of the shop's existing "Size" attribute; "M" already exists.
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/product_option_values') && $request->method() === 'POST'
            && (string) $xml($request)->product_option_value->id_attribute_group === '3' && (string) $xml($request)->product_option_value->name->language[0] === 'L');
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/combinations') && $request->method() === 'POST'
            && (string) $xml($request)->combination->reference === 'TEE-L' && (string) $xml($request)->combination->price === '100.00'
            && (string) $xml($request)->combination->associations->product_option_values->product_option_value->id === '8');

        $order = $this->order($store, '6');
        $writer->reportReturn($store, $order, $this->completedReturn($order));
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/messages')
            && str_contains((string) $xml($request)->message->message, 'RMA-TEST') && (string) $xml($request)->message->private === '1');
    }

    public function test_opencart_and_oscommerce_products_through_the_module_and_the_database(): void
    {
        $module = $this->store(StorePlatform::OPENCART, 'https://oc.test', ['api_key' => 'key', 'api_secret' => 'secret']);
        Http::fake(['https://oc.test/index.php?route=extension/finovo/api/save_product' => Http::response(['product_id' => 50, 'option_values' => [(string) $this->product->variants[0]->id => 7, (string) $this->product->variants[1]->id => 8]])]);

        $pushed = app(OpenCartWriter::class)->saveProduct($module, $this->product, null);
        $this->assertSame(['50-7', '50-8'], array_values($pushed->variantIds));
        Http::assertSent(fn (Request $request) => $request['product']['option_name'] === 'Size' && $request['product']['values'][1]['price_diff'] == 100 && $request['product']['values'][1]['name'] === 'L');

        $db = $this->shopDatabase();
        $opencart = $this->store(StorePlatform::OPENCART, null, ['db_name' => 'shop', 'table_prefix' => 'oc_']);
        $oscommerce = $this->store(StorePlatform::OSCOMMERCE, null, ['db_name' => 'shop', 'table_prefix' => 'os_']);

        $pushed = app(OpenCartWriter::class)->saveProduct($opencart, $this->product, null);
        $productId = (int) $pushed->externalId;
        $this->assertSame('Classic Tee', $db->table('oc_product_description')->where('product_id', $productId)->value('name'));
        $this->assertSame(['M', 'L'], $db->table('oc_option_value_description')->orderBy('option_value_id')->pluck('name')->all());
        $this->assertSame(['+', '+'], $db->table('oc_product_option_value')->where('product_id', $productId)->pluck('price_prefix')->all());
        $this->assertEquals(100, $db->table('oc_product_option_value')->where('product_option_value_id', explode('-', array_values($pushed->variantIds)[1])[1])->value('price'));

        $pushed = app(OsCommerceWriter::class)->saveProduct($oscommerce, $this->product, null);
        $this->assertSame(['Classic Tee - M', 'Classic Tee - L'], $db->table('os_products_description')->orderBy('products_id')->pluck('products_name')->all());
        $this->assertSame('TEE-L', $db->table('os_products')->where('products_id', array_values($pushed->variantIds)[1])->value('products_model'));
    }

    public function test_a_completed_return_is_noted_on_the_store_order(): void
    {
        $store = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        Http::fake(['https://shop.test/*' => Http::response(['id' => 1])]);
        $order = $this->order($store, '77');

        $return = $this->completedReturn($order);
        $this->assertSame(StoreSyncTask::TYPE_RETURN, StoreSyncTask::sole()->type);
        app(StoreSyncOutbox::class)->runDue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.test/wp-json/wc/v3/orders/77/notes'
            && $request['note'] === "Return {$return->return_number} completed in Finovo: 1 × Classic Tee - M (sellable)." && $request['customer_note'] === false);
    }

    public function test_bigcommerce_subscriptions_carry_a_secret_that_notifications_must_send_back(): void
    {
        $store = $this->store(StorePlatform::BIGCOMMERCE, 'abc123', ['access_token' => 'bc']);
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v3/hooks' => fn (Request $request) => Http::response($request->method() === 'GET' ? ['data' => []] : ['data' => ['id' => 1]]),
            'api.bigcommerce.com/stores/abc123/v2/orders/100/products*' => Http::response([['id' => 1, 'sku' => 'TEE-M', 'name' => 'Classic Tee', 'quantity' => 1, 'price_ex_tax' => 1500, 'total_inc_tax' => 1500]]),
            'api.bigcommerce.com/stores/abc123/v2/orders/100/shipping_addresses' => Http::response([]),
            'api.bigcommerce.com/stores/abc123/v2/orders/100' => Http::response(['id' => 100, 'status_id' => 11, 'billing_address' => ['first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@example.com'], 'total_inc_tax' => 1500, 'currency_code' => 'PKR', 'date_created' => 'Tue, 27 Oct 2026 10:00:00 +0000']),
        ]);

        app(BigCommerceWriter::class)->registerWebhooks($store, 'https://finovo.test/api/v1/webhooks/bigcommerce/'.$store->id);
        $secret = $store->fresh()->webhook_secret;
        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['scope'] === 'store/order/created' && $request['headers'] === ['X-Finovo-Webhook-Secret' => $secret]);

        $payload = ['scope' => 'store/order/created', 'store_id' => '1', 'data' => ['type' => 'order', 'id' => 100], 'hash' => 'h-1'];
        $this->postJson("/api/v1/webhooks/bigcommerce/{$store->id}", $payload)->assertUnauthorized();
        $this->postJson("/api/v1/webhooks/bigcommerce/{$store->id}", $payload, ['X-Finovo-Webhook-Secret' => $secret])->assertOk();

        $this->assertSame(StoreWebhookEvent::STATUS_PROCESSED, StoreWebhookEvent::sole()->status);
        $this->assertSame('Ali Raza', Order::where('external_order_id', '100')->sole()->customer_name);
    }

    public function test_prestashop_and_opencart_module_notifications_are_checked_against_the_shops_key(): void
    {
        $presta = $this->store(StorePlatform::PRESTASHOP, 'https://presta.test', ['webservice_key' => 'WSKEY']);
        $opencart = $this->store(StorePlatform::OPENCART, 'https://oc.test', ['api_key' => 'key', 'api_secret' => 'secret']);
        Http::fake(fn () => Http::response([], 500));
        $body = json_encode(['id' => 6]);
        $headers = fn (string $secret, string $topic) => [
            'X-Finovo-Topic' => $topic,
            'X-Finovo-Delivery' => 'd-'.$topic,
            'X-Finovo-Signature' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        ];

        $this->postJson("/api/v1/webhooks/prestashop/{$presta->id}", ['id' => 6], $headers('wrong', 'order.updated'))->assertUnauthorized();
        $this->postJson("/api/v1/webhooks/prestashop/{$presta->id}", ['id' => 6], $headers('WSKEY', 'order.updated'))->assertOk();
        $this->postJson("/api/v1/webhooks/opencart/{$opencart->id}", ['id' => 6], $headers('secret', 'product.deleted'))->assertOk();

        $this->assertSame([['prestashop', 'order.updated', '6'], ['opencart', 'product.deleted', '6']], StoreWebhookEvent::with('store')->orderBy('id')->get()
            ->map(fn (StoreWebhookEvent $event) => [$event->store->platform->value, $event->topic, $event->resource_id])->all());
    }

    public function test_wix_notifications_are_verified_with_the_apps_public_key(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        config(['services.wix.webhook_public_key' => openssl_pkey_get_details($key)['key']]);
        $store = $this->store(StorePlatform::WIX, 'site-1', ['instance_id' => 'inst-1']);
        Http::fake(fn () => Http::response([], 500));
        $event = json_encode(['id' => 'evt-1', 'eventType' => 'wix.ecom.v1.order_created', 'instanceId' => 'inst-1', 'data' => json_encode(['entityId' => 'order-9'])]);
        $jwt = $this->jwt(['data' => $event], $key);

        $this->call('POST', '/api/v1/webhooks/wix', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $jwt.'x')->assertUnauthorized();
        $this->call('POST', '/api/v1/webhooks/wix', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $jwt)->assertOk();

        $recorded = StoreWebhookEvent::sole();
        $this->assertSame([$store->id, 'wix.ecom.v1.order_created', 'order-9'], [$recorded->store_id, $recorded->topic, $recorded->resource_id]);
    }

    public function test_every_connected_store_has_its_new_orders_imported_each_minute(): void
    {
        $store = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        $store->update(['last_sync_at' => now()->subMinutes(5)]);
        Http::fake(['https://shop.test/wp-json/wc/v3/orders*' => Http::response([])]);

        $this->artisan('stores:import-orders')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'orders?') && str_contains($request->url(), 'modified_after='));
        $this->assertTrue($store->fresh()->last_sync_at->isAfter(now()->subMinute()));
    }

    private function store(StorePlatform $platform, ?string $reference, array $credentials): Store
    {
        static $count = 0;
        $count++;

        return Store::create([
            'organization_id' => $this->admin->currentOrganization()->id, 'name' => $platform->label()." Shop {$count}", 'code' => $platform->value."-{$count}",
            'platform' => $platform, 'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
            'external_reference' => $reference, 'credentials' => $credentials,
        ]);
    }

    /**
     * @param  list<string>  $variantIds  in the order of the product's variants
     */
    private function link(Store $store, string $productId, array $variantIds): void
    {
        StoreProductLink::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'product_id' => $this->product->id, 'external_id' => $productId]);

        foreach ($this->product->variants as $index => $variant) {
            SkuMapping::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'product_variant_id' => $variant->id, 'external_id' => $variantIds[$index], 'external_sku' => $variant->sku]);
        }
    }

    private function order(Store $store, string $externalId): Order
    {
        $order = Order::create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'order_number' => '#'.$externalId, 'external_order_id' => $externalId,
            'customer_name' => 'Sara Khan', 'order_status' => 'delivered', 'total_amount' => 1500, 'currency' => 'PKR',
        ]);
        $order->items()->create(['product_variant_id' => $this->product->variants[0]->id, 'external_sku' => 'TEE-M', 'product_name' => 'Classic Tee - M', 'quantity' => 1, 'unit_price' => 1500, 'total_amount' => 1500, 'status' => 'fulfilled']);

        return $order;
    }

    private function completedReturn(Order $order): OrderReturn
    {
        $return = OrderReturn::create([
            'organization_id' => $order->organization_id, 'order_id' => $order->id, 'return_number' => 'RMA-TEST-'.$order->id,
            'status' => 'received', 'reason' => 'Wrong size', 'items_count' => 1,
        ]);
        $return->items()->create(['order_item_id' => $order->items->first()->id, 'product_variant_id' => $this->product->variants[0]->id, 'sku' => 'TEE-M', 'product_name' => 'Classic Tee - M', 'quantity' => 1]);
        $return->update(['status' => 'completed', 'disposition' => 'sellable', 'completed_at' => now()]);

        return $return;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims, \OpenSSLAsymmetricKey $key): string
    {
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode($claims));
        openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.$encode($signature);
    }

    /**
     * OpenCart (oc_) and osCommerce (os_) catalog tables in an in-memory shop database.
     */
    private function shopDatabase(): Connection
    {
        config(['database.connections.shop' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('shop');
        $schema = Schema::connection('shop');

        $schema->create('oc_setting', fn (Blueprint $t) => [$t->integer('store_id'), $t->string('key'), $t->text('value')]);
        $schema->create('oc_language', fn (Blueprint $t) => [$t->increments('language_id'), $t->string('code')]);
        $schema->create('oc_product', fn (Blueprint $t) => [$t->increments('product_id'), $t->string('model'), $t->string('sku'), $t->integer('quantity'), $t->integer('stock_status_id'), $t->decimal('price', 15, 4), $t->boolean('shipping'), $t->boolean('subtract'), $t->integer('minimum'), $t->boolean('status'), $t->date('date_available'), $t->dateTime('date_added'), $t->dateTime('date_modified'), $t->integer('tax_class_id')]);
        $schema->create('oc_product_description', fn (Blueprint $t) => [$t->integer('product_id'), $t->integer('language_id'), $t->string('name'), $t->text('description'), $t->string('tag'), $t->string('meta_title'), $t->string('meta_description'), $t->string('meta_keyword')]);
        $schema->create('oc_product_to_store', fn (Blueprint $t) => [$t->integer('product_id'), $t->integer('store_id')]);
        $schema->create('oc_option', fn (Blueprint $t) => [$t->increments('option_id'), $t->string('type'), $t->integer('sort_order')]);
        $schema->create('oc_option_description', fn (Blueprint $t) => [$t->integer('option_id'), $t->integer('language_id'), $t->string('name')]);
        $schema->create('oc_option_value', fn (Blueprint $t) => [$t->increments('option_value_id'), $t->integer('option_id'), $t->string('image'), $t->integer('sort_order')]);
        $schema->create('oc_option_value_description', fn (Blueprint $t) => [$t->integer('option_value_id'), $t->integer('language_id'), $t->integer('option_id'), $t->string('name')]);
        $schema->create('oc_product_option', fn (Blueprint $t) => [$t->increments('product_option_id'), $t->integer('product_id'), $t->integer('option_id'), $t->text('value'), $t->boolean('required')]);
        $schema->create('oc_product_option_value', fn (Blueprint $t) => [$t->increments('product_option_value_id'), $t->integer('product_option_id'), $t->integer('product_id'), $t->integer('option_id'), $t->integer('option_value_id'), $t->integer('quantity'), $t->boolean('subtract'), $t->decimal('price', 15, 4), $t->string('price_prefix', 1), $t->integer('points'), $t->string('points_prefix', 1), $t->decimal('weight', 15, 8), $t->string('weight_prefix', 1)]);
        $db->table('oc_setting')->insert(['store_id' => 0, 'key' => 'config_language', 'value' => 'en-gb']);
        $db->table('oc_language')->insert(['code' => 'en-gb']);

        $schema->create('os_languages', fn (Blueprint $t) => [$t->integer('languages_id'), $t->string('code')]);
        $schema->create('os_products', fn (Blueprint $t) => [$t->increments('products_id'), $t->integer('products_quantity'), $t->string('products_model'), $t->decimal('products_price', 15, 4), $t->dateTime('products_date_added'), $t->dateTime('products_last_modified')->nullable(), $t->integer('products_status'), $t->integer('products_tax_class_id')]);
        $schema->create('os_products_description', fn (Blueprint $t) => [$t->integer('products_id'), $t->integer('language_id'), $t->string('products_name'), $t->text('products_description')]);
        $db->table('os_languages')->insert(['languages_id' => 1, 'code' => 'en']);

        $this->app->instance(ExternalDatabaseConnector::class, new class($db) extends ExternalDatabaseConnector
        {
            public function __construct(private readonly Connection $shop) {}

            public function connection(Store $store): Connection
            {
                return $this->shop;
            }
        });

        return $db;
    }
}
