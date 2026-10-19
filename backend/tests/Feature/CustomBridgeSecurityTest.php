<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomBridgeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Store $store;

    protected string $apiKey = 'finovo_test_api_key_12345';

    protected string $apiSecret = 'finovo_super_secret_shared_hmac_key_67890';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['status' => 'active']);
        $this->org = $user->currentOrganization();

        $this->store = Store::create([
            'organization_id' => $this->org->id,
            'name' => 'Custom PHP Store',
            'code' => 'CUSTOM-STORE',
            'platform' => 'custom_bridge',
            'status' => 'active',
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
        ]);
    }

    public function test_valid_hmac_signed_order_is_accepted_and_persisted(): void
    {
        $payload = [
            'order_id' => 'EXT-1001',
            'customer_name' => 'Michael Scott',
            'customer_email' => 'michael@dundermifflin.com',
            'items' => [
                [
                    'sku' => 'PAPER-01',
                    'name' => 'Dunder Mifflin Paper Ream',
                    'quantity' => 10,
                    'price' => 29.99,
                ],
            ],
            'total_amount' => 299.90,
            'currency' => 'USD',
        ];

        $rawBody = json_encode($payload);
        $timestamp = time();
        $nonce = 'nonce_'.bin2hex(random_bytes(16));
        $canonical = "{$timestamp}\n{$nonce}\n{$rawBody}";
        $signature = hash_hmac('sha256', $canonical, $this->apiSecret);

        $response = $this->call(
            'POST',
            '/api/v1/bridge/orders',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BRIDGE_API_KEY' => $this->apiKey,
                'HTTP_X_BRIDGE_TIMESTAMP' => $timestamp,
                'HTTP_X_BRIDGE_NONCE' => $nonce,
                'HTTP_X_BRIDGE_SIGNATURE' => $signature,
            ],
            $rawBody
        );

        $response->assertCreated()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', [
            'organization_id' => $this->org->id,
            'store_id' => $this->store->id,
            'external_order_id' => 'EXT-1001',
            'customer_name' => 'Michael Scott',
        ]);
    }

    public function test_replay_attack_with_same_nonce_is_blocked(): void
    {
        $payload = ['order_id' => 'EXT-1002', 'customer_name' => 'Dwight', 'items' => [['sku' => 'BEET-1', 'name' => 'Beets', 'quantity' => 1, 'price' => 5]]];
        $rawBody = json_encode($payload);
        $timestamp = time();
        $nonce = 'fixed_reuse_nonce_12345';
        $canonical = "{$timestamp}\n{$nonce}\n{$rawBody}";
        $signature = hash_hmac('sha256', $canonical, $this->apiSecret);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BRIDGE_API_KEY' => $this->apiKey,
            'HTTP_X_BRIDGE_TIMESTAMP' => $timestamp,
            'HTTP_X_BRIDGE_NONCE' => $nonce,
            'HTTP_X_BRIDGE_SIGNATURE' => $signature,
        ];

        // First attempt succeeds
        $first = $this->call('POST', '/api/v1/bridge/orders', [], [], [], $headers, $rawBody);
        $first->assertCreated();

        // Second attempt with same nonce is blocked
        $second = $this->call('POST', '/api/v1/bridge/orders', [], [], [], $headers, $rawBody);
        $second->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Nonce already used (replay attack prevented).');
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $rawBody = json_encode(['order_id' => 'EXT-999']);
        $timestamp = time();
        $nonce = 'nonce_tampered';

        $response = $this->call(
            'POST',
            '/api/v1/bridge/orders',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_BRIDGE_API_KEY' => $this->apiKey,
                'HTTP_X_BRIDGE_TIMESTAMP' => $timestamp,
                'HTTP_X_BRIDGE_NONCE' => $nonce,
                'HTTP_X_BRIDGE_SIGNATURE' => 'invalid_forged_signature',
            ],
            $rawBody
        );

        $response->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Invalid HMAC signature.');
    }
}
