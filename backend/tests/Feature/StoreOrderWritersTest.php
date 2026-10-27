<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Integrations\Push\BigCommerceWriter;
use App\Integrations\Push\EbayWriter;
use App\Integrations\Push\LinkedVariant;
use App\Integrations\Push\MagentoWriter;
use App\Integrations\Push\OpenCartWriter;
use App\Integrations\Push\OsCommerceWriter;
use App\Integrations\Push\PrestaShopWriter;
use App\Integrations\Push\WixWriter;
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
use App\Services\StoreSyncOutbox;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Every platform shows Finovo's orders, warehouse steps, shipments and stock: each writer against the API
 * shapes (or shop tables) its platform uses.
 */
class StoreOrderWritersTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Classic Tee', 'sku' => 'TEE', 'is_active' => true]);
        $this->variant = $product->variants()->create(['organization_id' => $org->id, 'sku' => 'TEE-M', 'price' => 1500]);
    }

    public function test_a_warehouse_step_is_queued_for_the_store_and_shown_there(): void
    {
        $store = $this->store(StorePlatform::BIGCOMMERCE, 'abc123', ['access_token' => 'bc']);
        $order = $this->order($store, ['external_order_id' => '100', 'order_status' => 'pending']);
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v2/orders/100' => fn (Request $request) => Http::response($request->method() === 'GET' ? ['id' => 100, 'status_id' => 11] : ['id' => 100]),
        ]);

        $order->update(['order_status' => 'packed']);
        $this->assertSame(StoreSyncTask::TYPE_STATUS, StoreSyncTask::sole()->type);
        app(StoreSyncOutbox::class)->runDue();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['status_id'] === 9);
        $this->assertSame(StoreSyncTask::STATUS_DONE, StoreSyncTask::sole()->status);
    }

    public function test_bigcommerce_orders_shipments_and_stock(): void
    {
        $store = $this->store(StorePlatform::BIGCOMMERCE, 'abc123', ['access_token' => 'bc']);
        $this->link($store, '77', '88');
        $order = $this->order($store);
        $order->items()->create(['product_variant_id' => null, 'external_sku' => 'GIFT', 'product_name' => 'Gift wrap', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100, 'status' => 'pending']);
        $bc = 'api.bigcommerce.com/stores/abc123/';
        Http::fake([
            $bc.'v2/orders/100/shipping_addresses' => Http::response([['id' => 5]]),
            $bc.'v2/orders/100/products*' => Http::response([['id' => 31, 'quantity' => 2], ['id' => 32, 'quantity' => 1]]),
            $bc.'v2/orders/100/shipments' => Http::response(['id' => 9], 201),
            $bc.'v2/orders/100' => fn (Request $request) => Http::response($request->method() === 'GET' ? ['id' => 100, 'status_id' => 9] : ['id' => 100]),
            $bc.'v2/orders' => Http::response(['id' => 100], 201),
            $bc.'v3/catalog/products/77/variants/88' => Http::response(['data' => []]),
            $bc.'v3/catalog/products/77*' => fn (Request $request) => Http::response(['data' => ['inventory_tracking' => 'none']]),
        ]);
        $writer = app(BigCommerceWriter::class);

        $this->assertSame('100', $writer->createOrder($store, $order));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://'.$bc.'v2/orders'
            && $request['products'][0] === ['product_id' => 77, 'variant_id' => 88, 'quantity' => 2, 'price_inc_tax' => '1500.00', 'price_ex_tax' => '1500.00']
            && $request['products'][1]['name'] === 'Gift wrap'
            && $request['billing_address']['email'] === 'sara@example.com'
            && $request['shipping_addresses'][0]['country_iso2'] === 'PK'
            && $request['status_id'] === 7);

        $order->update(['external_order_id' => '100']);
        $shipment = $this->shipment($order);
        $this->assertSame('9', $writer->fulfill($store, $order, $shipment));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/shipments')
            && $request['order_address_id'] === 5 && $request['tracking_number'] === 'TCS123'
            && $request['items'] === [['order_product_id' => 31, 'quantity' => 2], ['order_product_id' => 32, 'quantity' => 1]]);

        $writer->reportDelivered($store, $order, $shipment);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === 'https://'.$bc.'v2/orders/100' && $request['status_id'] === 10);

        $writer->setStock($store, new LinkedVariant('88', '77', 'TEE-M'), 6);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), 'products/77') && $request['inventory_tracking'] === 'variant');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), 'variants/88') && $request['inventory_level'] === 6);
    }

    public function test_magento_orders_are_placed_like_a_checkout_and_followed_by_comments_and_shipments(): void
    {
        $store = $this->store(StorePlatform::MAGENTO, 'https://magento.test', ['access_token' => 'mg']);
        $this->link($store, '1', '2', 'TEE-M-MG');
        $order = $this->order($store, ['payment_status' => 'paid']);
        $api = 'https://magento.test/rest/V1/';
        Http::fake([
            $api.'guest-carts/cart-1/items' => Http::response(['item_id' => 1]),
            $api.'guest-carts/cart-1/estimate-shipping-methods' => Http::response([['carrier_code' => 'flatrate', 'method_code' => 'flatrate', 'available' => true]]),
            $api.'guest-carts/cart-1/shipping-information' => Http::response(['payment_methods' => [['code' => 'checkmo'], ['code' => 'cashondelivery']]]),
            $api.'guest-carts/cart-1/order' => Http::response('55'),
            $api.'guest-carts' => Http::response('"cart-1"'),
            $api.'order/55/invoice' => Http::response('7'),
            $api.'order/55/ship' => Http::response('12'),
            $api.'orders/55/comments' => Http::response('true'),
            $api.'orders/55/cancel' => Http::response('true'),
            $api.'orders/55' => Http::response(['entity_id' => 55, 'state' => 'processing', 'status' => 'processing', 'items' => [['item_id' => 1, 'qty_ordered' => 2, 'qty_shipped' => 0]]]),
            $api.'stockItems/TEE-M-MG' => Http::response(['item_id' => 41, 'qty' => 3]),
            $api.'products/TEE-M-MG/stockItems/41' => Http::response('41'),
        ]);
        $writer = app(MagentoWriter::class);

        $this->assertSame('55', $writer->createOrder($store, $order));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'cart-1/items') && $request['cartItem']['sku'] === 'TEE-M-MG' && $request['cartItem']['qty'] === 2);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'cart-1/order') && $request['paymentMethod']['method'] === 'cashondelivery');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'order/55/invoice') && $request['capture'] === false);

        $order->update(['external_order_id' => '55', 'order_status' => 'picking']);
        $writer->updateStatus($store, $order);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'orders/55/comments')
            && $request['statusHistory']['comment'] === 'Finovo: Picking.' && $request['statusHistory']['status'] === 'processing');

        $this->assertSame('12', $writer->fulfill($store, $order, $this->shipment($order)));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'order/55/ship') && $request['tracks'][0]['track_number'] === 'TCS123' && $request['tracks'][0]['title'] === 'TCS');

        $writer->setStock($store, new LinkedVariant('2', '1', 'TEE-M-MG'), 4);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'stockItems/41') && $request['stockItem'] === ['qty' => 4, 'is_in_stock' => true, 'manage_stock' => true]);

        $writer->cancelOrder($store, $order);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'orders/55/cancel'));
    }

    public function test_wix_orders_fulfillments_and_inventory(): void
    {
        $store = $this->store(StorePlatform::WIX, 'site-1', ['api_key' => 'IST.key']);
        $this->link($store, 'prod-1', 'var-1');
        $order = $this->order($store);
        $wix = 'https://www.wixapis.com/';
        Http::fake([
            $wix.'ecom/v1/orders/order-1' => Http::response(['order' => ['id' => 'order-1', 'status' => 'APPROVED', 'fulfillmentStatus' => 'NOT_FULFILLED', 'lineItems' => [['id' => 'line-1', 'quantity' => 2]]]]),
            $wix.'ecom/v1/orders/order-1/cancel' => Http::response(['order' => ['id' => 'order-1']]),
            $wix.'ecom/v1/orders' => Http::response(['order' => ['id' => 'order-1']]),
            $wix.'ecom/v1/fulfillments/orders/order-1/create-fulfillment' => Http::response(['fulfillmentId' => 'ful-1']),
            $wix.'stores/v3/inventory-items/search' => Http::response(['inventoryItems' => [['id' => 'inv-1', 'revision' => '4', 'variantId' => 'var-1']]]),
            $wix.'stores/v3/inventory-items/inv-1' => Http::response(['inventoryItem' => ['id' => 'inv-1']]),
        ]);
        $writer = app(WixWriter::class);

        $this->assertSame('order-1', $writer->createOrder($store, $order));
        Http::assertSent(fn (Request $request) => $request->url() === $wix.'ecom/v1/orders'
            && $request['order']['lineItems'][0]['catalogReference'] === ['catalogItemId' => 'prod-1', 'appId' => '215238eb-22a5-4c36-9e7b-e7c08025e04e', 'options' => ['variantId' => 'var-1']]
            && $request['order']['buyerInfo']['email'] === 'sara@example.com'
            && $request['order']['priceSummary']['total']['amount'] === '3000.00'
            && $request['order']['channelInfo']['externalOrderId'] === 'FIN-1001');

        $order->update(['external_order_id' => 'order-1']);
        $this->assertSame('ful-1', $writer->fulfill($store, $order, $this->shipment($order)));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'create-fulfillment')
            && $request['fulfillment']['lineItems'] === [['id' => 'line-1']] && $request['fulfillment']['trackingInfo']['trackingNumber'] === 'TCS123');

        $writer->setStock($store, new LinkedVariant('var-1', 'prod-1', 'TEE-M'), 9);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['inventoryItem'] === ['id' => 'inv-1', 'revision' => '4', 'quantity' => 9]);

        $writer->cancelOrder($store, $order);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'order-1/cancel') && $request['restockAllItems'] === false);
    }

    public function test_ebay_takes_shipments_cancellations_and_quantities_but_not_new_orders(): void
    {
        config(['services.ebay.environment' => 'sandbox']);
        $store = $this->store(StorePlatform::EBAY, null, ['refresh_token' => 'rt', 'access_token' => 'at', 'access_token_expires_at' => time() + 3600]);
        $order = $this->order($store, ['external_order_id' => '12-34567-89012', 'payment_status' => 'paid']);
        $ebay = 'https://api.sandbox.ebay.com/';
        Http::fake([
            $ebay.'sell/fulfillment/v1/order/12-34567-89012/shipping_fulfillment' => Http::response([], 201, ['Location' => $ebay.'sell/fulfillment/v1/order/12-34567-89012/shipping_fulfillment/FUL-9']),
            $ebay.'sell/fulfillment/v1/order/12-34567-89012' => Http::response(['orderId' => '12-34567-89012', 'orderPaymentStatus' => 'PAID', 'orderFulfillmentStatus' => 'NOT_STARTED', 'lineItems' => [['lineItemId' => 'L1', 'quantity' => 2]], 'paymentSummary' => ['payments' => [['paymentDate' => '2026-10-20T10:00:00.000Z']]]]),
            $ebay.'sell/inventory/v1/bulk_update_price_quantity' => Http::response(['responses' => [['statusCode' => 200, 'sku' => 'TEE-M']]]),
            $ebay.'post-order/v2/cancellation' => Http::response(['cancelId' => '5'], 201),
        ]);
        $writer = app(EbayWriter::class);

        try {
            $writer->createOrder($store, $order);
            $this->fail('eBay orders cannot be created by the seller.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('placed by buyers on eBay', $e->getMessage());
        }

        $this->assertSame('FUL-9', $writer->fulfill($store, $order, $this->shipment($order)));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/shipping_fulfillment')
            && $request['lineItems'] === [['lineItemId' => 'L1', 'quantity' => 2]] && $request['trackingNumber'] === 'TCS123' && $request['shippingCarrierCode'] === 'TCS');

        $writer->setStock($store, new LinkedVariant('TEE-M', 'TEE-M', 'TEE-M'), 3);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'bulk_update_price_quantity') && $request['requests'][0]['shipToLocationAvailability']['quantity'] === 3);

        $writer->cancelOrder($store, $order);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'post-order/v2/cancellation')
            && $request->header('Authorization')[0] === 'IAF at' && $request['buyerPaid'] === true && $request['cancelReason'] === 'OUT_OF_STOCK_OR_CANNOT_FULFILL');
    }

    public function test_prestashop_orders_states_tracking_and_stock(): void
    {
        $store = $this->store(StorePlatform::PRESTASHOP, 'https://presta.test', ['webservice_key' => 'WSKEY']);
        $this->link($store, '12', '12-34');
        $order = $this->order($store);
        $lang = fn (string $text) => [['id' => '1', 'value' => $text]];
        $currentState = 0;
        Http::fake(function (Request $request) use ($lang, &$currentState) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $created = fn (string $resource, int $id) => Http::response([$resource => ['id' => $id]], 201);

            return match (true) {
                $request->method() === 'POST' && $path === '/api/customers' => $created('customer', 3),
                $request->method() === 'POST' && $path === '/api/addresses' => $created('address', 4),
                $request->method() === 'POST' && $path === '/api/carts' => $created('cart', 5),
                $request->method() === 'POST' && $path === '/api/orders' => (function () use (&$currentState) {
                    $currentState = 11; // PrestaShop opens webservice orders as "Remote payment accepted"

                    return Http::response(['order' => ['id' => 6]], 201);
                })(),
                $request->method() === 'POST' && $path === '/api/order_histories' => (function () use ($request, &$currentState) {
                    $currentState = (int) simplexml_load_string($request->body())->order_history->id_order_state;

                    return Http::response(['order_history' => ['id' => 1]], 201);
                })(),
                $request->method() === 'PUT' => Http::response([]),
                $path === '/api/languages' => Http::response(['languages' => [['id' => 1]]]),
                $path === '/api/customers' => Http::response(['customers' => []]),
                $path === '/api/countries' => Http::response(['countries' => [['id' => 9]]]),
                $path === '/api/currencies' => Http::response(['currencies' => [['id' => 2]]]),
                $path === '/api/carriers' => Http::response(['carriers' => [['id' => 7]]]),
                $path === '/api/order_states' => Http::response(['order_states' => [
                    ['id' => 3, 'paid' => '1', 'name' => $lang('Processing in progress'), 'template' => $lang('preparation')],
                    ['id' => 4, 'paid' => '1', 'name' => $lang('Shipped'), 'template' => $lang('shipped')],
                    ['id' => 5, 'paid' => '1', 'name' => $lang('Delivered'), 'template' => $lang('')],
                    ['id' => 6, 'paid' => '0', 'name' => $lang('Canceled'), 'template' => $lang('order_canceled')],
                    ['id' => 13, 'paid' => '0', 'name' => $lang('Awaiting Cash On Delivery validation'), 'template' => $lang('cashondelivery')],
                ]]),
                $path === '/api/orders/6' => Http::response(['order' => ['id' => 6, 'current_state' => $currentState, 'id_address_delivery' => 4]]),
                $path === '/api/order_carriers' => Http::response(['order_carriers' => [['id' => 8, 'id_order' => 6, 'id_carrier' => 7, 'tracking_number' => '']]]),
                $path === '/api/order_carriers/8' => Http::response(['order_carrier' => ['id' => 8, 'id_order' => 6, 'id_carrier' => 7, 'tracking_number' => '']]),
                $path === '/api/stock_availables' => Http::response(['stock_availables' => [['id' => 21, 'id_product' => 12, 'id_product_attribute' => 34, 'quantity' => 2]]]),
                $path === '/api/stock_availables/21' => Http::response(['stock_available' => ['id' => 21, 'id_product' => 12, 'id_product_attribute' => 34, 'id_shop' => 1, 'quantity' => 2, 'depends_on_stock' => 0, 'out_of_stock' => 2]]),
            };
        });
        $writer = app(PrestaShopWriter::class);
        $xml = fn (Request $request) => simplexml_load_string($request->body());

        $this->assertSame('6', $writer->createOrder($store, $order));
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/carts')
            && (string) $xml($request)->cart->associations->cart_rows->cart_row->id_product_attribute === '34'
            && (string) $xml($request)->cart->associations->cart_rows->cart_row->quantity === '2');
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders') && $request->method() === 'POST' && (string) $xml($request)->order->module === 'finovo');
        $this->assertSame(13, $currentState, 'an unpaid order waits for payment');
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('WSKEY:')));

        $order->update(['external_order_id' => '6', 'order_status' => 'packed']);
        $writer->updateStatus($store, $order);
        $this->assertSame(3, $currentState);

        $writer->fulfill($store, $order, $this->shipment($order));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/order_carriers/8')
            && (string) $xml($request)->order_carrier->tracking_number === 'TCS123' && (string) $xml($request)->order_carrier->id_carrier === '7');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'order_histories') && str_contains($request->url(), 'sendemail=1'));
        $this->assertSame(4, $currentState);

        $writer->setStock($store, new LinkedVariant('12-34', '12', 'TEE-M'), 5);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/stock_availables/21')
            && (string) $xml($request)->stock_available->quantity === '5' && (string) $xml($request)->stock_available->out_of_stock === '2');
    }

    public function test_opencart_module_receives_statuses_orders_and_stock(): void
    {
        $store = $this->store(StorePlatform::OPENCART, 'https://oc.test', ['api_key' => 'key', 'api_secret' => 'secret']);
        $this->link($store, '50', '50-7');
        $order = $this->order($store, ['payment_status' => 'paid']);
        $url = 'https://oc.test/index.php?route=extension/finovo/api/';
        Http::fake([
            $url.'order_statuses' => Http::response(['rows' => [['order_status_id' => 1, 'name' => 'Pending'], ['order_status_id' => 2, 'name' => 'Processing'], ['order_status_id' => 3, 'name' => 'Shipped'], ['order_status_id' => 5, 'name' => 'Complete'], ['order_status_id' => 7, 'name' => 'Canceled']]]),
            $url.'create_order' => Http::response(['order_id' => 81]),
            $url.'order_status' => Http::response(['order_status_id' => 2]),
            $url.'add_order_history' => Http::response(['status' => 'ok']),
            $url.'set_stock' => Http::response(['status' => 'ok']),
        ]);
        $writer = app(OpenCartWriter::class);

        $this->assertSame('81', $writer->createOrder($store, $order));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'create_order')
            && $request['order_status_id'] === 2
            && $request['order']['products'][0]['product_option_value_id'] === 7
            && $request['order']['shipping']['country_code'] === 'PK'
            && $request->hasHeader('X-Bridge-Signature'));

        $order->update(['external_order_id' => '81']);
        $writer->fulfill($store, $order, $this->shipment($order));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'add_order_history')
            && $request['order_status_id'] === 3 && $request['notify'] === true && str_contains($request['comment'], 'TCS123'));

        $writer->setStock($store, new LinkedVariant('50-7', '50', null), 8);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'set_stock') && $request['product_option_value_id'] === 7 && $request['quantity'] === 8);
    }

    public function test_an_old_opencart_module_asks_to_be_updated(): void
    {
        $store = $this->store(StorePlatform::OPENCART, 'https://oc.test', ['api_key' => 'key', 'api_secret' => 'secret']);
        Http::fake(['https://oc.test/*' => Http::response('Not found', 404)]);

        $this->expectExceptionMessage('older version that can only read');
        app(OpenCartWriter::class)->setStock($store, new LinkedVariant('50', '50', null), 1);
    }

    public function test_opencart_and_oscommerce_databases_get_orders_history_and_stock(): void
    {
        $db = $this->shopDatabase();
        $opencart = $this->store(StorePlatform::OPENCART, null, ['db_name' => 'shop', 'table_prefix' => 'oc_', 'site_url' => 'https://oc.test']);
        $oscommerce = $this->store(StorePlatform::OSCOMMERCE, null, ['db_name' => 'shop', 'table_prefix' => 'os_']);
        $this->link($opencart, '50', '50');
        $this->link($oscommerce, '60', '60');

        // OpenCart: order, lines, totals and its first history line in the shop's default currency.
        $ocOrder = $this->order($opencart, ['payment_status' => 'paid', 'currency' => 'PKR']);
        $ocId = (int) app(OpenCartWriter::class)->createOrder($opencart, $ocOrder);
        $row = $db->table('oc_order')->where('order_id', $ocId)->first();
        $this->assertSame('Sara', $row->firstname);
        $this->assertSame(2, (int) $row->order_status_id);
        $this->assertSame('Pakistan', $row->shipping_country);
        $this->assertEqualsWithDelta(3000 / 280, (float) $row->total, 0.01);
        $this->assertSame(1, $db->table('oc_order_product')->where('order_id', $ocId)->count());
        $this->assertSame(['sub_total', 'total'], $db->table('oc_order_total')->where('order_id', $ocId)->orderBy('sort_order')->pluck('code')->all());

        $ocOrder->update(['external_order_id' => (string) $ocId, 'order_status' => 'picked']);
        app(OpenCartWriter::class)->updateStatus($opencart, $ocOrder);
        $this->assertSame('Finovo: Picked.', $db->table('oc_order_history')->where('order_id', $ocId)->orderByDesc('order_history_id')->value('comment'));
        app(OpenCartWriter::class)->setStock($opencart, new LinkedVariant('50', '50', null), 11);
        $this->assertSame(11, (int) $db->table('oc_product')->where('product_id', 50)->value('quantity'));

        // osCommerce: the same through its own tables.
        $osOrder = $this->order($oscommerce);
        $osId = (int) app(OsCommerceWriter::class)->createOrder($oscommerce, $osOrder);
        $this->assertSame(1, (int) $db->table('os_orders')->where('orders_id', $osId)->value('orders_status'));
        $this->assertSame(60, (int) $db->table('os_orders_products')->where('orders_id', $osId)->value('products_id'));

        $osOrder->update(['external_order_id' => (string) $osId]);
        app(OsCommerceWriter::class)->fulfill($oscommerce, $osOrder, $this->shipment($osOrder));
        $this->assertSame(3, (int) $db->table('os_orders')->where('orders_id', $osId)->value('orders_status'));
        $this->assertStringContainsString('TCS123', $db->table('os_orders_status_history')->where('orders_id', $osId)->orderByDesc('orders_status_history_id')->value('comments'));

        app(OsCommerceWriter::class)->cancelOrder($oscommerce, $osOrder);
        $this->assertSame(4, (int) $db->table('os_orders')->where('orders_id', $osId)->value('orders_status'));
        app(OsCommerceWriter::class)->setStock($oscommerce, new LinkedVariant('60', '60', null), 2);
        $this->assertSame(2, (int) $db->table('os_products')->where('products_id', 60)->value('products_quantity'));
    }

    private function store(StorePlatform $platform, ?string $reference, array $credentials): Store
    {
        return Store::create([
            'organization_id' => $this->admin->currentOrganization()->id,
            'name' => $platform->label().' Shop',
            'code' => $platform->value.'-shop',
            'platform' => $platform,
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
            'external_reference' => $reference,
            'credentials' => $credentials,
        ]);
    }

    private function link(Store $store, string $productId, string $variantId, ?string $sku = null): void
    {
        StoreProductLink::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'product_id' => $this->variant->product_id, 'external_id' => $productId]);
        SkuMapping::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'product_variant_id' => $this->variant->id, 'external_id' => $variantId, 'external_sku' => $sku ?? 'TEE-M']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(Store $store, array $attributes = []): Order
    {
        $order = Order::create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'order_number' => 'FIN-1001',
            'customer_name' => 'Sara Khan', 'customer_email' => 'sara@example.com', 'customer_phone' => '03001234567',
            'shipping_address' => ['name' => 'Sara Khan', 'address1' => '12 Mall Road', 'city' => 'Lahore', 'zip' => '54000', 'country_code' => 'PK', 'country' => 'Pakistan'],
            'order_status' => 'pending', 'payment_status' => 'pending', 'subtotal_amount' => 3000, 'total_amount' => 3000, 'currency' => 'PKR',
            ...$attributes,
        ]);
        $order->items()->create(['product_variant_id' => $this->variant->id, 'external_sku' => 'TEE-M', 'product_name' => 'Classic Tee - M', 'quantity' => 2, 'unit_price' => 1500, 'total_amount' => 3000, 'status' => 'pending']);

        return $order;
    }

    private function shipment(Order $order): Shipment
    {
        return Shipment::withoutEvents(fn () => Shipment::create([
            'organization_id' => $order->organization_id, 'order_id' => $order->id, 'tracking_number' => 'TCS123', 'carrier' => 'TCS', 'status' => 'shipped', 'shipped_at' => now(),
        ]));
    }

    /**
     * A shop database with the OpenCart (oc_) and osCommerce (os_) tables the writers use.
     */
    private function shopDatabase(): Connection
    {
        config(['database.connections.shop' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('shop');
        $schema = Schema::connection('shop');

        $schema->create('oc_setting', fn (Blueprint $t) => [$t->integer('store_id'), $t->string('key'), $t->text('value')]);
        $schema->create('oc_language', fn (Blueprint $t) => [$t->increments('language_id'), $t->string('code')]);
        $schema->create('oc_currency', fn (Blueprint $t) => [$t->increments('currency_id'), $t->string('code'), $t->float('value')]);
        $schema->create('oc_country', fn (Blueprint $t) => [$t->increments('country_id'), $t->string('name'), $t->string('iso_code_2'), $t->text('address_format')]);
        $schema->create('oc_zone', fn (Blueprint $t) => [$t->increments('zone_id'), $t->integer('country_id'), $t->string('name'), $t->string('code')]);
        $schema->create('oc_order_status', fn (Blueprint $t) => [$t->integer('order_status_id'), $t->integer('language_id'), $t->string('name')]);
        $schema->create('oc_order', function (Blueprint $t) {
            $t->increments('order_id');
            foreach (['invoice_prefix', 'store_name', 'store_url', 'firstname', 'lastname', 'email', 'telephone', 'custom_field', 'payment_method', 'payment_code', 'shipping_method', 'shipping_code', 'comment', 'tracking', 'currency_code', 'ip', 'forwarded_ip', 'user_agent', 'accept_language'] as $column) {
                $t->string($column);
            }
            foreach (['payment', 'shipping'] as $type) {
                foreach (['firstname', 'lastname', 'company', 'address_1', 'address_2', 'city', 'postcode', 'country', 'zone', 'address_format', 'custom_field'] as $column) {
                    $t->string("{$type}_{$column}");
                }
                $t->integer("{$type}_country_id");
                $t->integer("{$type}_zone_id");
            }
            foreach (['invoice_no', 'store_id', 'customer_id', 'customer_group_id', 'order_status_id', 'affiliate_id', 'marketing_id', 'language_id', 'currency_id'] as $column) {
                $t->integer($column);
            }
            $t->decimal('total', 15, 4);
            $t->decimal('commission', 15, 4);
            $t->decimal('currency_value', 15, 8);
            $t->dateTime('date_added');
            $t->dateTime('date_modified');
        });
        $schema->create('oc_order_product', fn (Blueprint $t) => [$t->increments('order_product_id'), $t->integer('order_id'), $t->integer('product_id'), $t->string('name'), $t->string('model'), $t->integer('quantity'), $t->decimal('price', 15, 4), $t->decimal('total', 15, 4), $t->decimal('tax', 15, 4), $t->integer('reward')]);
        $schema->create('oc_order_option', fn (Blueprint $t) => [$t->increments('order_option_id'), $t->integer('order_id'), $t->integer('order_product_id'), $t->integer('product_option_id'), $t->integer('product_option_value_id'), $t->string('name'), $t->text('value'), $t->string('type')]);
        $schema->create('oc_order_total', fn (Blueprint $t) => [$t->increments('order_total_id'), $t->integer('order_id'), $t->string('code'), $t->string('title'), $t->decimal('value', 15, 4), $t->integer('sort_order')]);
        $schema->create('oc_order_history', fn (Blueprint $t) => [$t->increments('order_history_id'), $t->integer('order_id'), $t->integer('order_status_id'), $t->boolean('notify'), $t->text('comment'), $t->dateTime('date_added')]);
        $schema->create('oc_product', fn (Blueprint $t) => [$t->integer('product_id'), $t->integer('quantity')]);
        $db->table('oc_setting')->insert([['store_id' => 0, 'key' => 'config_language', 'value' => 'en-gb'], ['store_id' => 0, 'key' => 'config_currency', 'value' => 'USD'], ['store_id' => 0, 'key' => 'config_name', 'value' => 'OC Shop']]);
        $db->table('oc_language')->insert(['code' => 'en-gb']);
        $db->table('oc_currency')->insert([['code' => 'USD', 'value' => 1], ['code' => 'PKR', 'value' => 280]]);
        $db->table('oc_country')->insert(['name' => 'Pakistan', 'iso_code_2' => 'PK', 'address_format' => '']);
        $db->table('oc_order_status')->insert([['order_status_id' => 1, 'language_id' => 1, 'name' => 'Pending'], ['order_status_id' => 2, 'language_id' => 1, 'name' => 'Processing'], ['order_status_id' => 3, 'language_id' => 1, 'name' => 'Shipped']]);
        $db->table('oc_product')->insert(['product_id' => 50, 'quantity' => 1]);

        $schema->create('os_languages', fn (Blueprint $t) => [$t->integer('languages_id'), $t->string('code')]);
        $schema->create('os_configuration', fn (Blueprint $t) => [$t->string('configuration_key'), $t->string('configuration_value')]);
        $schema->create('os_orders_status', fn (Blueprint $t) => [$t->integer('orders_status_id'), $t->integer('language_id'), $t->string('orders_status_name')]);
        $schema->create('os_orders', function (Blueprint $t) {
            $t->increments('orders_id');
            $t->integer('customers_id');
            foreach (['customers_name', 'customers_email_address', 'customers_telephone', 'payment_method', 'currency'] as $column) {
                $t->string($column);
            }
            foreach (['customers', 'delivery', 'billing'] as $type) {
                foreach (['company', 'street_address', 'suburb', 'city', 'postcode', 'state', 'country'] as $column) {
                    $t->string("{$type}_{$column}");
                }
                $t->integer("{$type}_address_format_id");
            }
            $t->string('delivery_name');
            $t->string('billing_name');
            $t->integer('orders_status');
            $t->decimal('currency_value', 14, 6);
            $t->dateTime('date_purchased');
            $t->dateTime('last_modified')->nullable();
        });
        $schema->create('os_orders_products', fn (Blueprint $t) => [$t->increments('orders_products_id'), $t->integer('orders_id'), $t->integer('products_id'), $t->string('products_model'), $t->string('products_name'), $t->decimal('products_price', 15, 4), $t->decimal('final_price', 15, 4), $t->decimal('products_tax', 7, 4), $t->integer('products_quantity')]);
        $schema->create('os_orders_total', fn (Blueprint $t) => [$t->increments('orders_total_id'), $t->integer('orders_id'), $t->string('title'), $t->string('text'), $t->decimal('value', 15, 4), $t->string('class'), $t->integer('sort_order')]);
        $schema->create('os_orders_status_history', fn (Blueprint $t) => [$t->increments('orders_status_history_id'), $t->integer('orders_id'), $t->integer('orders_status_id'), $t->dateTime('date_added'), $t->integer('customer_notified'), $t->text('comments')]);
        $schema->create('os_products', fn (Blueprint $t) => [$t->integer('products_id'), $t->integer('products_quantity')]);
        $db->table('os_languages')->insert(['languages_id' => 1, 'code' => 'en']);
        $db->table('os_configuration')->insert(['configuration_key' => 'DEFAULT_LANGUAGE', 'configuration_value' => 'en']);
        $db->table('os_orders_status')->insert([['orders_status_id' => 1, 'language_id' => 1, 'orders_status_name' => 'Pending'], ['orders_status_id' => 2, 'language_id' => 1, 'orders_status_name' => 'Processing'], ['orders_status_id' => 3, 'language_id' => 1, 'orders_status_name' => 'Delivered'], ['orders_status_id' => 4, 'language_id' => 1, 'orders_status_name' => 'Cancelled']]);
        $db->table('os_products')->insert(['products_id' => 60, 'products_quantity' => 0]);

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
