<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\CourierAccount;
use App\Models\Customer;
use App\Models\Form;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\RecurringOrder;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\ProductImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security rules: response headers, token lifetime, rate limits, no requests to private network addresses and
 * no access to another company's records.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_an_api_token_stops_working_after_its_lifetime(): void
    {
        $token = $this->admin->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_authenticated_api_is_rate_limited_per_user(): void
    {
        config(['app.api_rate_limit' => 3]);

        foreach (range(1, 3) as $request) {
            $this->actingAs($this->admin)->getJson('/api/v1/warehouses')->assertOk();
        }
        $this->actingAs($this->admin)->getJson('/api/v1/warehouses')->assertTooManyRequests();
    }

    public function test_a_courier_account_cannot_point_at_a_private_address(): void
    {
        config(['app.block_private_hosts' => true]);

        $this->actingAs($this->admin)->postJson('/api/v1/courier-accounts', [
            'carrier' => 'leopards',
            'name' => 'Leopards',
            'credentials' => ['api_key' => 'K', 'api_password' => 'P'],
            'settings' => ['api_url' => 'https://169.254.169.254/latest'],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings.api_url');
    }

    public function test_a_product_photo_on_a_private_address_is_not_downloaded(): void
    {
        config(['app.block_private_hosts' => true]);
        Http::fake();
        $product = Product::create([
            'organization_id' => $this->admin->currentOrganization()->id,
            'name' => 'Serum',
            'sku' => 'SER-01',
            'image_url' => 'http://169.254.169.254/latest/meta-data/iam',
        ]);

        $this->assertFalse(app(ProductImageStore::class)->store($product));
        Http::assertNothingSent();
    }

    public function test_another_companys_records_cannot_be_read_or_changed(): void
    {
        $ids = $this->otherCompanyRecords();
        $checked = 0;

        /** @var Route $route */
        foreach (Router::getRoutes() as $route) {
            $parameters = $route->parameterNames();
            // {id} is checked on shipment routes only: elsewhere it names other kinds of records.
            if (! Str::startsWith($route->uri(), 'api/v1/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)
                || $parameters === [] || array_diff($parameters, array_keys($ids)) !== []
                || (in_array('id', $parameters, true) && ! Str::startsWith($route->uri(), 'api/v1/shipments/'))) {
                continue;
            }

            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) $ids[$m[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $status = $this->actingAs($this->admin)->json($method, $uri)->status();
                $this->assertTrue($status >= 400 && $status !== 500, "{$method} {$uri} answered {$status}");
                $checked++;
            }
        }

        $this->assertGreaterThan(80, $checked);
    }

    /**
     * One record of each kind in a second company, keyed by route parameter name.
     *
     * @return array<string, int|string>
     */
    private function otherCompanyRecords(): array
    {
        $other = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $other->currentOrganization()->id;
        $this->assertNotSame($orgId, $this->admin->currentOrganization()->id);

        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Other Hub', 'code' => 'OTH', 'is_active' => true]);
        $location = WarehouseLocation::create([
            'organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'code' => 'A01-B01',
            'location_type' => 'bin', 'putaway_eligible' => true, 'picking_eligible' => true, 'is_active' => true,
        ]);
        $store = Store::create(['organization_id' => $orgId, 'name' => 'Other Shop', 'code' => 'OTHER', 'platform' => 'manual', 'status' => 'active']);
        $store->warehouses()->attach($warehouse->id, ['is_default' => true]);
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Other Serum', 'sku' => 'OTH-01']);
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'OTH-01', 'price' => 100]);
        $order = $this->importOrder($store, $variant, 1);
        $pickTask = PickTask::create(['organization_id' => $orgId, 'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'not_started']);
        $pickTask->moveTo('packed', $other);

        return [
            'store' => $store->id,
            'warehouse' => $warehouse->id,
            'location' => $location->id,
            'product' => $product->id,
            'variantId' => $variant->id,
            'order' => $order->id,
            'pickTask' => $pickTask->id,
            'id' => Shipment::where('order_id', $order->id)->value('id'),
            'courierAccount' => CourierAccount::create(['organization_id' => $orgId, 'carrier' => 'leopards', 'name' => 'Other', 'credentials' => ['api_key' => 'K'], 'settings' => []])->id,
            'client' => Client::create(['organization_id' => $orgId, 'name' => 'Other Client', 'code' => 'OTH'])->id,
            'customer' => Customer::create(['organization_id' => $orgId, 'identity_key' => 'other', 'name' => 'Other Customer'])->id,
            'form' => Form::create(['organization_id' => $orgId, 'name' => 'Other Form', 'schema' => ['fields' => []]])->uuid,
            'version' => 1,
            'recurringOrder' => RecurringOrder::create(['organization_id' => $orgId, 'template' => [], 'interval' => 'weekly', 'next_run_at' => now()->addWeek()])->id,
        ];
    }
}
