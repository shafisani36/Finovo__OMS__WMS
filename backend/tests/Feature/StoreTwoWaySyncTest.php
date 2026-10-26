<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Models\StoreWebhookEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Products and orders created or changed in Finovo reach the store, and the store's own changes reach Finovo
 * automatically through signed notifications (webhooks).
 */
class StoreTwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPIFY_URL = 'https://demo-shop.myshopify.com/admin/api/2026-07/graphql.json';

    private const SHOPIFY_SECRET = 'shpss_test_secret';

    protected User $admin;

    protected Store $shopify;

    protected Warehouse $warehouse;

    /** @var list<array<string, mixed>> GraphQL requests sent to Shopify (query + variables) */
    protected array $shopifyCalls = [];

    /** Whether the faked Shopify reports order 5001 as cancelled. */
    protected bool $orderCancelledOnShop = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_version' => '2026-07', 'services.shopify.client_secret' => self::SHOPIFY_SECRET]);
        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $this->shopify = Store::create([
            'organization_id' => $org->id,
            'name' => 'Demo Shopify',
            'code' => 'demo-shopify',
            'platform' => StorePlatform::SHOPIFY,
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
            'external_reference' => 'demo-shop.myshopify.com',
            'credentials' => ['access_token' => 'shpat_test'],
        ]);
        $this->shopify->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);
    }

    public function test_a_product_added_in_finovo_is_created_and_then_updated_on_the_shop(): void
    {
        $this->fakeShopify();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/products', [
            ...$this->teePayload(),
            'store_ids' => [$this->shopify->id],
        ])->assertCreated();

        $this->assertStringContainsString('Also created on Demo Shopify.', $response->json('message'));
        $product = Product::with('variants')->sole();
        $this->assertSame('7001', StoreProductLink::where('product_id', $product->id)->value('external_id'));
        $this->assertSame(['9001', '9002'], SkuMapping::where('store_id', $this->shopify->id)->orderBy('external_id')->pluck('external_id')->all());

        $create = $this->lastCall('productSet');
        $this->assertNull($create['variables']['identifier']);
        $this->assertSame([['name' => 'Size', 'values' => [['name' => 'M'], ['name' => 'L']]]], $create['variables']['input']['productOptions']);
        $this->assertSame('TEE-M', $create['variables']['input']['variants'][0]['sku']);

        // Editing sends the change to the same product and variants on the shop.
        $payload = $this->teePayload();
        $payload['name'] = 'Classic Tee 2';
        $payload['variants'][0]['id'] = $product->variants->firstWhere('sku', 'TEE-M')->id;
        $payload['variants'][1]['id'] = $product->variants->firstWhere('sku', 'TEE-L')->id;
        $this->putJson("/api/v1/products/{$product->id}", $payload)->assertOk();

        $update = $this->lastCall('productSet');
        $this->assertSame(['id' => 'gid://shopify/Product/7001'], $update['variables']['identifier']);
        $this->assertSame('gid://shopify/ProductVariant/9001', $update['variables']['input']['variants'][0]['id']);
        $this->assertSame('Classic Tee 2', $update['variables']['input']['title']);
    }

    public function test_a_product_is_saved_in_finovo_even_when_the_shop_refuses_it(): void
    {
        Http::fake([self::SHOPIFY_URL => Http::response(['data' => ['productSet' => ['product' => null, 'userErrors' => [['field' => ['title'], 'message' => 'Title is too long']]]]])]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/products', [...$this->teePayload(), 'store_ids' => [$this->shopify->id]])->assertCreated();

        $this->assertStringContainsString('Demo Shopify could not be created: Shopify said: Title is too long', $response->json('message'));
        $this->assertSame(1, Product::count());
        $this->assertSame(0, StoreProductLink::count());
    }

    public function test_product_sku_rules_and_delete_only_without_history(): void
    {
        $this->fakeShopify();
        $this->actingAs($this->admin)->postJson('/api/v1/products', $this->teePayload())->assertCreated();

        // Another product cannot reuse a SKU, and several variants need different option values.
        $this->postJson('/api/v1/products', [...$this->teePayload(), 'sku' => 'TEE-2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['variants']);
        $this->postJson('/api/v1/products', [
            'name' => 'Mug', 'sku' => 'MUG',
            'variants' => [['sku' => 'MUG-1', 'option_value' => 'White', 'price' => 5], ['sku' => 'MUG-2', 'option_value' => 'white', 'price' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants']);

        $product = Product::sole();
        app(InventoryEngine::class)->adjustStock($product->organization_id, $this->warehouse->id, $product->variants->first()->id, 'add', 5, 'opening_stock');

        $this->deleteJson("/api/v1/products/{$product->id}")->assertUnprocessable();

        $unused = $this->postJson('/api/v1/products', ['name' => 'Cap', 'sku' => 'CAP', 'variants' => [['sku' => 'CAP-1', 'price' => 3]]])->json('data.id');
        $this->deleteJson("/api/v1/products/{$unused}")->assertOk();
        $this->assertNull(Product::find($unused));
    }

    public function test_an_order_created_in_finovo_is_created_on_the_shop_and_kept_in_step(): void
    {
        $this->fakeShopify();
        $variant = $this->linkedVariant();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/orders', [
            'store_id' => $this->shopify->id,
            'customer_name' => 'Sara Khan',
            'customer_email' => 'sara@example.com',
            'shipping_address' => ['name' => 'Sara Khan', 'address1' => '12 Main St', 'city' => 'Karachi', 'country_code' => 'PK'],
            'payment_status' => 'pending',
            'currency' => 'pkr',
            'shipping_amount' => 200,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 1500]],
        ])->assertCreated();

        $order = Order::with('items')->sole();
        $this->assertSame('FIN-1001', $order->order_number);
        $this->assertSame('5001', $order->external_order_id);
        $this->assertSame('PKR', $order->currency);
        $this->assertEquals(3000, $order->subtotal_amount);
        $this->assertEquals(3200, $order->total_amount);
        $this->assertSame($this->admin->id, $order->created_by);
        $this->assertStringContainsString('Also created on Demo Shopify.', $response->json('message'));

        $created = $this->lastCall('orderCreate')['variables']['order'];
        $this->assertSame('gid://shopify/ProductVariant/9001', $created['lineItems'][0]['variantId']);
        $this->assertSame([['key' => 'finovo_order_id', 'value' => (string) $order->id]], $created['customAttributes']);
        $this->assertSame('PK', $created['shippingAddress']['countryCode']);

        // The shop's lines stay as they are; the customer and note can still change and are sent to the shop.
        $edit = ['customer_name' => 'Sara K.', 'payment_status' => 'pending', 'currency' => 'PKR', 'notes' => 'Call first'];
        $this->putJson("/api/v1/orders/{$order->id}", [...$edit, 'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 1500]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
        $this->putJson("/api/v1/orders/{$order->id}", $edit)->assertOk();
        $this->assertSame('Call first', $this->lastCall('orderUpdate')['variables']['input']['note']);

        // An order on the shop is cancelled, not deleted, and the cancellation reaches the shop.
        $this->deleteJson("/api/v1/orders/{$order->id}")->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('gid://shopify/Order/5001', $this->lastCall('orderCancel')['variables']['orderId']);
    }

    public function test_an_order_without_a_store_stays_in_finovo_and_can_be_deleted(): void
    {
        $variant = $this->linkedVariant();

        $id = $this->actingAs($this->admin)->postJson('/api/v1/orders', [
            'customer_name' => 'Walk-in customer',
            'payment_status' => 'paid',
            'currency' => 'PKR',
            'discount_amount' => 100,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 1500]],
        ])->assertCreated()->json('data.id');

        $this->assertEquals(1400, Order::find($id)->total_amount);
        $this->putJson("/api/v1/orders/{$id}", [
            'customer_name' => 'Walk-in customer', 'payment_status' => 'paid', 'currency' => 'PKR',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 3, 'unit_price' => 1000]],
        ])->assertOk();
        $this->assertEquals(3000, Order::find($id)->subtotal_amount);

        $this->deleteJson("/api/v1/orders/{$id}")->assertOk();
        $this->assertNull(Order::find($id));
        Http::assertNothingSent();
    }

    public function test_a_signed_shopify_notification_imports_the_order_once(): void
    {
        $this->fakeShopify();
        $this->linkedVariant();
        $body = json_encode(['id' => 5001, 'name' => '#1001']);

        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", json_decode($body, true), $this->shopifyHeaders($body, 'orders/create', 'delivery-1'))->assertOk();
        // Shopify can deliver the same notification again; it is processed once.
        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", json_decode($body, true), $this->shopifyHeaders($body, 'orders/create', 'delivery-1'))->assertOk();

        $order = Order::sole();
        $this->assertSame('#1001', $order->order_number);
        $this->assertSame('5001', $order->external_order_id);
        $this->assertSame(1, StoreWebhookEvent::where('status', StoreWebhookEvent::STATUS_PROCESSED)->count());

        // A notification with a wrong signature is refused.
        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", ['id' => 5001], [
            'X-Shopify-Topic' => 'orders/create', 'X-Shopify-Hmac-Sha256' => 'forged', 'X-Shopify-Shop-Domain' => 'demo-shop.myshopify.com',
        ])->assertUnauthorized();
    }

    public function test_the_shop_cancelling_an_order_releases_finovo_stock(): void
    {
        $variant = $this->linkedVariant();
        app(InventoryEngine::class)->adjustStock($variant->organization_id, $this->warehouse->id, $variant->id, 'add', 10, 'opening_stock');
        $this->fakeShopify();

        $body = json_encode(['id' => 5001]);
        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", ['id' => 5001], $this->shopifyHeaders($body, 'orders/create', 'd-1'))->assertOk();
        $order = Order::sole();
        $this->actingAs($this->admin)->postJson("/api/v1/orders/{$order->id}/allocate")->assertOk();
        $this->assertSame(2, InventoryBalance::where('product_variant_id', $variant->id)->value('reserved'));

        // Shopify now reports the order as cancelled.
        $this->orderCancelledOnShop = true;
        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", ['id' => 5001], $this->shopifyHeaders($body, 'orders/cancelled', 'd-2'))->assertOk();

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame(0, InventoryBalance::where('product_variant_id', $variant->id)->value('reserved'));
    }

    public function test_an_order_finovo_created_on_the_shop_is_not_imported_twice(): void
    {
        $variant = $this->linkedVariant();
        $order = Order::create([
            'organization_id' => $this->shopify->organization_id, 'store_id' => $this->shopify->id, 'order_number' => 'FIN-1001',
            'customer_name' => 'Sara Khan', 'order_status' => 'pending', 'total_amount' => 50, 'currency' => 'PKR',
        ]);
        $order->items()->create(['product_variant_id' => $variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Tee', 'quantity' => 2, 'unit_price' => 25, 'total_amount' => 50]);
        $this->fakeShopify(finovoOrderId: $order->id);

        $body = json_encode(['id' => 5001]);
        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", ['id' => 5001], $this->shopifyHeaders($body, 'orders/create', 'd-1'))->assertOk();

        $this->assertSame(1, Order::count());
        $this->assertSame('5001', $order->fresh()->external_order_id);
        $this->assertSame('FIN-1001', $order->fresh()->order_number);
    }

    public function test_a_product_deleted_on_the_shop_is_archived_in_finovo(): void
    {
        $variant = $this->linkedVariant();
        $body = json_encode(['id' => 7001]);

        $this->postJson("/api/v1/webhooks/shopify/{$this->shopify->id}", ['id' => 7001], $this->shopifyHeaders($body, 'products/delete', 'd-1'))->assertOk();

        $this->assertFalse($variant->product->fresh()->is_active);
        $this->assertSame(0, SkuMapping::count());
        $this->assertSame(0, StoreProductLink::count());
    }

    public function test_woocommerce_notifications_are_verified_with_the_store_secret(): void
    {
        $store = $this->wooStore();
        Http::fake(['https://shop.test/wp-json/wc/v3/orders/77' => Http::response([
            'id' => 77, 'number' => '77', 'status' => 'processing', 'currency' => 'PKR', 'total' => '1500.00',
            'billing' => ['first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@example.com', 'address_1' => '1 Mall Rd', 'city' => 'Lahore', 'country' => 'PK'],
            'shipping' => [], 'line_items' => [['product_id' => 10, 'variation_id' => 0, 'sku' => 'MUG-1', 'name' => 'Mug', 'quantity' => 1, 'price' => 1500, 'total' => '1500.00', 'subtotal' => '1500.00', 'total_tax' => '0']],
            'date_created_gmt' => '2026-10-27T10:00:00',
        ])]);

        // The check WooCommerce sends when a subscription is created needs only a 200.
        $this->post("/api/v1/webhooks/woocommerce/{$store->id}", ['webhook_id' => 3])->assertOk();

        $body = json_encode(['id' => 77]);
        $headers = [
            'X-WC-Webhook-Topic' => 'order.created',
            'X-WC-Webhook-ID' => '3',
            'X-WC-Webhook-Delivery-ID' => 'abc',
            'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, 'woo-secret', true)),
        ];
        $this->postJson("/api/v1/webhooks/woocommerce/{$store->id}", ['id' => 77], $headers)->assertOk();

        $order = Order::sole();
        $this->assertSame('#77', $order->order_number);
        $this->assertSame('Ali Raza', $order->customer_name);
        $this->assertSame('paid', $order->payment_status);

        $this->postJson("/api/v1/webhooks/woocommerce/{$store->id}", ['id' => 77], [...$headers, 'X-WC-Webhook-Signature' => 'forged'])->assertUnauthorized();
    }

    public function test_a_simple_product_is_created_on_woocommerce(): void
    {
        $store = $this->wooStore();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/categories*' => Http::response([['id' => 15, 'name' => 'Kitchen']]),
            'https://shop.test/wp-json/wc/v3/products' => Http::response(['id' => 321]),
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/products', [
            'name' => 'Ceramic Mug', 'sku' => 'MUG', 'category' => 'Kitchen',
            'variants' => [['sku' => 'MUG-1', 'price' => 1500]],
            'store_ids' => [$store->id],
        ])->assertCreated();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products'
            && $request['type'] === 'simple'
            && $request['sku'] === 'MUG-1'
            && $request['regular_price'] === '1500.00'
            && $request['categories'] === [['id' => 15]]);
        $this->assertSame('321', SkuMapping::where('store_id', $store->id)->value('external_id'));
    }

    public function test_automatic_import_is_switched_on_only_with_a_public_address(): void
    {
        config(['services.webhooks.base_url' => 'http://127.0.0.1:8001']);
        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->shopify->id}/webhooks")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Automatic import needs Finovo on a public HTTPS address (set WEBHOOK_BASE_URL). Until then the scheduled sync imports new orders.');

        config(['services.webhooks.base_url' => 'https://api.finovo.test']);
        $this->fakeShopify();
        $this->postJson("/api/v1/stores/{$this->shopify->id}/webhooks")->assertOk();

        $created = collect($this->shopifyCalls)->filter(fn (array $call) => str_contains($call['query'], 'webhookSubscriptionCreate'));
        // ORDERS_CREATE already exists for this address, so only the other topics are added.
        $this->assertCount(6, $created);
        $this->assertSame("https://api.finovo.test/api/v1/webhooks/shopify/{$this->shopify->id}", $created->first()['variables']['subscription']['uri']);
        $this->assertSame('active', $this->shopify->fresh()->settings['webhooks']['status']);
    }

    public function test_sales_staff_can_create_orders_but_not_products(): void
    {
        $sales = User::factory()->create(['status' => 'active', 'role' => UserRole::SALES, 'current_organization_id' => $this->shopify->organization_id]);

        $order = Order::create([
            'organization_id' => $this->shopify->organization_id, 'order_number' => 'FIN-1001',
            'customer_name' => 'X', 'order_status' => 'pending', 'total_amount' => 0, 'currency' => 'PKR',
        ]);

        $this->actingAs($sales)->postJson('/api/v1/products', $this->teePayload())->assertForbidden();
        $this->postJson('/api/v1/orders', ['customer_name' => 'X'])->assertUnprocessable();
        $this->deleteJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function teePayload(): array
    {
        return [
            'name' => 'Classic Tee',
            'sku' => 'TEE',
            'category' => 'Apparel',
            'description' => 'Soft cotton tee',
            'variants' => [
                ['sku' => 'TEE-M', 'option_name' => 'Size', 'option_value' => 'M', 'price' => 1500, 'cost_price' => 600],
                ['sku' => 'TEE-L', 'option_name' => 'Size', 'option_value' => 'L', 'price' => 1600],
            ],
        ];
    }

    /**
     * A Finovo variant already linked to Shopify variant 9001 of product 7001.
     */
    private function linkedVariant(): ProductVariant
    {
        $orgId = $this->shopify->organization_id;
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Classic Tee', 'sku' => 'TEE', 'is_active' => true]);
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'TEE-M', 'option_name' => 'Size', 'option_value' => 'M', 'price' => 1500]);
        StoreProductLink::create(['organization_id' => $orgId, 'store_id' => $this->shopify->id, 'product_id' => $product->id, 'external_id' => '7001']);
        SkuMapping::create(['organization_id' => $orgId, 'store_id' => $this->shopify->id, 'product_variant_id' => $variant->id, 'external_id' => '9001', 'external_sku' => 'TEE-M']);

        return $variant;
    }

    private function wooStore(): Store
    {
        $store = Store::create([
            'organization_id' => $this->shopify->organization_id,
            'name' => 'Woo Shop',
            'code' => 'woo-shop',
            'platform' => StorePlatform::WOOCOMMERCE,
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
            'external_reference' => 'https://shop.test',
            'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ]);
        $store->forceFill(['webhook_secret' => 'woo-secret'])->save();

        return $store;
    }

    /**
     * @return array<string, string>
     */
    private function shopifyHeaders(string $body, string $topic, string $deliveryId): array
    {
        return [
            'X-Shopify-Topic' => $topic,
            'X-Shopify-Webhook-Id' => $deliveryId,
            'X-Shopify-Shop-Domain' => 'demo-shop.myshopify.com',
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, self::SHOPIFY_SECRET, true)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastCall(string $operation): array
    {
        return collect($this->shopifyCalls)->last(fn (array $call) => str_contains($call['query'], $operation.'('));
    }

    /**
     * Fake the Shopify GraphQL API for the writes and single reads used here.
     */
    private function fakeShopify(?int $finovoOrderId = null): void
    {
        $this->shopifyCalls = [];

        Http::fake([self::SHOPIFY_URL => function (Request $request) use ($finovoOrderId) {
            $query = $request['query'];
            $this->shopifyCalls[] = ['query' => $query, 'variables' => $request['variables']];

            return Http::response(['data' => match (true) {
                str_contains($query, 'productSet(') => ['productSet' => ['userErrors' => [], 'product' => ['legacyResourceId' => '7001', 'variants' => ['nodes' => [
                    ['legacyResourceId' => '9001', 'sku' => 'TEE-M'],
                    ['legacyResourceId' => '9002', 'sku' => 'TEE-L'],
                ]]]]],
                str_contains($query, 'orderCreate(') => ['orderCreate' => ['userErrors' => [], 'order' => ['legacyResourceId' => '5001', 'name' => '#1001']]],
                str_contains($query, 'orderUpdate(') => ['orderUpdate' => ['userErrors' => [], 'order' => ['id' => 'gid://shopify/Order/5001']]],
                str_contains($query, 'orderCancel(') => ['orderCancel' => ['orderCancelUserErrors' => []]],
                str_contains($query, 'webhookSubscriptions(') => ['webhookSubscriptions' => ['nodes' => [
                    ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE', 'uri' => "https://api.finovo.test/api/v1/webhooks/shopify/{$this->shopify->id}"],
                ]]],
                str_contains($query, 'webhookSubscriptionCreate(') => ['webhookSubscriptionCreate' => ['userErrors' => [], 'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/2']]],
                str_contains($query, 'order(id:') => ['order' => [
                    'legacyResourceId' => '5001',
                    'name' => '#1001',
                    'createdAt' => '2026-10-27T10:00:00Z',
                    'cancelledAt' => $this->orderCancelledOnShop ? '2026-10-27T11:00:00Z' : null,
                    'displayFinancialStatus' => 'PENDING',
                    'displayFulfillmentStatus' => 'UNFULFILLED',
                    'customAttributes' => $finovoOrderId ? [['key' => 'finovo_order_id', 'value' => (string) $finovoOrderId]] : [],
                    'currentTotalPriceSet' => ['shopMoney' => ['amount' => '3000.00', 'currencyCode' => 'PKR']],
                    'email' => 'sara@example.com',
                    'customer' => ['firstName' => 'Sara', 'lastName' => 'Khan'],
                    'lineItems' => ['nodes' => [[
                        'name' => 'Classic Tee - M', 'sku' => 'TEE-M', 'quantity' => 2, 'variant' => ['legacyResourceId' => '9001'],
                        'originalUnitPriceSet' => ['shopMoney' => ['amount' => '1500.00']],
                        'discountedTotalSet' => ['shopMoney' => ['amount' => '3000.00']],
                    ]]],
                ]],
                default => [],
            }]);
        }]);
    }
}
