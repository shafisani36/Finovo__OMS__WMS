<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class ActivityEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Organization $org;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->org = $this->admin->currentOrganization();
        $this->warehouse = Warehouse::create(['organization_id' => $this->org->id, 'name' => 'Karachi DC', 'code' => 'KHI-01']);
    }

    public function test_only_active_admins_of_the_organization_get_the_store_created_email(): void
    {
        $secondAdmin = $this->member(UserRole::ADMIN);
        $operator = $this->member(UserRole::OPERATOR);
        $outsideAdmin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);

        $this->actingAs($this->admin)->postJson('/api/v1/stores', [
            'name' => 'Walk-in Counter',
            'platform' => 'manual',
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();

        foreach ([$this->admin, $secondAdmin] as $admin) {
            Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($admin->email)
                && $mail->hasSubject('Store created: Walk-in Counter')
                && str_contains($mail->render(), 'Fulfilling warehouses: Karachi DC'));
        }
        Mail::assertNotSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($operator->email) || $mail->hasTo($outsideAdmin->email));
    }

    public function test_deleting_a_store_emails_the_admins(): void
    {
        $store = Store::create([
            'organization_id' => $this->org->id,
            'name' => 'Old Shop',
            'code' => 'old-shop',
            'platform' => 'manual',
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
        ]);
        $store->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/stores/{$store->id}")->assertOk();

        Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasTo($this->admin->email)
            && $mail->hasSubject('Store deleted: Old Shop')
            && str_contains($mail->render(), 'Linked warehouses: Karachi DC'));
    }

    public function test_creating_and_deleting_a_warehouse_emails_the_admins(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/warehouses', ['name' => 'Lahore Hub', 'code' => 'LHR-01'])
            ->assertCreated()
            ->json('data.id');

        Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasSubject('Warehouse created: Lahore Hub')
            && str_contains($mail->render(), 'Code: LHR-01'));

        $this->actingAs($this->admin)->deleteJson("/api/v1/warehouses/{$id}")->assertOk();

        $this->assertDatabaseMissing('warehouses', ['id' => $id]);
        Mail::assertSent(Mailable::class, fn (Mailable $mail) => $mail->hasSubject('Warehouse deleted: Lahore Hub'));
    }

    public function test_a_warehouse_in_use_cannot_be_deleted(): void
    {
        $store = Store::create([
            'organization_id' => $this->org->id,
            'name' => 'Main Shop',
            'code' => 'main-shop',
            'platform' => 'manual',
            'status' => Store::STATUS_ACTIVE,
            'sync_status' => 'healthy',
            'orders_awaiting_sync' => 0,
        ]);
        $store->warehouses()->sync([$this->warehouse->id => ['is_default' => true, 'priority' => 1]]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/warehouses/{$this->warehouse->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.warehouse.0', "Warehouse 'Karachi DC' cannot be deleted because it has linked stores. Deactivate it instead.");

        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id]);
        Mail::assertNothingSent();
    }

    public function test_only_admins_can_delete_a_warehouse(): void
    {
        $operator = $this->member(UserRole::OPERATOR);

        $this->actingAs($operator)->deleteJson("/api/v1/warehouses/{$this->warehouse->id}")->assertForbidden();
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id]);
    }

    public function test_the_change_is_kept_when_the_email_cannot_be_sent(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->actingAs($this->admin)->postJson('/api/v1/stores', [
            'name' => 'Walk-in Counter',
            'platform' => 'manual',
            'warehouse_id' => $this->warehouse->id,
        ])->assertCreated();

        $this->assertDatabaseHas('stores', ['name' => 'Walk-in Counter']);
    }

    public function test_unknown_events_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(EmailService::class)->sendActivityEmail('store.renamed', $this->org, ['name' => 'X', 'actor' => $this->admin]);
    }

    private function member(UserRole $role): User
    {
        $user = User::factory()->create(['status' => 'active', 'role' => $role]);
        OrganizationMembership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role->value]);

        return $user;
    }
}
