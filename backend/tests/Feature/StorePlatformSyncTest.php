<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Integrations\Connectors\ExternalDatabaseConnector;
use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SkuMapping;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StoreSyncService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Each platform's reader against recorded-shape API responses (or a shop database), through the real sync service.
 */
class StorePlatformSyncTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->warehouse = Warehouse::create(['organization_id' => $this->admin->currentOrganization()->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
    }

    public function test_woocommerce_products_variations_and_orders(): void
    {
        $store = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);

        Http::fake([
            'https://shop.test/wp-json/wc/v3/products/10/variations*' => Http::response([
                ['id' => 11, 'sku' => 'HOOD-S', 'price' => '40', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Size', 'option' => 'S']]],
                ['id' => 12, 'sku' => 'HOOD-M', 'price' => '40', 'manage_stock' => true, 'stock_quantity' => 5, 'attributes' => [['name' => 'Size', 'option' => 'M']]],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/products*' => Http::response([
                ['id' => 10, 'name' => 'Hoodie', 'slug' => 'hoodie', 'type' => 'variable', 'status' => 'publish', 'short_description' => '<p>Warm &amp; soft</p>', 'categories' => [['name' => 'Clothing']], 'images' => [['src' => 'https://shop.test/hoodie.jpg']]],
                ['id' => 20, 'name' => 'Cap', 'slug' => 'cap', 'type' => 'simple', 'status' => 'publish', 'sku' => 'CAP-1', 'price' => '15', 'manage_stock' => true, 'stock_quantity' => 9, 'short_description' => '', 'categories' => [], 'images' => []],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([[
                'id' => 501, 'number' => '501', 'status' => 'processing', 'currency' => 'PKR', 'total' => '95.00', 'customer_note' => '', 'date_created_gmt' => '2026-10-19T10:00:00',
                'billing' => ['first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@example.com', 'phone' => '0300', 'address_1' => '1 Billing Rd', 'city' => 'Lahore', 'country' => 'PK'],
                'shipping' => ['first_name' => 'Ali', 'last_name' => 'Raza', 'address_1' => '2 Ship St', 'city' => 'Karachi', 'country' => 'PK'],
                'line_items' => [['product_id' => 10, 'variation_id' => 12, 'sku' => 'HOOD-M', 'name' => 'Hoodie - M', 'quantity' => 2, 'price' => 40, 'total' => '80.00', 'total_tax' => '0.00']],
            ]], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $result = app(StoreSyncService::class)->sync($store);

        $this->assertSame(2, $result->productsCreated);
        $this->assertSame(3, $result->variantsSynced);
        $this->assertSame('M', ProductVariant::where('sku', 'HOOD-M')->sole()->option_value);
        $this->assertSame('Warm & soft', ProductVariant::where('sku', 'HOOD-M')->sole()->product->description);
        $this->assertSame(5, $this->stockOf('HOOD-M'));

        $order = Order::with('items')->sole();
        $this->assertSame('#501', $order->order_number);
        $this->assertSame('Ali Raza', $order->customer_name);
        $this->assertSame('Karachi', $order->shipping_address['city']);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(ProductVariant::where('sku', 'HOOD-M')->value('id'), $order->items->sole()->product_variant_id);
    }

    public function test_a_shop_connected_again_as_a_new_store_reuses_its_products(): void
    {
        $fakeShop = fn () => Http::fake([
            'https://shop.test/wp-json/wc/v3/products/10/variations*' => Http::response([
                ['id' => 11, 'sku' => '', 'price' => '40', 'manage_stock' => true, 'stock_quantity' => 3, 'attributes' => [['name' => 'Size', 'option' => 'S']]],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/products*' => Http::response([
                // No SKUs in the shop, so Finovo generates them
                ['id' => 10, 'name' => 'Shoes', 'slug' => 'shoes', 'type' => 'variable', 'status' => 'publish', 'short_description' => '', 'categories' => [], 'images' => []],
                ['id' => 13, 'name' => 'Shoes', 'slug' => 'shoes', 'type' => 'simple', 'status' => 'publish', 'sku' => '', 'price' => '25', 'manage_stock' => true, 'stock_quantity' => 2, 'short_description' => '', 'categories' => [], 'images' => []],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $fakeShop();
        $first = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        app(StoreSyncService::class)->sync($first);
        $products = Product::orderBy('id')->pluck('sku')->all();
        $this->assertSame(['SHOES', 'SHOES-13'], $products);

        // The store is deleted and the same shop is connected again
        $first->delete();
        $again = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck2', 'consumer_secret' => 'cs2']);
        $fakeShop();
        $result = app(StoreSyncService::class)->sync($again);

        $this->assertSame(0, $result->productsCreated);
        $this->assertSame($products, Product::orderBy('id')->pluck('sku')->all());
        $this->assertSame(2, ProductVariant::count());
        $simpleVariant = Product::where('sku', 'SHOES-13')->sole()->variants()->sole();
        $this->assertSame(2, $this->stockOf($simpleVariant->sku), 'opening stock is not added twice');
    }

    public function test_a_free_sku_is_found_even_when_the_suffixed_one_is_taken(): void
    {
        $orgId = $this->admin->currentOrganization()->id;
        foreach (['CAP', 'CAP-20'] as $sku) {
            $taken = Product::create(['organization_id' => $orgId, 'name' => 'Other', 'sku' => $sku]);
            $taken->variants()->create(['organization_id' => $orgId, 'sku' => $sku, 'price' => 1]);
            // Linked to another store, so it is not a leftover to reuse
            SkuMapping::create(['organization_id' => $orgId, 'store_id' => $this->otherStore()->id, 'product_variant_id' => $taken->variants()->value('id'), 'external_id' => $sku, 'external_sku' => $sku]);
        }

        $store = $this->store(StorePlatform::WOOCOMMERCE, 'https://shop.test', ['consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products*' => Http::response([
                ['id' => 20, 'name' => 'Cap', 'slug' => 'cap', 'type' => 'simple', 'status' => 'publish', 'sku' => '', 'price' => '15', 'short_description' => '', 'categories' => [], 'images' => []],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(StoreSyncService::class)->sync($store);

        $this->assertTrue(Product::where('sku', 'CAP-20-2')->exists());
    }

    public function test_bigcommerce_catalog_and_orders(): void
    {
        $store = $this->store(StorePlatform::BIGCOMMERCE, 'abc123', ['access_token' => 'bc-token']);

        Http::fake([
            'https://api.bigcommerce.com/stores/abc123/v3/catalog/products*' => Http::response([
                'data' => [[
                    'id' => 77, 'name' => 'Mug', 'description' => '<p>Ceramic</p>', 'is_visible' => true, 'price' => 12, 'inventory_tracking' => 'variant',
                    'custom_url' => ['url' => '/mug/'], 'images' => [['is_thumbnail' => true, 'url_standard' => 'https://cdn.bc/mug.jpg']],
                    'variants' => [
                        ['id' => 701, 'sku' => 'MUG-BLU', 'price' => null, 'calculated_price' => 12, 'cost_price' => 4, 'inventory_level' => 6, 'option_values' => [['option_display_name' => 'Color', 'label' => 'Blue']]],
                    ],
                ]],
                'meta' => ['pagination' => ['total_pages' => 1]],
            ]),
            'https://api.bigcommerce.com/stores/abc123/v2/orders/900/products*' => Http::response([
                ['variant_id' => 701, 'sku' => 'MUG-BLU', 'name' => 'Mug', 'quantity' => 1, 'price_ex_tax' => '12.00', 'total_tax' => '0.00', 'total_inc_tax' => '12.00'],
            ]),
            'https://api.bigcommerce.com/stores/abc123/v2/orders/900/shipping_addresses' => Http::response([
                ['first_name' => 'Hina', 'last_name' => 'Ali', 'street_1' => '5 Sea View', 'city' => 'Karachi', 'country_iso2' => 'PK'],
            ]),
            'https://api.bigcommerce.com/stores/abc123/v2/orders?*' => Http::sequence()
                ->push([['id' => 900, 'status_id' => 11, 'date_created' => 'Mon, 19 Oct 2026 10:00:00 +0000', 'total_inc_tax' => '12.00', 'currency_code' => 'PKR', 'billing_address' => ['first_name' => 'Hina', 'last_name' => 'Ali', 'email' => 'hina@example.com']]])
                ->push(null, 204),
        ]);

        app(StoreSyncService::class)->sync($store);

        $variant = ProductVariant::where('sku', 'MUG-BLU')->sole();
        $this->assertSame('Color', $variant->option_name);
        $this->assertEquals(4.00, $variant->cost_price);
        $this->assertSame('https://cdn.bc/mug.jpg', $variant->product->image_url);
        $this->assertSame(6, $this->stockOf('MUG-BLU'));

        $order = Order::with('items')->sole();
        $this->assertSame('Hina Ali', $order->customer_name);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('5 Sea View', $order->shipping_address['address1']);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_prestashop_products_combinations_and_orders(): void
    {
        $store = $this->store(StorePlatform::PRESTASHOP, 'https://presta.test', ['webservice_key' => 'KEY']);
        $lang = fn (string $value) => [['id' => '1', 'value' => $value]];

        Http::fake(function (Request $request) use ($lang) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/products' => Http::response(['products' => [[
                    'id' => 3, 'reference' => 'SHIRT', 'price' => '20.000000', 'wholesale_price' => '8.000000', 'active' => '1', 'id_default_image' => '12', 'id_category_default' => '4',
                    'name' => $lang('Shirt'), 'link_rewrite' => $lang('shirt'), 'description_short' => $lang('<p>Cotton</p>'),
                    'associations' => ['combinations' => [['id' => '31']]],
                ]]]),
                '/api/stock_availables' => Http::response(['stock_availables' => [['id_product_attribute' => '31', 'quantity' => '7']]]),
                '/api/combinations' => Http::response(['combinations' => [['id' => 31, 'reference' => 'SHIRT-L', 'price' => '2.000000', 'associations' => ['product_option_values' => [['id' => '5']]]]]]),
                '/api/product_option_values' => Http::response(['product_option_values' => [['id' => 5, 'id_attribute_group' => '1', 'name' => $lang('L')]]]),
                '/api/product_options' => Http::response(['product_options' => [['id' => 1, 'public_name' => $lang('Size')]]]),
                '/api/categories/4' => Http::response(['category' => ['id' => 4, 'name' => $lang('Men')]]),
                '/api/orders' => Http::response(['orders' => [[
                    'id' => 44, 'reference' => 'XKBKNABJK', 'id_customer' => '2', 'id_address_delivery' => '6', 'id_currency' => '1', 'current_state' => '2',
                    'total_paid_tax_incl' => '22.000000', 'date_add' => '2026-10-19 10:00:00',
                    'associations' => ['order_rows' => [['product_id' => '3', 'product_attribute_id' => '31', 'product_reference' => 'SHIRT-L', 'product_name' => 'Shirt - Size : L', 'product_quantity' => '1', 'unit_price_tax_incl' => '22.000000', 'unit_price_tax_excl' => '22.000000']]],
                ]]]),
                '/api/customers/2' => Http::response(['customer' => ['firstname' => 'Sana', 'lastname' => 'Malik', 'email' => 'sana@example.com']]),
                '/api/addresses/6' => Http::response(['address' => ['firstname' => 'Sana', 'lastname' => 'Malik', 'address1' => '9 Garden Rd', 'city' => 'Lahore', 'postcode' => '54000', 'id_country' => '9', 'phone' => '0321']]),
                '/api/countries/9' => Http::response(['country' => ['iso_code' => 'PK', 'name' => $lang('Pakistan')]]),
                '/api/currencies/1' => Http::response(['currency' => ['iso_code' => 'PKR']]),
                '/api/order_states' => Http::response(['order_states' => [['id' => 2, 'paid' => '1', 'shipped' => '0', 'delivered' => '0', 'name' => $lang('Payment accepted')]]]),
            };
        });

        app(StoreSyncService::class)->sync($store);

        $variant = ProductVariant::where('sku', 'SHIRT-L')->sole();
        $this->assertSame('Size', $variant->option_name);
        $this->assertSame('L', $variant->option_value);
        $this->assertEquals(22.00, $variant->price);
        $this->assertSame('https://presta.test/img/p/1/2/12.jpg', $variant->product->image_url);
        $this->assertSame('Men', $variant->product->category);
        $this->assertSame(7, $this->stockOf('SHIRT-L'));

        $order = Order::with('items')->sole();
        $this->assertSame('XKBKNABJK', $order->order_number);
        $this->assertSame('Sana Malik', $order->customer_name);
        $this->assertSame('PK', $order->shipping_address['country_code']);
        $this->assertSame('PKR', $order->currency);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_magento_configurable_products_and_orders(): void
    {
        $store = $this->store(StorePlatform::MAGENTO, 'https://magento.test', ['access_token' => 'mg-token']);
        $attribute = fn (string $code, $value) => ['attribute_code' => $code, 'value' => $value];

        Http::fake([
            'https://magento.test/rest/V1/products*' => Http::response(['total_count' => 2, 'items' => [
                ['id' => 1, 'sku' => 'TEE', 'name' => 'Tee', 'type_id' => 'configurable', 'status' => 1, 'price' => 0, 'custom_attributes' => [$attribute('image', '/t/e/tee.jpg'), $attribute('url_key', 'tee')]],
                ['id' => 2, 'sku' => 'TEE-S', 'name' => 'Tee-S', 'type_id' => 'simple', 'status' => 1, 'price' => 30, 'custom_attributes' => [$attribute('visibility', '1')]],
            ]]),
            'https://magento.test/rest/V1/configurable-products/TEE/children' => Http::response([
                ['id' => 2, 'sku' => 'TEE-S', 'name' => 'Tee-S', 'price' => 30, 'custom_attributes' => [$attribute('cost', '11')]],
            ]),
            'https://magento.test/rest/V1/stockItems/TEE-S' => Http::response(['qty' => 4, 'manage_stock' => true]),
            'https://magento.test/rest/V1/orders*' => Http::response(['total_count' => 1, 'items' => [[
                'entity_id' => 8, 'increment_id' => '000000008', 'state' => 'complete', 'created_at' => '2026-10-19 10:00:00',
                'customer_firstname' => 'Omar', 'customer_lastname' => 'Shah', 'customer_email' => 'omar@example.com', 'grand_total' => 30, 'order_currency_code' => 'USD',
                'billing_address' => ['telephone' => '0333'],
                'items' => [
                    ['item_id' => 1, 'product_id' => 1, 'product_type' => 'configurable', 'sku' => 'TEE-S', 'name' => 'Tee', 'qty_ordered' => 1, 'price' => 30, 'tax_amount' => 0, 'row_total_incl_tax' => 30],
                    ['item_id' => 2, 'parent_item_id' => 1, 'product_id' => 2, 'product_type' => 'simple', 'sku' => 'TEE-S', 'name' => 'Tee-S', 'qty_ordered' => 1, 'price' => 0],
                ],
            ]]]),
        ]);

        $result = app(StoreSyncService::class)->sync($store);

        $this->assertSame(1, $result->productsCreated);
        $variant = ProductVariant::where('sku', 'TEE-S')->sole();
        $this->assertSame('S', $variant->option_value);
        $this->assertEquals(11.00, $variant->cost_price);
        $this->assertSame('https://magento.test/media/catalog/product/t/e/tee.jpg', $variant->product->image_url);
        $this->assertSame(4, $this->stockOf('TEE-S'));

        $order = Order::with('items')->sole();
        $this->assertSame('fulfilled', $order->fulfillment_status);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_wix_products_inventory_and_orders(): void
    {
        $store = $this->store(StorePlatform::WIX, '31713e68-1111-4222-8333-444455556666', ['api_key' => 'IST.key']);

        Http::fake([
            'https://www.wixapis.com/stores/v3/products/search' => Http::response(['products' => [['id' => 'prod-1']], 'pagingMetadata' => ['hasNext' => false]]),
            'https://www.wixapis.com/stores/v3/inventory-items/search' => Http::response(['inventoryItems' => [
                ['variantId' => 'var-1', 'productId' => 'prod-1', 'trackQuantity' => true, 'quantity' => 8],
            ]]),
            'https://www.wixapis.com/stores/v3/products/prod-1*' => Http::response(['product' => [
                'id' => 'prod-1', 'name' => 'Candle', 'slug' => 'candle', 'plainDescription' => '<p>Soy wax</p>', 'visible' => true,
                'media' => ['main' => ['image' => ['url' => 'https://static.wixstatic.com/candle.jpg']]],
                'variantsInfo' => ['variants' => [['id' => 'var-1', 'sku' => 'CANDLE-L', 'price' => ['actualPrice' => ['amount' => '18']], 'choices' => [['optionChoiceNames' => ['optionName' => 'Size', 'choiceName' => 'Large']]]]]],
            ]]),
            'https://www.wixapis.com/ecom/v1/orders/search' => Http::response(['orders' => [[
                'id' => 'order-1', 'number' => 10001, 'status' => 'APPROVED', 'paymentStatus' => 'PAID', 'fulfillmentStatus' => 'NOT_FULFILLED', 'currency' => 'USD', 'createdDate' => '2026-10-19T10:00:00.000Z',
                'buyerInfo' => ['email' => 'zara@example.com'], 'priceSummary' => ['total' => ['amount' => '18']],
                'billingInfo' => ['contactDetails' => ['firstName' => 'Zara', 'lastName' => 'Noor', 'phone' => '0345']],
                'shippingInfo' => ['logistics' => ['shippingDestination' => ['address' => ['addressLine1' => '4 Hill Rd', 'city' => 'Islamabad', 'country' => 'PK'], 'contactDetails' => ['firstName' => 'Zara', 'lastName' => 'Noor']]]],
                'lineItems' => [['productName' => ['original' => 'Candle'], 'catalogReference' => ['catalogItemId' => 'prod-1', 'options' => ['variantId' => 'var-1']], 'physicalProperties' => ['sku' => 'CANDLE-L'], 'quantity' => 1, 'price' => ['amount' => '18'], 'totalPriceAfterTax' => ['amount' => '18']]],
            ]], 'metadata' => ['hasNext' => false]]),
        ]);

        app(StoreSyncService::class)->sync($store);

        $variant = ProductVariant::where('sku', 'CANDLE-L')->sole();
        $this->assertSame('Large', $variant->option_value);
        $this->assertSame(8, $this->stockOf('CANDLE-L'));

        $order = Order::with('items')->sole();
        $this->assertSame('#10001', $order->order_number);
        $this->assertSame('Zara Noor', $order->customer_name);
        $this->assertSame('Islamabad', $order->shipping_address['city']);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_ebay_inventory_items_and_orders(): void
    {
        config(['services.ebay.environment' => 'sandbox']);
        $store = $this->store(StorePlatform::EBAY, null, ['refresh_token' => 'rt', 'access_token' => 'at', 'access_token_expires_at' => time() + 3600]);

        Http::fake([
            'https://api.sandbox.ebay.com/sell/inventory/v1/inventory_item*' => Http::response(['total' => 1, 'inventoryItems' => [[
                'sku' => 'LAMP-1', 'product' => ['title' => 'Desk Lamp', 'description' => 'LED', 'imageUrls' => ['https://i.ebayimg.com/lamp.jpg']],
                'availability' => ['shipToLocationAvailability' => ['quantity' => 5]],
            ]]]),
            'https://api.sandbox.ebay.com/sell/inventory/v1/offer*' => Http::response(['offers' => [['pricingSummary' => ['price' => ['value' => '25.00', 'currency' => 'USD']]]]]),
            'https://api.sandbox.ebay.com/sell/fulfillment/v1/order*' => Http::response(['total' => 1, 'orders' => [[
                'orderId' => '12-34567-89012', 'creationDate' => '2026-10-19T10:00:00.000Z', 'orderPaymentStatus' => 'PAID', 'orderFulfillmentStatus' => 'NOT_STARTED',
                'pricingSummary' => ['total' => ['value' => '25.00', 'currency' => 'USD']],
                'buyer' => ['username' => 'buyer_1'],
                'fulfillmentStartInstructions' => [['shippingStep' => ['shipTo' => ['fullName' => 'John Doe', 'contactAddress' => ['addressLine1' => '1 Main St', 'city' => 'Austin', 'countryCode' => 'US'], 'primaryPhone' => ['phoneNumber' => '5551234'], 'email' => 'john@example.com']]]],
                'lineItems' => [['sku' => 'LAMP-1', 'title' => 'Desk Lamp', 'quantity' => 1, 'lineItemCost' => ['value' => '25.00'], 'total' => ['value' => '25.00']]],
            ]]]),
        ]);

        app(StoreSyncService::class)->sync($store);

        $variant = ProductVariant::where('sku', 'LAMP-1')->sole();
        $this->assertEquals(25.00, $variant->price);
        $this->assertSame(5, $this->stockOf('LAMP-1'));
        $this->assertSame('https://i.ebayimg.com/lamp.jpg', $variant->product->image_url);

        $order = Order::with('items')->sole();
        $this->assertSame('John Doe', $order->customer_name);
        $this->assertSame('john@example.com', $order->customer_email);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_opencart_module_rows_are_read_with_signed_requests(): void
    {
        $store = $this->store(StorePlatform::OPENCART, 'https://oc.test', ['api_key' => 'finovo_k', 'api_secret' => str_repeat('s', 64)]);

        Http::fake(function (Request $request) {
            $this->assertSame(hash_hmac('sha256', $request->header('X-Bridge-Timestamp')[0].$request->header('X-Bridge-Nonce')[0].$request->body(), str_repeat('s', 64)), $request->header('X-Bridge-Signature')[0]);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['route']) {
                'extension/finovo/api/products' => Http::response(['image_base_url' => 'https://oc.test/image/', 'rows' => [
                    ['product_id' => 40, 'model' => 'TSHIRT', 'sku' => '', 'upc' => '', 'ean' => '', 'quantity' => 50, 'price' => '10.0000', 'image' => 'catalog/tshirt.jpg', 'status' => 1, 'name' => 'T-Shirt', 'description' => '&lt;p&gt;Cotton&lt;/p&gt;', 'category' => 'Apparel'],
                ]]),
                'extension/finovo/api/option_values' => Http::response(['rows' => [
                    ['product_option_value_id' => 7, 'product_id' => 40, 'quantity' => 12, 'subtract' => 1, 'price' => '2.0000', 'price_prefix' => '+', 'option_name' => 'Size', 'value_name' => 'Large'],
                ]]),
                'extension/finovo/api/orders' => Http::response(['rows' => [[
                    'order_id' => 3, 'invoice_prefix' => 'INV-2026-', 'firstname' => 'Bilal', 'lastname' => 'Khan', 'email' => 'bilal@example.com', 'telephone' => '0312',
                    'shipping_firstname' => 'Bilal', 'shipping_lastname' => 'Khan', 'shipping_address_1' => '7 Canal Rd', 'shipping_city' => 'Lahore', 'shipping_country' => 'Pakistan',
                    'total' => '12.0000', 'currency_code' => 'USD', 'currency_value' => '1.00000000', 'order_status_id' => 5, 'status_name' => 'Complete', 'date_added' => '2026-10-19 10:00:00', 'comment' => '',
                ]]]),
                'extension/finovo/api/order_products' => Http::response(['rows' => [
                    ['order_product_id' => 1, 'order_id' => 3, 'product_id' => 40, 'name' => 'T-Shirt', 'model' => 'TSHIRT', 'quantity' => 1, 'price' => '12.0000', 'total' => '12.0000', 'tax' => '0.0000', 'product_option_value_id' => 7],
                ]]),
            };
        });

        app(StoreSyncService::class)->sync($store);

        $variant = ProductVariant::where('sku', 'TSHIRT-LARGE')->sole();
        $this->assertEquals(12.00, $variant->price);
        $this->assertSame('https://oc.test/image/catalog/tshirt.jpg', $variant->product->image_url);
        $this->assertSame('Cotton', $variant->product->description);
        $this->assertSame(12, $this->stockOf('TSHIRT-LARGE'));

        $order = Order::with('items')->sole();
        $this->assertSame('INV-2026-3', $order->order_number);
        $this->assertSame('fulfilled', $order->fulfillment_status);
        $this->assertSame($variant->id, $order->items->sole()->product_variant_id);
    }

    public function test_opencart_and_oscommerce_databases_are_read_directly(): void
    {
        $shop = $this->fakeShopDatabase();

        $openCart = $this->store(StorePlatform::OPENCART, 'opencart_db', ['db_name' => 'opencart_db', 'table_prefix' => 'oc_', 'site_url' => 'http://localhost/opencart/']);
        $osCommerce = $this->store(StorePlatform::OSCOMMERCE, 'oscommerce_db', ['db_name' => 'oscommerce_db', 'table_prefix' => '']);

        app(StoreSyncService::class)->sync($openCart);
        app(StoreSyncService::class)->sync($osCommerce);

        $this->assertSame(3, $this->stockOf('MOUSE-1'));
        $this->assertSame('Mouse', ProductVariant::where('sku', 'MOUSE-1')->sole()->product->name);
        $this->assertSame('http://localhost/opencart/image/catalog/demo/my%20mouse.jpg', ProductVariant::where('sku', 'MOUSE-1')->sole()->product->image_url);
        // osCommerce 4: address from the default sales channel, photo from products_images
        $this->assertSame('http://localhost/oscommerce/images/products/5/31/a1b2c3.jpg', ProductVariant::where('sku', 'KB-9')->sole()->product->image_url);
        $openCartOrder = Order::where('store_id', $openCart->id)->with('items')->sole();
        $this->assertSame('Ahmed Ali', $openCartOrder->customer_name);
        $this->assertTrue($openCartOrder->order_status === 'cancelled');

        $this->assertSame(4, $this->stockOf('KB-9'));
        $osOrder = Order::where('store_id', $osCommerce->id)->with('items')->sole();
        $this->assertSame('Nida Iqbal', $osOrder->customer_name);
        $this->assertEquals(55.00, $osOrder->total_amount);
        $this->assertSame('paid', $osOrder->payment_status);
        $this->assertSame(ProductVariant::where('sku', 'KB-9')->value('id'), $osOrder->items->sole()->product_variant_id);

        $shop->disconnect();
    }

    public function test_oscommerce_2_images_use_the_website_set_on_the_store(): void
    {
        config(['database.connections.shop' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('shop');
        $schema = Schema::connection('shop');
        $schema->create('languages', fn (Blueprint $t) => [$t->integer('languages_id'), $t->string('code')]);
        $schema->create('configuration', fn (Blueprint $t) => [$t->string('configuration_key'), $t->string('configuration_value')]);
        $schema->create('products', fn (Blueprint $t) => [$t->integer('products_id'), $t->string('products_model'), $t->integer('products_quantity'), $t->decimal('products_price'), $t->integer('products_status'), $t->string('products_image')]);
        $schema->create('products_description', fn (Blueprint $t) => [$t->integer('products_id'), $t->integer('language_id'), $t->string('products_name'), $t->text('products_description')]);
        $schema->create('orders', fn (Blueprint $t) => [$t->integer('orders_id'), $t->dateTime('date_purchased'), $t->dateTime('last_modified')->nullable()]);
        $db->table('languages')->insert(['languages_id' => 1, 'code' => 'en']);
        $db->table('products')->insert(['products_id' => 8, 'products_model' => 'LAMP-1', 'products_quantity' => 1, 'products_price' => 20, 'products_status' => 1, 'products_image' => 'lamp.jpg']);
        $db->table('products_description')->insert(['products_id' => 8, 'language_id' => 1, 'products_name' => 'Lamp', 'products_description' => '']);
        $this->app->instance(ExternalDatabaseConnector::class, new class($db) extends ExternalDatabaseConnector
        {
            public function __construct(private readonly Connection $shop) {}

            public function connection(Store $store): Connection
            {
                return $this->shop;
            }
        });

        $store = $this->store(StorePlatform::OSCOMMERCE, 'osc2', ['db_name' => 'osc2']);
        $result = app(StoreSyncService::class)->sync($store);
        $this->assertNull(ProductVariant::where('sku', 'LAMP-1')->sole()->product->image_url);
        $this->assertStringContainsString("stores:set-website {$store->id}", implode(' ', $result->warnings));

        $this->artisan('stores:set-website', ['store' => $store->id, 'url' => 'http://localhost/catalog/'])->assertSuccessful();
        app(StoreSyncService::class)->sync($store->fresh());

        $this->assertSame('http://localhost/catalog/images/lamp.jpg', ProductVariant::where('sku', 'LAMP-1')->sole()->product->image_url);
        $this->artisan('stores:set-website', ['store' => $store->id, 'url' => 'not a url'])->assertFailed();
    }

    private function store(StorePlatform $platform, ?string $reference, array $credentials): Store
    {
        $store = Store::create([
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
        $store->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);

        return $store;
    }

    private function otherStore(): Store
    {
        static $count = 0;
        $count++;

        return Store::create([
            'organization_id' => $this->admin->currentOrganization()->id, 'name' => "Counter {$count}", 'code' => "counter-{$count}",
            'platform' => StorePlatform::MANUAL, 'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
    }

    private function stockOf(string $sku): ?int
    {
        return InventoryBalance::where('warehouse_id', $this->warehouse->id)
            ->where('product_variant_id', ProductVariant::where('sku', $sku)->value('id'))
            ->value('on_hand');
    }

    /**
     * A SQLite stand-in for the shop MySQL databases, with the OpenCart and osCommerce tables Finovo reads.
     */
    private function fakeShopDatabase(): Connection
    {
        config(['database.connections.shop' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('shop');
        $schema = Schema::connection('shop');

        $tables = [
            'oc_language' => fn (Blueprint $t) => [$t->integer('language_id'), $t->string('code')],
            'oc_setting' => fn (Blueprint $t) => [$t->integer('store_id'), $t->string('key'), $t->string('value')],
            'oc_product' => fn (Blueprint $t) => [$t->integer('product_id'), $t->string('model'), $t->string('sku'), $t->string('upc'), $t->string('ean'), $t->integer('quantity'), $t->decimal('price'), $t->string('image')->nullable(), $t->integer('status')],
            'oc_product_description' => fn (Blueprint $t) => [$t->integer('product_id'), $t->integer('language_id'), $t->string('name'), $t->text('description')],
            'oc_product_to_category' => fn (Blueprint $t) => [$t->integer('product_id'), $t->integer('category_id')],
            'oc_category_description' => fn (Blueprint $t) => [$t->integer('category_id'), $t->integer('language_id'), $t->string('name')],
            'oc_product_option_value' => fn (Blueprint $t) => [$t->integer('product_option_value_id'), $t->integer('product_id'), $t->integer('option_id'), $t->integer('option_value_id'), $t->integer('quantity'), $t->integer('subtract'), $t->decimal('price'), $t->string('price_prefix')],
            'oc_option_description' => fn (Blueprint $t) => [$t->integer('option_id'), $t->integer('language_id'), $t->string('name')],
            'oc_option_value_description' => fn (Blueprint $t) => [$t->integer('option_value_id'), $t->integer('language_id'), $t->string('name')],
            'oc_order' => fn (Blueprint $t) => [$t->integer('order_id'), $t->string('invoice_prefix'), $t->string('firstname'), $t->string('lastname'), $t->string('email'), $t->string('telephone'), $t->string('shipping_firstname'), $t->string('shipping_lastname'), $t->string('shipping_company'), $t->string('shipping_address_1'), $t->string('shipping_address_2'), $t->string('shipping_city'), $t->string('shipping_postcode'), $t->string('shipping_zone'), $t->string('shipping_country'), $t->text('comment'), $t->decimal('total'), $t->string('currency_code'), $t->decimal('currency_value'), $t->integer('order_status_id'), $t->dateTime('date_added'), $t->dateTime('date_modified')],
            'oc_order_status' => fn (Blueprint $t) => [$t->integer('order_status_id'), $t->integer('language_id'), $t->string('name')],
            'oc_order_product' => fn (Blueprint $t) => [$t->integer('order_product_id'), $t->integer('order_id'), $t->integer('product_id'), $t->string('name'), $t->string('model'), $t->integer('quantity'), $t->decimal('price'), $t->decimal('total'), $t->decimal('tax')],
            'oc_order_option' => fn (Blueprint $t) => [$t->integer('order_option_id'), $t->integer('order_product_id'), $t->integer('product_option_value_id')],
            'languages' => fn (Blueprint $t) => [$t->integer('languages_id'), $t->string('code')],
            'configuration' => fn (Blueprint $t) => [$t->string('configuration_key'), $t->string('configuration_value')],
            'products' => fn (Blueprint $t) => [$t->integer('products_id'), $t->string('products_model'), $t->integer('products_quantity'), $t->decimal('products_price'), $t->integer('products_status')],
            'products_description' => fn (Blueprint $t) => [$t->integer('products_id'), $t->integer('language_id'), $t->string('products_name'), $t->text('products_description')],
            'orders' => fn (Blueprint $t) => [$t->integer('orders_id'), $t->string('customers_name'), $t->string('customers_email_address'), $t->string('customers_telephone'), $t->string('delivery_name'), $t->string('delivery_company')->nullable(), $t->string('delivery_street_address'), $t->string('delivery_suburb')->nullable(), $t->string('delivery_city'), $t->string('delivery_postcode'), $t->string('delivery_state')->nullable(), $t->string('delivery_country'), $t->string('currency'), $t->decimal('currency_value'), $t->integer('orders_status'), $t->dateTime('date_purchased'), $t->dateTime('last_modified')->nullable()],
            'orders_status' => fn (Blueprint $t) => [$t->integer('orders_status_id'), $t->integer('language_id'), $t->string('orders_status_name')],
            'orders_products' => fn (Blueprint $t) => [$t->integer('orders_products_id'), $t->integer('orders_id'), $t->string('products_id'), $t->string('products_model'), $t->string('products_name'), $t->decimal('products_price'), $t->decimal('final_price'), $t->decimal('products_tax'), $t->integer('products_quantity')],
            'orders_total' => fn (Blueprint $t) => [$t->integer('orders_id'), $t->string('class'), $t->decimal('value')],
            'platforms' => fn (Blueprint $t) => [$t->integer('platform_id'), $t->string('platform_url'), $t->string('platform_url_secure'), $t->integer('ssl_enabled'), $t->integer('is_default')],
            'products_images' => fn (Blueprint $t) => [$t->integer('products_images_id'), $t->integer('products_id'), $t->integer('default_image'), $t->integer('image_status'), $t->integer('sort_order')],
            'products_images_description' => fn (Blueprint $t) => [$t->integer('products_images_id'), $t->integer('language_id'), $t->string('hash_file_name')],
        ];

        foreach ($tables as $name => $columns) {
            $schema->create($name, function (Blueprint $table) use ($columns) {
                $columns($table);
            });
        }

        $db->table('oc_language')->insert(['language_id' => 1, 'code' => 'en-gb']);
        $db->table('oc_setting')->insert(['store_id' => 0, 'key' => 'config_language', 'value' => 'en-gb']);
        $db->table('oc_product')->insert(['product_id' => 1, 'model' => 'MOUSE-1', 'sku' => '', 'upc' => '', 'ean' => '', 'quantity' => 3, 'price' => 9.5, 'image' => 'catalog/demo/my mouse.jpg', 'status' => 1]);
        $db->table('oc_product_description')->insert(['product_id' => 1, 'language_id' => 1, 'name' => 'Mouse', 'description' => '']);
        $db->table('oc_order')->insert(['order_id' => 1, 'invoice_prefix' => 'INV-', 'firstname' => 'Ahmed', 'lastname' => 'Ali', 'email' => 'ahmed@example.com', 'telephone' => '0300', 'shipping_firstname' => 'Ahmed', 'shipping_lastname' => 'Ali', 'shipping_company' => '', 'shipping_address_1' => '3 Mall Rd', 'shipping_address_2' => '', 'shipping_city' => 'Lahore', 'shipping_postcode' => '54000', 'shipping_zone' => 'Punjab', 'shipping_country' => 'Pakistan', 'comment' => '', 'total' => 9.5, 'currency_code' => 'PKR', 'currency_value' => 1, 'order_status_id' => 7, 'date_added' => '2026-10-19 10:00:00', 'date_modified' => '2026-10-19 10:00:00']);
        $db->table('oc_order_status')->insert(['order_status_id' => 7, 'language_id' => 1, 'name' => 'Canceled']);
        $db->table('oc_order_product')->insert(['order_product_id' => 1, 'order_id' => 1, 'product_id' => 1, 'name' => 'Mouse', 'model' => 'MOUSE-1', 'quantity' => 1, 'price' => 9.5, 'total' => 9.5, 'tax' => 0]);

        $db->table('languages')->insert(['languages_id' => 1, 'code' => 'en']);
        $db->table('configuration')->insert(['configuration_key' => 'DEFAULT_LANGUAGE', 'configuration_value' => 'en']);
        $db->table('products')->insert(['products_id' => 5, 'products_model' => 'KB-9', 'products_quantity' => 4, 'products_price' => 50, 'products_status' => 1]);
        $db->table('platforms')->insert(['platform_id' => 1, 'platform_url' => 'localhost/oscommerce', 'platform_url_secure' => '', 'ssl_enabled' => 0, 'is_default' => 1]);
        $db->table('products_images')->insert([
            ['products_images_id' => 30, 'products_id' => 5, 'default_image' => 0, 'image_status' => 1, 'sort_order' => 2],
            ['products_images_id' => 31, 'products_id' => 5, 'default_image' => 1, 'image_status' => 1, 'sort_order' => 1],
        ]);
        $db->table('products_images_description')->insert([
            ['products_images_id' => 30, 'language_id' => 0, 'hash_file_name' => 'other.jpg'],
            ['products_images_id' => 31, 'language_id' => 0, 'hash_file_name' => 'a1b2c3.jpg'],
        ]);
        $db->table('products_description')->insert(['products_id' => 5, 'language_id' => 1, 'products_name' => 'Keyboard', 'products_description' => '']);
        $db->table('orders')->insert(['orders_id' => 1, 'customers_name' => 'Nida Iqbal', 'customers_email_address' => 'nida@example.com', 'customers_telephone' => '0311', 'delivery_name' => 'Nida Iqbal', 'delivery_street_address' => '8 Park Ln', 'delivery_city' => 'Karachi', 'delivery_postcode' => '75000', 'delivery_country' => 'Pakistan', 'currency' => 'PKR', 'currency_value' => 1, 'orders_status' => 2, 'date_purchased' => '2026-10-19 10:00:00']);
        $db->table('orders_status')->insert(['orders_status_id' => 2, 'language_id' => 1, 'orders_status_name' => 'Processing']);
        $db->table('orders_products')->insert(['orders_products_id' => 1, 'orders_id' => 1, 'products_id' => '5', 'products_model' => 'KB-9', 'products_name' => 'Keyboard', 'products_price' => 50, 'final_price' => 50, 'products_tax' => 10, 'products_quantity' => 1]);
        $db->table('orders_total')->insert(['orders_id' => 1, 'class' => 'ot_total', 'value' => 55]);

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
