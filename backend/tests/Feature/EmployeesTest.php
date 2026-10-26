<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sara Khan', 'email' => 'sara@finovo.test', 'status' => 'active', 'role' => UserRole::ADMIN]);
        $this->admin->currentOrganization();
    }

    private function addEmployee(array $overrides = []): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'name' => 'Ali Raza',
            'email' => 'ali@finovo.test',
            'role' => 'operator',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_adds_lists_and_edits_employees_of_their_company_only(): void
    {
        $id = $this->addEmployee(['email' => '  ALI@Finovo.test ']);
        $employee = User::find($id);

        $this->assertSame('ali@finovo.test', $employee->email);
        $this->assertSame($this->admin->current_organization_id, $employee->current_organization_id);
        $this->assertTrue(Hash::check(self::PASSWORD, $employee->password));
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $id, 'organization_id' => $this->admin->current_organization_id, 'role' => 'operator']);

        // Someone from another company never shows up.
        User::factory()->create(['email' => 'other@else.test'])->currentOrganization();

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('kpis.total', 2)
            ->assertJsonPath('kpis.admins', 1);
        $this->getJson('/api/v1/employees?search=ali')->assertJsonCount(1, 'data')->assertJsonPath('data.0.role_label', 'Warehouse Operator');

        $this->putJson("/api/v1/employees/{$id}", ['name' => 'Ali R.', 'email' => 'ali@finovo.test', 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $id, 'role' => 'admin']);

        $this->postJson('/api/v1/employees', ['name' => 'Copy', 'email' => 'ali@finovo.test', 'role' => 'operator', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_suspending_or_resetting_a_password_signs_the_employee_out(): void
    {
        $id = $this->addEmployee();
        $employee = User::find($id);
        $employee->createToken('phone');

        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$id}/status", ['status' => 'suspended'])->assertOk();
        $this->assertSame('suspended', $employee->fresh()->status);
        $this->assertSame(0, $employee->tokens()->count());

        $this->putJson("/api/v1/employees/{$id}/status", ['status' => 'active'])->assertOk();
        $employee->createToken('laptop');

        $newPassword = 'An0ther-Str0ng-One';
        $this->postJson("/api/v1/employees/{$id}/reset-password", ['password' => $newPassword, 'password_confirmation' => $newPassword])->assertOk();
        $this->assertTrue(Hash::check($newPassword, $employee->fresh()->password));
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertDatabaseHas('security_events', ['event_type' => 'employee.password_reset']);
    }

    public function test_admin_cannot_lock_themselves_or_the_company_out(): void
    {
        $id = $this->admin->id;
        $this->actingAs($this->admin);

        $this->putJson("/api/v1/employees/{$id}/status", ['status' => 'suspended'])->assertUnprocessable()->assertJsonValidationErrors('employee');
        $this->deleteJson("/api/v1/employees/{$id}")->assertUnprocessable();
        $this->putJson("/api/v1/employees/{$id}", ['name' => 'Me', 'email' => $this->admin->email, 'role' => 'operator'])->assertUnprocessable();

        // Their own name can still change.
        $this->putJson("/api/v1/employees/{$id}", ['name' => 'Me', 'email' => $this->admin->email, 'role' => 'admin'])->assertOk();
    }

    public function test_employee_with_records_is_suspended_instead_of_removed(): void
    {
        $withRecords = $this->addEmployee();
        $plain = $this->addEmployee(['email' => 'temp@finovo.test']);
        $orgId = $this->admin->current_organization_id;
        $warehouse = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB-1']);
        DB::table('purchase_requisitions')->insert([
            'organization_id' => $orgId, 'pr_number' => 'PR-1', 'requester_id' => $withRecords, 'warehouse_id' => $warehouse->id,
        ]);

        $this->deleteJson("/api/v1/employees/{$withRecords}")->assertUnprocessable()->assertJsonValidationErrors('employee');
        $this->deleteJson("/api/v1/employees/{$plain}")->assertOk();

        $this->assertDatabaseHas('users', ['id' => $withRecords]);
        $this->assertDatabaseMissing('users', ['id' => $plain]);
    }

    public function test_employees_without_the_permission_cannot_manage_employees(): void
    {
        $id = $this->addEmployee();

        $this->actingAs(User::find($id))->getJson('/api/v1/employees')->assertForbidden();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
    }

    public function test_role_defaults_and_one_employees_own_allows_and_denies(): void
    {
        $id = $this->addEmployee(['role' => 'sales']);
        $employee = User::find($id);

        // Sales staff work with orders but not purchasing.
        $this->actingAs($employee)->getJson('/api/v1/orders')->assertOk();
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();

        $sheet = $this->actingAs($this->admin)->getJson("/api/v1/employees/{$id}/permissions")->assertOk()->json('data');
        $this->assertSame(0, $sheet['version']);
        $orders = collect($sheet['modules'])->flatMap(fn ($module) => $module['permissions'])->keyBy('key');
        $this->assertTrue($orders['orders.view']['role_default']);
        $this->assertFalse($orders['purchasing.view']['effective']);

        // Deny orders, allow purchasing for this one employee only.
        $this->putJson("/api/v1/employees/{$id}/permissions", ['expected_version' => 0, 'overrides' => [
            ['permission' => 'orders.view', 'effect' => 'deny'],
            ['permission' => 'purchasing.view', 'effect' => 'allow'],
        ]])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertDatabaseHas('security_events', ['event_type' => 'employee.permissions_changed']);

        $this->actingAs($employee)->getJson('/api/v1/orders')->assertForbidden();
        $this->getJson('/api/v1/purchase-orders')->assertOk();
        $this->assertNotContains('orders.view', $this->getJson('/api/v1/auth/me')->json('data.user.permissions'));

        // Another sales employee keeps the role defaults.
        $other = User::find($this->addEmployee(['role' => 'sales', 'email' => 'zara@finovo.test']));
        $this->actingAs($other)->getJson('/api/v1/orders')->assertOk();

        // A stale screen cannot overwrite newer changes; "inherit" goes back to the role default.
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$id}/permissions", ['expected_version' => 0, 'overrides' => [
            ['permission' => 'orders.view', 'effect' => 'inherit'],
        ]])->assertStatus(409);
        $this->putJson("/api/v1/employees/{$id}/permissions", ['expected_version' => 1, 'overrides' => [
            ['permission' => 'orders.view', 'effect' => 'inherit'],
        ]])->assertOk();
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $id, 'permission' => 'orders.view']);
        $this->actingAs($employee)->getJson('/api/v1/orders')->assertOk();

        // Unknown permissions, admins and one's own account are refused.
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$id}/permissions", ['expected_version' => 2, 'overrides' => [
            ['permission' => 'payroll.run', 'effect' => 'allow'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('overrides.0.permission');
        $this->putJson("/api/v1/employees/{$this->admin->id}/permissions", ['expected_version' => 0, 'overrides' => []])->assertUnprocessable();
    }

    public function test_managers_can_only_hand_out_what_they_hold_and_never_touch_admins(): void
    {
        $managerId = $this->addEmployee(['role' => 'manager', 'email' => 'mona@finovo.test']);
        $salesId = $this->addEmployee(['role' => 'sales']);

        // Managers see employees but cannot manage permissions until an admin allows it.
        $manager = User::find($managerId);
        $this->actingAs($manager)->getJson('/api/v1/employees')->assertOk();
        $this->getJson("/api/v1/employees/{$salesId}/permissions")->assertForbidden();

        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$managerId}/permissions", ['expected_version' => 0, 'overrides' => [
            ['permission' => 'employees.manage_permissions', 'effect' => 'allow'],
        ]])->assertOk();

        $this->actingAs($manager);
        // Fine: an action the manager holds.
        $this->putJson("/api/v1/employees/{$salesId}/permissions", ['expected_version' => 0, 'overrides' => [
            ['permission' => 'inventory.adjust', 'effect' => 'allow'],
        ]])->assertOk();
        // Refused as a whole: people-and-access actions are for admins to give.
        $this->putJson("/api/v1/employees/{$salesId}/permissions", ['expected_version' => 1, 'overrides' => [
            ['permission' => 'returns.view', 'effect' => 'deny'],
            ['permission' => 'employees.create', 'effect' => 'allow'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('overrides');
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $salesId, 'permission' => 'returns.view']);

        // Admin accounts are off limits.
        $this->getJson("/api/v1/employees/{$this->admin->id}/permissions")->assertForbidden();
    }

    public function test_employee_works_only_in_assigned_warehouses(): void
    {
        $orgId = $this->admin->current_organization_id;
        $hub = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB-1']);
        $north = Warehouse::create(['organization_id' => $orgId, 'name' => 'North', 'code' => 'HUB-N']);

        $id = $this->addEmployee(['warehouse_access' => 'assigned', 'warehouse_ids' => [$hub->id]]);
        $employee = User::find($id);
        $this->assertDatabaseHas('warehouse_memberships', ['user_id' => $id, 'warehouse_id' => $hub->id, 'status' => 'active', 'assigned_by' => $this->admin->id]);
        $this->getJson('/api/v1/employees?search=ali')->assertJsonPath('data.0.warehouses.0.name', 'Hub')->assertJsonPath('data.0.warehouse_access', 'assigned');
        $this->getJson("/api/v1/warehouses/{$hub->id}/staff")->assertJsonCount(1, 'data.assigned_staff');

        // Only Hub is visible or usable, even when North's ID is sent.
        $this->actingAs($employee)->getJson('/api/v1/warehouses')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Hub');
        $this->getJson("/api/v1/warehouses/{$north->id}")->assertForbidden();
        $this->getJson("/api/v1/warehouses/{$hub->id}")->assertOk();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.user.warehouse_ids', [$hub->id]);

        // Moved to North: Hub access ends at once and the Hub assignment stays as history.
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$id}", ['name' => 'Ali Raza', 'email' => 'ali@finovo.test', 'warehouse_ids' => [$north->id]])
            ->assertOk()
            ->assertJsonPath('data.warehouses.0.name', 'North');
        $this->assertDatabaseHas('warehouse_memberships', ['user_id' => $id, 'warehouse_id' => $hub->id, 'status' => 'revoked']);
        $this->getJson("/api/v1/warehouses/{$hub->id}/staff")->assertJsonCount(0, 'data.assigned_staff');
        $this->actingAs($employee)->getJson("/api/v1/warehouses/{$hub->id}")->assertForbidden();

        // A suspended account can do nothing, even with a session still open.
        $this->actingAs($this->admin)->putJson("/api/v1/employees/{$id}/status", ['status' => 'suspended'])->assertOk();
        $this->actingAs($employee->fresh())->getJson('/api/v1/orders')->assertForbidden();

        // Other companies' warehouses are not valid choices.
        $other = User::factory()->create();
        $foreign = Warehouse::create(['organization_id' => $other->currentOrganization()->id, 'name' => 'Theirs', 'code' => 'T-1']);
        $this->actingAs($this->admin)->postJson('/api/v1/employees', [
            'name' => 'Bad', 'email' => 'bad@finovo.test', 'role' => 'operator',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'warehouse_ids' => [$foreign->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_ids.0');
    }

    public function test_warehouse_scope_limits_stock_and_fulfillment_lists(): void
    {
        $orgId = $this->admin->current_organization_id;
        $hub = Warehouse::create(['organization_id' => $orgId, 'name' => 'Hub', 'code' => 'HUB-1']);
        $north = Warehouse::create(['organization_id' => $orgId, 'name' => 'North', 'code' => 'HUB-N']);
        $product = Product::create(['organization_id' => $orgId, 'name' => 'Mug', 'sku' => 'MUG']);
        $variant = $product->variants()->create(['organization_id' => $orgId, 'sku' => 'MUG-1', 'price' => 5]);
        foreach ([$hub, $north] as $warehouse) {
            app(InventoryEngine::class)->adjustStock($orgId, $warehouse->id, $variant->id, 'add', 10, 'Opening');
        }

        $employee = User::find($this->addEmployee(['warehouse_access' => 'assigned', 'warehouse_ids' => [$hub->id]]));
        $this->actingAs($employee);

        $this->assertSame([$hub->id], collect($this->getJson('/api/v1/inventory')->assertOk()->json('data'))->pluck('warehouse_id')->unique()->values()->all());
        $this->assertSame(['Hub'], collect($this->getJson('/api/v1/inventory/movements')->assertOk()->json('data'))->pluck('warehouse')->unique()->values()->all());
        $this->getJson("/api/v1/inventory/sku/{$variant->id}")->assertJsonCount(1, 'data.warehouse_balances');

        $adjust = ['product_variant_id' => $variant->id, 'adjustment_type' => 'subtract', 'quantity' => 1, 'reason' => 'Broken'];
        $this->postJson('/api/v1/inventory/adjustments', [...$adjust, 'warehouse_id' => $north->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warehouse_id');
        $this->postJson('/api/v1/inventory/adjustments', [...$adjust, 'warehouse_id' => $hub->id])->assertSuccessful();
    }
}
