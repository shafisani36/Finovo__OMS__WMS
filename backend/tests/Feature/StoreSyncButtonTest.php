<?php

namespace Tests\Feature;

use App\Enums\StorePlatform;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The Sync button imports right away, also when a queue (without a running worker) is configured.
 */
class StoreSyncButtonTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Queue::fake();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->store = Store::create([
            'organization_id' => $this->admin->currentOrganization()->id, 'name' => 'Woo', 'code' => 'woo',
            'platform' => StorePlatform::WOOCOMMERCE, 'status' => Store::STATUS_ACTIVE, 'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0, 'external_reference' => 'https://shop.test',
            'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
        ]);
        Http::fake([
            'https://shop.test/wp-json/wc/v3/products*' => Http::response([
                ['id' => 20, 'name' => 'Cap', 'slug' => 'cap', 'type' => 'simple', 'status' => 'publish', 'sku' => 'CAP-1', 'price' => '15', 'short_description' => '', 'categories' => [], 'images' => []],
            ], 200, ['X-WP-TotalPages' => '1']),
            'https://shop.test/wp-json/wc/v3/orders*' => Http::response([], 200, ['X-WP-TotalPages' => '1']),
        ]);
    }

    public function test_the_sync_button_imports_at_once(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('result.products_created', 1);

        $this->assertTrue(Product::where('sku', 'CAP-1')->exists());
        $this->assertNotNull($this->store->fresh()->last_sync_at);
        Queue::assertNothingPushed();
    }

    public function test_a_store_already_being_synced_is_not_synced_twice(): void
    {
        $lock = Cache::lock("store-sync:{$this->store->id}", 60);
        $lock->get();

        $this->actingAs($this->admin)->postJson("/api/v1/stores/{$this->store->id}/sync")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Woo is already being synced. Its products and orders appear when that sync finishes.');

        $lock->release();
        $this->assertFalse(Product::where('sku', 'CAP-1')->exists());
    }
}
