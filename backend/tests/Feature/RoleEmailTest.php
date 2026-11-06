<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMembership;
use App\Services\EmailService;
use App\Services\InventoryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Who is emailed about what: admins and managers about everything, operators about their own warehouses, sales
 * staff about orders, products and stores, and the customer at each step of their order.
 */
class RoleEmailTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $admin;

    protected User $manager;

    protected User $sales;

    protected User $operator;

    protected User $otherOperator;

    protected Warehouse $warehouse;

    protected Store $store;

    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Karachi DC', 'code' => 'KHI']);
        $elsewhere = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Lahore DC', 'code' => 'LHR']);

        $this->manager = $this->member(UserRole::MANAGER);
        $this->sales = $this->member(UserRole::SALES);
        $this->operator = $this->member(UserRole::OPERATOR, $this->warehouse);
        $this->otherOperator = $this->member(UserRole::OPERATOR, $elsewhere);

        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Glow Shop', 'code' => 'GLOW', 'platform' => 'shopify', 'status' => 'active']);
        $this->store->warehouses()->attach($this->warehouse->id, ['is_default' => true]);
        $this->variant = Product::create(['organization_id' => $this->org->id, 'name' => 'Face Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $this->org->id, 'sku' => 'SER-01', 'price' => 25]);
        app(InventoryEngine::class)->adjustStock(organizationId: $this->org->id, warehouseId: $this->warehouse->id, productVariantId: $this->variant->id, adjustmentType: 'add', quantity: 20, reason: 'Opening');
        $this->actingAs($this->admin);
    }

    public function test_the_customer_hears_about_every_step_and_the_team_by_role(): void
    {
        $order = $this->importOrder($this->store, $this->variant, 2, 25, ['customer_name' => 'Sana', 'customer_email' => 'sana@example.com']);
        app(EmailService::class)->sendOrderConfirmationEmail($order);

        $this->assertCustomerEmail('confirmed', ['You ordered', '2 × Face Serum']);
        $this->assertTeamGets("Order placed: {$order->order_number}", [$this->admin, $this->manager, $this->sales], [$this->operator, $this->otherOperator]);
        $this->assertTeamGets("Order sent to the warehouse: {$order->order_number}", [$this->admin, $this->manager, $this->operator], [$this->sales, $this->otherOperator]);

        $task = PickTask::where('order_id', $order->id)->firstOrFail();
        $this->putJson("/api/v1/picking/{$task->id}/status", ['status' => 'packed'])->assertOk();
        $this->assertCustomerEmail('has left our warehouse', ['packed and leaving our warehouse']);
        $this->assertTeamGets("Order packed: {$order->order_number}", [$this->operator, $this->sales], [$this->otherOperator]);

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->postJson("/api/v1/shipments/{$shipment->id}/dispatch", ['carrier' => 'TCS', 'tracking_number' => 'TCS-77'])->assertOk()->assertJsonPath('customer_emailed', true);
        $this->assertCustomerEmail('has been shipped', ['TCS-77']);
        $this->assertTeamGets("Order shipped: {$order->order_number}", [$this->admin, $this->operator, $this->sales], [$this->otherOperator]);

        $this->postJson("/api/v1/shipments/{$shipment->id}/out-for-delivery")->assertOk();
        $this->assertCustomerEmail('is out for delivery', ['within 2-3 working days', 'TCS-77']);
        $this->postJson("/api/v1/shipments/{$shipment->id}/out-for-delivery")->assertUnprocessable();

        $this->postJson("/api/v1/shipments/{$shipment->id}/deliver")->assertOk();
        $this->assertTeamGets("Order delivered: {$order->order_number}", [$this->manager, $this->sales], [$this->operator]);
        $this->assertSame('delivered', Order::find($order->id)->order_status);
    }

    public function test_stock_changes_go_to_the_operators_of_that_warehouse(): void
    {
        $this->postJson('/api/v1/inventory/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'product_variant_id' => $this->variant->id,
            'adjustment_type' => 'add',
            'quantity' => 5,
            'reason' => 'Found in aisle 3',
        ])->assertSuccessful();

        $this->assertTeamGets('Stock adjusted: SER-01 at Karachi DC', [$this->admin, $this->manager, $this->operator], [$this->sales, $this->otherOperator]);
        Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($this->operator->email) && str_contains($mail->render(), 'Found in aisle 3'));
    }

    public function test_product_changes_go_to_sales_and_not_to_operators(): void
    {
        $this->postJson('/api/v1/products', ['name' => 'Night Cream', 'sku' => 'NC-01', 'variants' => [['sku' => 'NC-01', 'price' => 12]]])->assertCreated();

        $this->assertTeamGets('Product created: Night Cream', [$this->admin, $this->manager, $this->sales], [$this->operator, $this->otherOperator]);
    }

    private function member(UserRole $role, ?Warehouse $warehouse = null): User
    {
        $user = User::factory()->create([
            'status' => 'active',
            'role' => $role,
            'current_organization_id' => $this->org->id,
            'warehouse_access' => $warehouse ? User::WAREHOUSE_ACCESS_ASSIGNED : User::WAREHOUSE_ACCESS_ALL,
        ]);
        OrganizationMembership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role->value]);
        if ($warehouse) {
            WarehouseMembership::create(['organization_id' => $this->org->id, 'warehouse_id' => $warehouse->id, 'user_id' => $user->id, 'role' => 'operator', 'status' => WarehouseMembership::STATUS_ACTIVE]);
        }

        return $user;
    }

    /**
     * @param  list<string>  $phrases
     */
    private function assertCustomerEmail(string $subject, array $phrases): void
    {
        Mail::assertSent(Mailable::class, function (Mailable $mail) use ($subject, $phrases) {
            $html = (string) $mail->render();

            return $mail->hasTo('sana@example.com') && str_contains($mail->subject, $subject)
                && collect($phrases)->every(fn (string $phrase) => str_contains($html, $phrase));
        });
    }

    /**
     * @param  list<User>  $told
     * @param  list<User>  $notTold
     */
    private function assertTeamGets(string $subject, array $told, array $notTold): void
    {
        foreach ($told as $user) {
            Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($user->email) && $mail->subject === $subject);
        }
        foreach ($notTold as $user) {
            Mail::assertNotSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($user->email) && $mail->subject === $subject);
        }
    }
}
