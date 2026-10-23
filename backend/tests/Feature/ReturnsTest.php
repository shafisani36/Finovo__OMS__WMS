<?php

namespace Tests\Feature;

use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Store $store;

    private Warehouse $warehouse;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['status' => 'active']);
        $org = $this->user->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $org->id, 'name' => 'Hub', 'code' => 'HUB-1']);
        $this->store = Store::create(['organization_id' => $org->id, 'name' => 'Shop', 'code' => 'S-1', 'platform' => 'shopify', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);

        $product = Product::create(['organization_id' => $org->id, 'name' => 'Mouse', 'sku' => 'M-1']);
        $this->variantId = $product->variants()->create(['organization_id' => $org->id, 'sku' => 'M-1', 'price' => 10])->id;
        app(InventoryEngine::class)->adjustStock($org->id, $this->warehouse->id, $this->variantId, 'add', 10, 'Opening');
    }

    /** Create an order for 3 units and take it through packing and dispatch. */
    private function shippedOrder(): array
    {
        $order = $this->importOrder($this->store, ProductVariant::findOrFail($this->variantId), 3);
        $this->actingAs($this->user);

        $task = PickTask::where('order_id', $order->id)->firstOrFail();
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertOk();
        $shipmentId = $this->getJson('/api/v1/shipments')->json('data.0.id');
        $this->postJson("/api/v1/shipments/{$shipmentId}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'T-1'])->assertOk();

        return $this->getJson('/api/v1/returns/returnable-orders')->assertOk()->json('data.0');
    }

    private function balance(): array
    {
        return app(InventoryEngine::class)->getAvailability($this->user->currentOrganization()->id, $this->warehouse->id, $this->variantId);
    }

    public function test_return_is_received_inspected_and_restocked(): void
    {
        $order = $this->shippedOrder();
        $this->assertSame(3, $order['items'][0]['returnable_quantity']);
        $this->assertSame(7, $this->balance()['on_hand']);

        $line = ['order_item_id' => $order['items'][0]['id'], 'quantity' => 2];

        // Cannot return more than was shipped.
        $this->postJson('/api/v1/returns', ['order_id' => $order['id'], 'reason' => 'Wrong size', 'items' => [['order_item_id' => $line['order_item_id'], 'quantity' => 4]]])
            ->assertUnprocessable();

        $returnId = $this->postJson('/api/v1/returns', ['order_id' => $order['id'], 'reason' => 'Wrong size', 'items' => [$line]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.warehouse', 'Hub')
            ->json('data.id');

        // Only one unit is left to return now.
        $this->getJson('/api/v1/returns/returnable-orders')->assertJsonPath('data.0.items.0.returnable_quantity', 1);

        // It has to arrive before it can be inspected.
        $this->postJson("/api/v1/returns/{$returnId}/complete", ['disposition' => 'sellable'])->assertUnprocessable();
        $this->postJson("/api/v1/returns/{$returnId}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson("/api/v1/returns/{$returnId}/complete", ['disposition' => 'sellable', 'notes' => 'Unopened'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(9, $this->balance()['on_hand']);
        $this->assertSame(9, $this->balance()['available']);
        $this->getJson("/api/v1/returns?store_id={$this->store->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('kpis.completed', 1)
            ->assertJsonPath('kpis.restocked_units', 2);

        $other = Store::create(['organization_id' => $this->store->organization_id, 'name' => 'Other', 'code' => 'S-2', 'platform' => 'shopify', 'status' => 'active']);
        $this->getJson("/api/v1/returns?store_id={$other->id}")->assertJsonCount(0, 'data');

        // Returning the last unit as damaged puts it on hand but not up for sale, and closes the order.
        $last = $this->postJson('/api/v1/returns', ['order_id' => $order['id'], 'reason' => 'Broken', 'items' => [['order_item_id' => $line['order_item_id'], 'quantity' => 1]]])->json('data.id');
        $this->postJson("/api/v1/returns/{$last}/receive")->assertOk();
        $this->postJson("/api/v1/returns/{$last}/complete", ['disposition' => 'damaged'])->assertOk();

        $this->assertSame(10, $this->balance()['on_hand']);
        $this->assertSame(9, $this->balance()['available']);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'order_status' => 'returned']);
    }

    public function test_rejected_return_frees_the_quantity_and_unshipped_orders_cannot_be_returned(): void
    {
        $order = $this->shippedOrder();
        $line = ['order_item_id' => $order['items'][0]['id'], 'quantity' => 3];

        $returnId = $this->postJson('/api/v1/returns', ['order_id' => $order['id'], 'reason' => 'Changed mind', 'items' => [$line]])->json('data.id');
        $this->getJson('/api/v1/returns/returnable-orders')->assertJsonCount(0, 'data');

        $this->postJson("/api/v1/returns/{$returnId}/reject", ['reason' => 'Outside return window'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->getJson('/api/v1/returns/returnable-orders')->assertJsonPath('data.0.items.0.returnable_quantity', 3);
        $this->assertSame(7, $this->balance()['on_hand']);

        $unshipped = $this->importOrder($this->store, ProductVariant::findOrFail($this->variantId), 1);
        $this->postJson('/api/v1/returns', [
            'order_id' => $unshipped->id,
            'reason' => 'Test',
            'items' => [['order_item_id' => $unshipped->items[0]->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }
}
