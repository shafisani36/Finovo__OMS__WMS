<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Worker task dashboard: each worker sees the pick tasks given to them, and an admin sees every worker's open and
 * finished work and gives unassigned tasks to workers.
 */
class WorkBoardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $picker;

    protected Warehouse $warehouse;

    protected Store $store;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $orgId = $this->admin->currentOrganization()->id;
        $this->picker = User::factory()->create(['name' => 'Ali Picker', 'status' => 'active', 'role' => UserRole::OPERATOR]);
        $this->picker->memberships()->create(['organization_id' => $orgId, 'role' => UserRole::OPERATOR->value]);
        $this->picker->forceFill(['current_organization_id' => $orgId])->save();

        $this->warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB', 'is_active' => true]);
        $this->store = Store::create(['organization_id' => $orgId, 'name' => 'Shop', 'code' => 'SHOP', 'platform' => 'manual', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);
        $this->variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 25]);
    }

    public function test_an_admin_gives_a_task_to_a_worker_and_the_worker_sees_it(): void
    {
        $task = $this->task();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.unassigned.0.id', $task->id)
            ->assertJsonPath('data.totals.open_pick_tasks', 1);

        $this->putJson("/api/v1/picking/{$task->id}/assign", ['user_id' => $this->picker->id])
            ->assertOk()
            ->assertJsonPath('message', "Order {$task->order->order_number} is now Ali Picker's to pick.");

        $this->actingAs($this->picker)->getJson('/api/v1/operator/tasks')
            ->assertOk()
            ->assertJsonPath('data.my_tasks.0.id', $task->id)
            ->assertJsonPath('data.my_tasks.0.status', 'not_started')
            ->assertJsonPath('data.unassigned', 0);
    }

    public function test_the_board_counts_each_workers_open_and_finished_work(): void
    {
        $this->task(['assigned_to_user_id' => $this->picker->id, 'status' => 'in_progress', 'started_at' => now()]);
        $this->task(['assigned_to_user_id' => $this->picker->id, 'status' => 'packed', 'completed_at' => now(), 'pick_errors' => 1, 'pack_errors' => 1]);
        $this->task(['assigned_to_user_id' => $this->picker->id, 'status' => 'packed', 'completed_at' => now()->subDays(2)]);

        $worker = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/overview')->assertOk()->json('data.workers'))
            ->firstWhere('id', $this->picker->id);

        $this->assertSame(['open_tasks' => 1, 'done_today' => 1, 'scan_errors_today' => 2], array_intersect_key($worker, array_flip(['open_tasks', 'done_today', 'scan_errors_today'])));
    }

    public function test_tasks_go_only_to_workers_of_the_company_and_only_admins_see_the_board(): void
    {
        $task = $this->task();
        $outsider = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR]);

        $this->actingAs($this->admin)->putJson("/api/v1/picking/{$task->id}/assign", ['user_id' => $outsider->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
        $this->putJson("/api/v1/picking/{$task->id}/assign", ['user_id' => null])->assertOk();

        $this->actingAs($this->picker)->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->actingAs($this->picker)->putJson("/api/v1/picking/{$task->id}/assign", ['user_id' => $this->picker->id])->assertForbidden();
    }

    private function task(array $attributes = []): PickTask
    {
        $order = $this->importOrder($this->store, $this->variant, 1);

        return PickTask::create([
            'organization_id' => $this->warehouse->organization_id,
            'order_id' => $order->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'not_started',
            ...$attributes,
        ]);
    }
}
