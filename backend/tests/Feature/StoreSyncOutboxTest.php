<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\StoreProductLink;
use App\Models\StoreSyncTask;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use App\Services\StoreSyncOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Finovo keeps the connected stores current: stock and shipments go out through the outbox (retried with growing
 * waits), the Orders page pulls new store orders by itself, and an order's Push sends it with its status.
 */
class StoreSyncOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPIFY_URL = 'https://demo-shop.myshopify.com/admin/api/2026-07/graphql.json';

    protected User $admin;

    protected Store $shopify;

    protected Warehouse $warehouse;

    protected ProductVariant $variant;

    /** @var list<array<string, mixed>> */
    protected array $calls = [];

    /** When set, Shopify answers every request with this error. */
    protected ?string $shopifyError = null;

    /** The order's state on the shop, as Shopify reports it. */
    protected array $shopOrder = ['displayFinancialStatus' => 'PENDING', 'cancelledAt' => null];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.api_version' => '2026-07']);
        Http::preventStrayRequests();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $this->shopify = Store::create([
            'organization_id' => $org->id, 'name' => 'Demo Shopify', 'code' => 'demo-shopify', 'platform' => StorePlatform::SHOPIFY,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
            'external_reference' => 'demo-shop.myshopify.com', 'credentials' => ['access_token' => 'shpat_test'],
        ]);
        $this->shopify->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);

        $product = Product::create(['organization_id' => $org->id, 'name' => 'Classic Tee', 'sku' => 'TEE', 'is_active' => true]);
        $this->variant = $product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'price' => 1500]);
        StoreProductLink::create(['organization_id' => $org->id, 'store_id' => $this->shopify->id, 'product_id' => $product->id, 'external_id' => '7001']);
        SkuMapping::create(['organization_id' => $org->id, 'store_id' => $this->shopify->id, 'product_variant_id' => $this->variant->id, 'external_id' => '9001', 'external_sku' => 'TEE-M']);

        $this->fakeShopify();
    }

    public function test_finovo_stock_is_sent_to_stores_that_take_it_from_a_linked_warehouse(): void
    {
        $this->addStock(20);
        app(StoreSyncOutbox::class)->runDue();

        // The item was untracked and not stocked at the location, so Finovo fixes both before setting the count.
        $this->assertNotNull($this->sent('inventoryItemUpdate'));
        $this->assertNotNull($this->sent('inventoryActivate'));
        $set = $this->sent('inventorySetQuantities');
        $this->assertSame(20, $set['variables']['input']['quantities'][0]['quantity']);
        $this->assertNull($set['variables']['input']['quantities'][0]['changeFromQuantity']);
        $this->assertSame('gid://shopify/Location/1', $set['variables']['input']['quantities'][0]['locationId']);
        $this->assertStringContainsString('@idempotent(key: $key)', $set['query']);
        $this->assertSame(StoreSyncTask::STATUS_DONE, StoreSyncTask::sole()->status);
        $this->assertSame('Main warehouse', $this->shopify->fresh()->settings['sync']['shopify_location_name']);
    }

    public function test_a_store_without_a_linked_warehouse_keeps_its_own_stock(): void
    {
        $this->shopify->warehouses()->detach();
        $this->addStock(20);

        $this->assertSame(0, StoreSyncTask::count());
        Http::assertNothingSent();
    }

    public function test_every_stock_change_sends_the_latest_quantity(): void
    {
        $this->addStock(20);
        $outbox = app(StoreSyncOutbox::class);

        $this->assertSame(1, StoreSyncTask::count(), 'one task per store and variant, however many changes');
        $outbox->runDue();
        $this->assertSame(20, $this->sent('inventorySetQuantities')['variables']['input']['quantities'][0]['quantity']);

        app(InventoryEngine::class)->adjustStock($this->shopify->organization_id, $this->warehouse->id, $this->variant->id, 'subtract', 4, 'damaged');
        $outbox->runDue();
        $this->assertSame(16, $this->sent('inventorySetQuantities')['variables']['input']['quantities'][0]['quantity']);
    }

    public function test_a_failed_update_is_retried_with_growing_waits_then_given_up(): void
    {
        $this->addStock(10);
        $this->shopifyError = 'Internal error';
        $outbox = app(StoreSyncOutbox::class);

        $outbox->runDue();
        $task = StoreSyncTask::sole();
        $this->assertSame(StoreSyncTask::STATUS_PENDING, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertTrue($task->available_at->isFuture(), 'waits before the next attempt');

        foreach (range(1, 5) as $attempt) {
            $task->update(['available_at' => now()->subSecond()]);
            $outbox->runDue();
            $task->refresh();
        }

        $this->assertSame(StoreSyncTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('Internal error', $task->last_error);
    }

    public function test_a_dispatched_order_is_fulfilled_on_shopify_with_tracking_and_then_delivered(): void
    {
        $order = $this->shopifyOrder('packed');
        $shipment = Shipment::create([
            'organization_id' => $order->organization_id, 'order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'tracking_number' => 'TCS123', 'carrier' => 'TCS', 'status' => 'pending',
        ]);
        $outbox = app(StoreSyncOutbox::class);

        $shipment->update(['status' => 'shipped', 'shipped_at' => now()]);
        $outbox->runDue();

        $fulfillment = $this->sent('fulfillmentCreate')['variables']['fulfillment'];
        $this->assertSame([['fulfillmentOrderId' => 'gid://shopify/FulfillmentOrder/11']], $fulfillment['lineItemsByFulfillmentOrder']);
        $this->assertSame('TCS123', $fulfillment['trackingInfo']['number']);
        $this->assertSame('TCS', $fulfillment['trackingInfo']['company']);
        $this->assertTrue($fulfillment['notifyCustomer']);
        $this->assertSame('8001', $shipment->fresh()->store_fulfillment_id);

        $shipment->update(['status' => 'delivered']);
        $outbox->runDue();

        $this->assertSame('DELIVERED', $this->sent('fulfillmentEventCreate')['variables']['event']['status']);
        $this->assertSame(1, $this->timesSent('fulfillmentCreate'), 'fulfilled only once');
    }

    public function test_woocommerce_variations_get_their_stock_and_shipped_orders_are_completed(): void
    {
        $woo = $this->wooStore();
        $woo->warehouses()->sync([$this->warehouse->id]);
        StoreProductLink::create(['organization_id' => $woo->organization_id, 'store_id' => $woo->id, 'product_id' => $this->variant->product_id, 'external_id' => '40']);
        SkuMapping::create(['organization_id' => $woo->organization_id, 'store_id' => $woo->id, 'product_variant_id' => $this->variant->id, 'external_id' => '41', 'external_sku' => 'TEE-M']);
        Http::fake(['https://shop.test/*' => Http::response(['id' => 1])]);

        $this->addStock(7);
        app(StoreSyncOutbox::class)->runDue();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/products/40/variations/41'
            && $request['stock_quantity'] === 7
            && $request['manage_stock'] === true);

        $order = Order::create([
            'organization_id' => $woo->organization_id, 'store_id' => $woo->id, 'order_number' => '#77', 'external_order_id' => '77',
            'customer_name' => 'Ali', 'order_status' => 'packed', 'total_amount' => 10, 'currency' => 'PKR',
        ]);
        Shipment::create(['organization_id' => $order->organization_id, 'order_id' => $order->id, 'tracking_number' => 'LEO9', 'carrier' => 'Leopards Courier', 'status' => 'shipped']);
        app(StoreSyncOutbox::class)->runDue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.test/wp-json/wc/v3/orders/77' && $request['status'] === 'completed');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.test/wp-json/wc/v3/orders/77/notes' && str_contains($request['note'], 'LEO9') && $request['customer_note'] === true);

        // A warehouse step: "Processing" with a private note.
        $order->update(['order_status' => 'picking']);
        app(StoreSyncOutbox::class)->runDue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.test/wp-json/wc/v3/orders/77' && $request['status'] === 'processing');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://shop.test/wp-json/wc/v3/orders/77/notes' && $request['note'] === 'Finovo: Picking.' && $request['customer_note'] === false);
    }

    public function test_the_warehouse_step_shows_on_shopify_as_a_tag(): void
    {
        $order = $this->shopifyOrder('allocated');

        $order->update(['order_status' => 'packed']);
        app(StoreSyncOutbox::class)->runDue();

        $this->assertSame(['Finovo: Packed'], $this->sent('tagsAdd')['variables']['tags']);
        $this->assertContains('Finovo: Allocated', $this->sent('tagsRemove')['variables']['tags']);
        $this->assertNotContains('Finovo: Packed', $this->sent('tagsRemove')['variables']['tags']);
    }

    public function test_push_creates_a_finovo_order_on_its_store(): void
    {
        $order = Order::create([
            'organization_id' => $this->shopify->organization_id, 'store_id' => $this->shopify->id, 'order_number' => 'FIN-1001',
            'customer_name' => 'Sara Khan', 'order_status' => 'pending', 'payment_status' => 'paid', 'total_amount' => 1500, 'currency' => 'PKR',
        ]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Classic Tee', 'quantity' => 1, 'unit_price' => 1500, 'total_amount' => 1500, 'status' => 'pending']);

        $this->actingAs($this->admin)->postJson("/api/v1/orders/{$order->id}/push")
            ->assertOk()
            ->assertJsonPath('message', 'Order FIN-1001 was sent to Demo Shopify with its current status (pending).');

        $created = $this->sent('orderCreate')['variables']['order'];
        $this->assertSame('PAID', $created['financialStatus']);
        $this->assertSame('gid://shopify/ProductVariant/9001', $created['lineItems'][0]['variantId']);
        $this->assertSame('5001', $order->fresh()->external_order_id);
    }

    public function test_push_sends_a_shipped_finovo_order_with_its_tracking(): void
    {
        $order = Order::create([
            'organization_id' => $this->shopify->organization_id, 'store_id' => $this->shopify->id, 'order_number' => 'FIN-1002',
            'customer_name' => 'Sara Khan', 'order_status' => 'shipped', 'payment_status' => 'paid', 'total_amount' => 1500, 'currency' => 'PKR',
        ]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Classic Tee', 'quantity' => 1, 'unit_price' => 1500, 'total_amount' => 1500, 'status' => 'pending']);
        Shipment::withoutEvents(fn () => Shipment::create([
            'organization_id' => $order->organization_id, 'order_id' => $order->id, 'tracking_number' => 'TCS9', 'carrier' => 'TCS', 'status' => 'shipped',
        ]));

        $this->actingAs($this->admin)->postJson("/api/v1/orders/{$order->id}/push")->assertOk();

        $this->assertNotNull($this->sent('orderCreate'));
        $this->assertSame('TCS9', $this->sent('fulfillmentCreate')['variables']['fulfillment']['trackingInfo']['number']);
    }

    public function test_orders_already_on_their_store_are_not_pushed_but_kept_in_step(): void
    {
        $order = $this->shopifyOrder('pending', ['payment_status' => 'pending']);

        $this->actingAs($this->admin)->postJson("/api/v1/orders/{$order->id}/push")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Order #1001 is already on Demo Shopify; its status, shipments and cancellation are sent there automatically.');
        $this->assertSame([], $this->calls);

        // A payment recorded in Finovo reaches the shop when the order is saved.
        $this->putJson("/api/v1/orders/{$order->id}", [
            'store_id' => $this->shopify->id, 'customer_name' => 'Sara Khan', 'payment_status' => 'paid', 'currency' => 'PKR',
            'shipping_address' => ['name' => 'Sara Khan', 'address1' => '1 Mall Road', 'city' => 'Lahore', 'country_code' => 'PK'],
        ])->assertOk();
        $this->assertNotNull($this->sent('orderMarkAsPaid'));

        // Cancelling in Finovo cancels on the shop, once.
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertNotNull($this->sent('orderCancel'));
    }

    public function test_push_explains_why_an_order_cannot_be_sent(): void
    {
        $order = $this->shopifyOrder('pending', ['external_order_id' => null]);
        $this->shopifyError = 'Access denied';

        $this->actingAs($this->admin)->postJson("/api/v1/orders/{$order->id}/push")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Order #1001 could not be sent to Demo Shopify: Shopify said: Access denied');

        $manual = Store::create([
            'organization_id' => $this->shopify->organization_id, 'name' => 'Walk-in', 'code' => 'walk-in', 'platform' => StorePlatform::MANUAL,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
        $order->update(['store_id' => $manual->id]);

        $this->postJson("/api/v1/orders/{$order->id}/push")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Walk-in is a Manual Store, which keeps its orders in Finovo only.');
    }

    public function test_the_orders_page_imports_new_store_orders_at_most_once_a_minute(): void
    {
        $woo = $this->wooStore();
        $woo->update(['last_sync_at' => now()->subMinutes(5)]);
        Http::fake(['https://shop.test/wp-json/wc/v3/orders*' => Http::response([])]);

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$woo->id}/sync-orders")
            ->assertOk()
            ->assertJsonPath('data.imported', true);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'orders?') && str_contains($request->url(), 'modified_after='));
        $this->assertTrue($woo->fresh()->last_sync_at->isAfter(now()->subMinute()));

        $this->postJson("/api/v1/stores/{$woo->id}/sync-orders")->assertOk()->assertJsonPath('data.imported', false);
        Http::assertSentCount(1);
    }

    private function addStock(int $quantity): void
    {
        app(InventoryEngine::class)->adjustStock($this->shopify->organization_id, $this->warehouse->id, $this->variant->id, 'add', $quantity, 'opening_stock');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shopifyOrder(string $status, array $attributes = []): Order
    {
        return Order::create([
            'organization_id' => $this->shopify->organization_id, 'store_id' => $this->shopify->id, 'order_number' => '#1001',
            'external_order_id' => '5001', 'customer_name' => 'Sara Khan', 'order_status' => $status, 'total_amount' => 50, 'currency' => 'PKR',
            ...$attributes,
        ]);
    }

    private function wooStore(): Store
    {
        return Store::create([
            'organization_id' => $this->shopify->organization_id, 'name' => 'Woo Shop', 'code' => 'woo', 'platform' => StorePlatform::WOOCOMMERCE,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
            'external_reference' => 'https://shop.test', 'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ]);
    }

    /**
     * @return array<string, mixed>|null the last request to Shopify that ran this operation
     */
    private function sent(string $operation): ?array
    {
        return collect($this->calls)->last(fn (array $call) => str_contains($call['query'], $operation.'('));
    }

    private function timesSent(string $operation): int
    {
        return collect($this->calls)->filter(fn (array $call) => str_contains($call['query'], $operation.'('))->count();
    }

    private function fakeShopify(): void
    {
        Http::fake([self::SHOPIFY_URL => function (Request $request) {
            $query = $request['query'];
            $this->calls[] = ['query' => $query, 'variables' => $request['variables']];

            if ($this->shopifyError) {
                return Http::response(['errors' => [['message' => $this->shopifyError]]]);
            }

            return Http::response(['data' => match (true) {
                str_contains($query, 'locations(') => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Main warehouse', 'isActive' => true]]]],
                str_contains($query, 'productVariant(') => ['productVariant' => ['inventoryItem' => ['id' => 'gid://shopify/InventoryItem/3', 'tracked' => false, 'inventoryLevel' => null]]],
                str_contains($query, 'inventoryItemUpdate(') => ['inventoryItemUpdate' => ['userErrors' => [], 'inventoryItem' => ['id' => 'x']]],
                str_contains($query, 'inventoryActivate(') => ['inventoryActivate' => ['userErrors' => [], 'inventoryLevel' => ['id' => 'x']]],
                str_contains($query, 'inventorySetQuantities(') => ['inventorySetQuantities' => ['userErrors' => [], 'inventoryAdjustmentGroup' => ['reason' => 'correction']]],
                str_contains($query, 'fulfillmentOrders(') => ['order' => ['fulfillmentOrders' => ['nodes' => [
                    ['id' => 'gid://shopify/FulfillmentOrder/11', 'status' => 'OPEN'],
                    ['id' => 'gid://shopify/FulfillmentOrder/12', 'status' => 'CLOSED'],
                ]]]],
                str_contains($query, 'fulfillmentCreate(') => ['fulfillmentCreate' => ['userErrors' => [], 'fulfillment' => ['legacyResourceId' => '8001']]],
                str_contains($query, 'fulfillmentEventCreate(') => ['fulfillmentEventCreate' => ['userErrors' => [], 'fulfillmentEvent' => ['id' => 'e']]],
                str_contains($query, 'orderCreate(') => ['orderCreate' => ['userErrors' => [], 'order' => ['legacyResourceId' => '5001', 'name' => '#1001']]],
                str_contains($query, 'orderUpdate(') => ['orderUpdate' => ['userErrors' => [], 'order' => ['id' => 'x']]],
                str_contains($query, 'orderMarkAsPaid(') => ['orderMarkAsPaid' => ['userErrors' => [], 'order' => ['id' => 'x']]],
                str_contains($query, 'orderCancel(') => ['orderCancel' => ['orderCancelUserErrors' => []]],
                str_contains($query, 'tagsAdd(') => ['tagsAdd' => ['userErrors' => [], 'node' => ['id' => 'x']]],
                str_contains($query, 'tagsRemove(') => ['tagsRemove' => ['userErrors' => [], 'node' => ['id' => 'x']]],
                str_contains($query, 'order(id:') => ['order' => $this->shopOrder],
                default => [],
            }]);
        }]);
    }
}
