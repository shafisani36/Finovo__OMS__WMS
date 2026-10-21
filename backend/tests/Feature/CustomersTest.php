<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private function store(int $orgId, StorePlatform $platform): Store
    {
        return Store::create([
            'organization_id' => $orgId, 'name' => $platform->label(), 'code' => $platform->value, 'platform' => $platform,
            'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy', 'orders_awaiting_sync' => 0,
        ]);
    }

    private function order(Store $store, string $id, array $attributes = []): Order
    {
        return Order::create($attributes + [
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'order_number' => "#{$id}",
            'external_order_id' => $id, 'customer_name' => 'Ayesha Khan', 'total_amount' => 100, 'currency' => 'PKR',
            'placed_at' => now(),
        ]);
    }

    public function test_orders_with_the_same_email_in_different_stores_make_one_customer(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $admin->currentOrganization()->id;
        $wix = $this->store($orgId, StorePlatform::WIX);
        $shopify = $this->store($orgId, StorePlatform::SHOPIFY);

        $this->order($wix, '1', ['customer_email' => 'Ayesha@Example.com', 'currency' => 'USD', 'total_amount' => 20, 'placed_at' => now()->subDays(40)]);
        $latest = $this->order($shopify, '2', [
            'customer_email' => 'ayesha@example.com', 'customer_phone' => '0300 1234567',
            'shipping_address' => ['address1' => 'House 5', 'city' => 'Karachi'], 'total_amount' => 250,
        ]);
        $latest->items()->create(['external_sku' => 'TEE-1', 'product_name' => 'Tee', 'quantity' => 2, 'unit_price' => 125, 'total_amount' => 250]);
        $this->order($shopify, '3', ['customer_name' => 'Bilal', 'customer_email' => 'bilal@example.com']);
        $this->order($shopify, '4', ['customer_name' => 'Guest customer']); // nothing to identify the buyer

        $this->assertSame(2, Customer::count());
        $ayesha = Customer::where('email', 'ayesha@example.com')->firstOrFail();
        $this->assertSame('0300 1234567', $ayesha->phone);
        $this->assertSame('Karachi', $ayesha->default_address['city']);

        $this->actingAs($admin)->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('kpis.total_customers', 2)
            ->assertJsonPath('kpis.repeat_buyers', 1)
            ->assertJsonPath('kpis.new_last_30_days', 1);

        $this->actingAs($admin)->getJson("/api/v1/customers?store_id={$wix->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.spent.0.currency', 'USD');

        $this->actingAs($admin)->getJson("/api/v1/customers/{$ayesha->id}")
            ->assertOk()
            ->assertJsonPath('data.stats.orders_count', 2)
            // PKR and USD are reported separately, never added together
            ->assertJsonCount(2, 'data.stats.spent')
            ->assertJsonPath('data.stats.spent.0.currency', 'PKR')
            ->assertJsonPath('data.stats.spent.0.amount', 250)
            ->assertJsonPath('data.stats.spent.1.currency', 'USD')
            ->assertJsonPath('data.stats.spent.1.amount', 20)
            ->assertJsonPath('data.stats.items_purchased', 2)
            ->assertJsonCount(2, 'data.stores')
            ->assertJsonPath('data.orders.0.order_number', '#2');
    }

    public function test_existing_orders_are_linked_by_the_command_and_other_organizations_are_hidden(): void
    {
        $admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $store = $this->store($admin->currentOrganization()->id, StorePlatform::WOOCOMMERCE);
        $order = $this->order($store, '9', ['customer_email' => 'old@example.com']);
        $order->updateQuietly(['customer_id' => null]);
        Customer::query()->delete();

        $this->artisan('customers:link')->assertSuccessful();
        $this->assertNotNull($order->fresh()->customer_id);

        $outsider = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->actingAs($outsider)->getJson('/api/v1/customers/'.$order->fresh()->customer_id)->assertNotFound();
        $this->actingAs($outsider)->getJson('/api/v1/customers')->assertJsonCount(0, 'data');
    }
}
