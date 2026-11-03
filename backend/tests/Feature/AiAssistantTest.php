<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Read-only AI assistant (OpenRouter): answers from the company's own records through read-only lookups, never
 * changes anything, and never sees another company's data.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openrouter.key' => 'test-key', 'services.openrouter.model' => 'openai/gpt-4o-mini']);
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
    }

    public function test_the_assistant_answers_from_a_looked_up_order(): void
    {
        $order = $this->order($this->admin, 'GS-1042');
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->toolCall('find_order', ['order_number' => 'GS-1042']))
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Order GS-1042 is allocated.']]]]),
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/ai/ask', ['question' => 'Where is order GS-1042?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Order GS-1042 is allocated.')
            ->assertJsonPath('sources.0', 'Order GS-1042');

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer test-key')
            && collect($request['messages'])->contains(fn ($m) => ($m['role'] ?? '') === 'tool' && str_contains($m['content'], "{$order->order_number} customer")));
    }

    public function test_lookups_never_reach_another_companys_records(): void
    {
        $other = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->order($other, 'OTHER-1');
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->toolCall('find_order', ['order_number' => 'OTHER-1']))
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'I could not find that order.']]]]),
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/ai/ask', ['question' => 'Show order OTHER-1'])->assertOk()->assertJsonPath('sources', []);

        Http::assertSent(fn (HttpRequest $request) => ! collect($request['messages'])->contains(fn ($m) => ($m['role'] ?? '') === 'tool' && str_contains($m['content'], 'OTHER-1 customer')));
    }

    public function test_a_lookup_needs_the_users_permission(): void
    {
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR]);
        $operator->memberships()->create(['organization_id' => $this->admin->currentOrganization()->id, 'role' => 'operator']);
        $operator->forceFill(['current_organization_id' => $this->admin->currentOrganization()->id])->save();
        $operator->permissionOverrides()->create(['organization_id' => $this->admin->currentOrganization()->id, 'permission' => 'orders.view', 'effect' => 'deny']);

        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->toolCall('order_counts', []))
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'You cannot see orders.']]]]),
        ]);

        $this->actingAs($operator)->postJson('/api/v1/ai/ask', ['question' => 'How many orders are open?'])->assertOk();
        Http::assertSent(fn (HttpRequest $request) => collect($request['messages'])->contains(fn ($m) => ($m['role'] ?? '') === 'tool' && str_contains($m['content'], 'not allowed')));
    }

    public function test_stock_lookup_and_unconfigured_assistant(): void
    {
        $orgId = $this->admin->currentOrganization()->id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 10]);
        InventoryBalance::create(['organization_id' => $orgId, 'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 9, 'available' => 7, 'reserved' => 2]);
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($this->toolCall('stock_for_product', ['product' => 'ser-01']))
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => '7 available in Hub.']]]]),
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/ai/ask', ['question' => 'Stock of SER-01?'])
            ->assertOk()
            ->assertJsonPath('sources.0', 'Stock of SER-01');

        config(['services.openrouter.key' => null]);
        $this->postJson('/api/v1/ai/ask', ['question' => 'Hello'])->assertStatus(503);
    }

    private function order(User $user, string $number)
    {
        $orgId = $user->currentOrganization()->id;
        $store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'S'.$user->id, 'platform' => 'manual', 'status' => 'active']);
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Item', 'sku' => 'IT-'.$user->id])->variants()->create(['organization_id' => $orgId, 'sku' => 'IT-'.$user->id, 'price' => 10]);

        return $this->importOrder($store, $variant, 1, 10, ['order_number' => $number, 'customer_name' => "{$number} customer"]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments): array
    {
        return ['choices' => [['message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]],
        ]]]];
    }
}
