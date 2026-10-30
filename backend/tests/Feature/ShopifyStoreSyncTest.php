<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Jobs\SyncStoreJob;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ShopifyStoreSyncTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPHQL_URL = 'https://demo-shop.myshopify.com/admin/api/2026-07/graphql.json';

    protected User $admin;

    protected Store $store;

    protected Warehouse $warehouse;

    /** @var array<string, mixed> */
    protected array $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_version' => '2026-07']);
        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $this->store = Store::create([
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
        $this->store->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);

        $this->order = [
            'legacyResourceId' => '5001',
            'name' => '#1001',
            'createdAt' => '2026-10-19T10:00:00Z',
            'cancelledAt' => null,
            'displayFinancialStatus' => 'PENDING',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'note' => 'Leave at the door',
            'currentTotalPriceSet' => ['shopMoney' => ['amount' => '59.00', 'currencyCode' => 'PKR']],
            'currentSubtotalPriceSet' => ['shopMoney' => ['amount' => '50.00']],
            'totalShippingPriceSet' => ['shopMoney' => ['amount' => '5.00']],
            'currentTotalTaxSet' => ['shopMoney' => ['amount' => '4.00']],
            'paymentGatewayNames' => ['Cash on Delivery (COD)'],
            'tags' => ['vip'],
            'email' => 'sara@example.com',
            'phone' => null,
            'customer' => ['firstName' => 'Sara', 'lastName' => 'Khan', 'email' => 'sara@example.com', 'phone' => '+923001234567'],
            'shippingAddress' => ['name' => 'Sara Khan', 'company' => null, 'address1' => '12 Main St', 'address2' => null, 'city' => 'Karachi', 'province' => 'Sindh', 'zip' => '75500', 'country' => 'Pakistan', 'countryCodeV2' => 'PK', 'phone' => null],
            'lineItems' => ['nodes' => [[
                'name' => 'Classic Tee - M / Red',
                'sku' => 'TEE-M-RED',
                'quantity' => 2,
                'variant' => ['legacyResourceId' => '9001'],
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '25.00']],
                'discountedTotalSet' => ['shopMoney' => ['amount' => '50.00']],
                'image' => ['url' => 'https://cdn.shopify.com/tee-red.jpg'],
                'taxLines' => [['priceSet' => ['shopMoney' => ['amount' => '4.00']]]],
            ]]],
        ];
    }

    public function test_sync_imports_products_variants_stock_and_orders(): void
    {
        $this->fakeShopify();

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertOk()
            ->assertJsonPath('result.products_created', 1)
            ->assertJsonPath('result.variants_synced', 2)
            ->assertJsonPath('result.orders_created', 1)
            ->assertJsonPath('result.opening_stock_lines', 1);

        $product = Product::sole();
        $this->assertSame('Classic Tee', $product->name);
        $this->assertSame('https://cdn.shopify.com/tee.jpg', $product->image_url);
        $this->assertSame('Apparel', $product->category);
        // Everything Shopify sent is kept: all photos, other facts, and the original record for this store.
        $this->assertSame(['https://cdn.shopify.com/tee.jpg', 'https://cdn.shopify.com/tee-back.jpg'], $product->images);
        $this->assertSame('Finovo Apparel', $product->details['Vendor']);
        $this->assertSame(['cotton', 'summer'], $product->details['Tags']);
        $this->assertSame('7001', $product->platform_data[(string) $this->store->id]['legacyResourceId']);
        $this->assertCount(2, $product->platform_data[(string) $this->store->id]['variants']);

        $variant = ProductVariant::where('sku', 'TEE-M-RED')->sole();
        $this->assertSame('Size / Color', $variant->option_name);
        $this->assertSame('M / Red', $variant->option_value);
        $this->assertEquals(25.00, $variant->price);
        $this->assertEquals(10.00, $variant->cost_price);
        $this->assertSame(2, SkuMapping::where('store_id', $this->store->id)->count());

        // A variant without a SKU in Shopify still gets a unique Finovo SKU
        $this->assertTrue(ProductVariant::where('sku', 'CLASSIC-TEE-9002')->exists());

        $this->assertSame(12, InventoryBalance::where('warehouse_id', $this->warehouse->id)->where('product_variant_id', $variant->id)->value('on_hand'));

        $order = Order::with('items')->sole();
        $this->assertSame('#1001', $order->order_number);
        $this->assertSame('Sara Khan', $order->customer_name);
        $this->assertSame('sara@example.com', $order->customer_email);
        $this->assertSame('Karachi', $order->shipping_address['city']);
        $this->assertSame('imported', $order->order_status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
        $this->assertEquals(4.00, $order->items->sole()->tax_amount);
        $this->assertEquals(50.00, $order->subtotal_amount);
        $this->assertEquals(5.00, $order->shipping_amount);
        $this->assertSame('Cash on Delivery (COD)', $order->payment_method);
        $this->assertSame(['vip'], $order->details['Tags']);
        $this->assertSame('5001', $order->platform_data['legacyResourceId']);
        $this->assertSame('https://cdn.shopify.com/tee-red.jpg', $order->items->sole()->image_url);
        $this->assertArrayNotHasKey('platform_data', $order->toArray(), 'the original record stays out of lists');

        $this->assertNotNull($this->store->fresh()->last_sync_at);
        $this->assertDatabaseHas('integration_jobs', ['store_id' => $this->store->id, 'job_type' => 'store_sync', 'status' => 'succeeded']);
    }

    public function test_syncing_again_updates_without_duplicates_or_adding_stock_twice(): void
    {
        $this->fakeShopify();
        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")->assertOk();

        $this->order['displayFinancialStatus'] = 'PAID';
        $this->order['cancelledAt'] = '2026-10-20T09:00:00Z';
        $this->fakeShopify(stock: 50);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertOk()
            ->assertJsonPath('result.products_created', 0)
            ->assertJsonPath('result.orders_updated', 1)
            ->assertJsonPath('result.opening_stock_lines', 0);

        $this->assertSame(1, Product::count());
        $this->assertSame(1, Order::count());
        $this->assertSame('paid', Order::sole()->payment_status);
        $this->assertSame('cancelled', Order::sole()->order_status);
        $this->assertSame(12, InventoryBalance::sole()->on_hand);
    }

    public function test_orders_are_still_imported_when_shopify_withholds_customer_data(): void
    {
        $this->fakeShopify(customerDataApproved: false);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertOk()
            ->assertJsonPath('result.orders_created', 1);

        $this->assertSame('Guest customer', Order::sole()->customer_name);
        $this->assertStringContainsString('Protected customer data', (string) $this->store->fresh()->connection_error);
    }

    public function test_shopify_rate_limits_are_retried(): void
    {
        Sleep::fake();
        $calls = 0;
        $this->fakeShopify(beforeEach: function () use (&$calls) {
            return ++$calls === 1 ? Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]) : null;
        });

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")->assertOk();

        Sleep::assertSleptTimes(1);
        $this->assertSame(1, Product::count());
    }

    public function test_a_rejected_token_marks_the_store_and_returns_the_reason(): void
    {
        Http::fake([self::GRAPHQL_URL => Http::response(['errors' => '[API] Invalid API key or access token'], 401)]);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertUnprocessable()
            ->assertJsonPath('message', "Shopify rejected Finovo's access (HTTP 401). Check the store's permissions or connect it again.");

        $this->assertSame('sync_issues', $this->store->fresh()->sync_status);
        $this->assertDatabaseHas('integration_jobs', ['store_id' => $this->store->id, 'status' => 'failed']);
    }

    public function test_platforms_without_sync_and_unconnected_stores_are_refused(): void
    {
        $this->store->update(['platform' => StorePlatform::MANUAL]);
        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Finovo cannot import products and orders for Manual Store.');

        $this->store->update(['platform' => StorePlatform::SHOPIFY, 'status' => Store::STATUS_SETUP_INCOMPLETE]);
        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Connect the store before syncing it.');
    }

    public function test_the_scheduled_sync_runs_in_the_background(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        $this->artisan('stores:sync')->assertSuccessful();

        Queue::assertPushed(SyncStoreJob::class, fn (SyncStoreJob $job) => $job->store->is($this->store));
    }

    public function test_the_sync_command_runs_every_connected_store(): void
    {
        $this->fakeShopify();

        $this->artisan('stores:sync', ['--now' => true])
            ->expectsOutputToContain('Demo Shopify: 1 products (1 new), 2 variants, 1 orders (1 new)')
            ->assertSuccessful();
    }

    /**
     * Fake the Shopify GraphQL API: one product with two variants and one order.
     */
    private function fakeShopify(int $stock = 12, bool $customerDataApproved = true, ?\Closure $beforeEach = null): void
    {
        Http::fake([self::GRAPHQL_URL => function (Request $request) use ($stock, $customerDataApproved, $beforeEach) {
            if ($beforeEach && ($response = $beforeEach())) {
                return $response;
            }

            $query = $request['query'];

            if (str_contains($query, 'products(')) {
                return Http::response(['data' => ['products' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'nodes' => [[
                        'id' => 'gid://shopify/Product/7001',
                        'legacyResourceId' => '7001',
                        'title' => 'Classic Tee',
                        'handle' => 'classic-tee',
                        'description' => 'Soft cotton tee',
                        'productType' => 'Apparel',
                        'status' => 'ACTIVE',
                        'featuredMedia' => ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/tee.jpg']]],
                        'media' => ['nodes' => [['preview' => ['image' => ['url' => 'https://cdn.shopify.com/tee.jpg']]], ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/tee-back.jpg']]]]],
                        'vendor' => 'Finovo Apparel',
                        'tags' => ['cotton', 'summer'],
                        'totalInventory' => 12,
                        'createdAt' => '2026-10-01T09:00:00Z',
                        'variants' => [
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            'nodes' => [
                                ['legacyResourceId' => '9001', 'sku' => 'TEE-M-RED', 'barcode' => null, 'price' => '25.00', 'inventoryQuantity' => $stock, 'selectedOptions' => [['name' => 'Size', 'value' => 'M'], ['name' => 'Color', 'value' => 'Red']], 'inventoryItem' => ['unitCost' => ['amount' => '10.00']]],
                                ['legacyResourceId' => '9002', 'sku' => '', 'barcode' => null, 'price' => '25.00', 'inventoryQuantity' => 0, 'selectedOptions' => [['name' => 'Size', 'value' => 'L'], ['name' => 'Color', 'value' => 'Red']], 'inventoryItem' => ['unitCost' => null]],
                            ],
                        ],
                    ]],
                ]]]);
            }

            $asksForCustomer = str_contains($query, 'customer {');

            if ($asksForCustomer && ! $customerDataApproved) {
                return Http::response(['errors' => [['message' => 'This app is not approved to access the Customer object. See https://shopify.dev/docs/apps/launch/protected-customer-data for more details.']]]);
            }

            $order = $asksForCustomer
                ? $this->order
                : array_diff_key($this->order, array_flip(['email', 'phone', 'customer', 'shippingAddress']));

            return Http::response(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [$order],
            ]]]);
        }]);
    }
}
