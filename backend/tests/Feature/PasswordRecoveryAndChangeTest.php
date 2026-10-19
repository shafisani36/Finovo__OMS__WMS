<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordRecoveryAndChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_forgot_password_always_returns_generic_response(): void
    {
        // Existing user
        $user = User::factory()->create(['email' => 'known@finovo.com']);

        $resKnown = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'known@finovo.com',
        ]);

        $resKnown->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'If an eligible account is registered with this email, a verification code has been sent.',
            ]);

        Notification::assertSentTo($user, OtpCodeNotification::class);

        // Non-existent user
        $resUnknown = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'unknown@finovo.com',
        ]);

        $resUnknown->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'If an eligible account is registered with this email, a verification code has been sent.',
            ]);
    }

    public function test_password_reset_flow_with_token_and_policy(): void
    {
        $user = User::factory()->create([
            'email' => 'recover@finovo.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        // Mock verified reset token
        $resetToken = 'test-reset-claim-token-999';
        Cache::put("pwd_reset_claim:{$resetToken}", $user->id, now()->addMinutes(10));

        // Attempting to reuse old password fails
        $failReuse = $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'OldPassword123!',
            'password_confirmation' => 'OldPassword123!',
        ]);

        $failReuse->assertStatus(422);

        // Put claim back for valid attempt
        Cache::put("pwd_reset_claim:{$resetToken}", $user->id, now()->addMinutes(10));

        // Password shorter than 12 chars fails
        $failShort = $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'Short1!',
            'password_confirmation' => 'Short1!',
        ]);

        $failShort->assertStatus(422);

        // Valid new password >= 12 characters
        $resSuccess = $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'BrandNewSecurePass2026!#',
            'password_confirmation' => 'BrandNewSecurePass2026!#',
        ]);

        $resSuccess->assertStatus(200)
            ->assertJson(['success' => true]);

        // Token consumed: cannot reuse same reset_token
        $resReplay = $this->postJson('/api/v1/auth/password/reset', [
            'reset_token' => $resetToken,
            'password' => 'BrandNewSecurePass2026!#',
            'password_confirmation' => 'BrandNewSecurePass2026!#',
        ]);

        $resReplay->assertStatus(422);

        // Verify password actually updated
        $this->assertTrue(Hash::check('BrandNewSecurePass2026!#', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_authenticated_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('CurrentPassword123!'),
        ]);

        $token = $user->createToken('active_token')->plainTextToken;

        // Incorrect current password fails
        $failCurrent = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/password/change', [
                'current_password' => 'IncorrectPass!',
                'new_password' => 'BrandNewPassword123!',
                'new_password_confirmation' => 'BrandNewPassword123!',
            ]);

        $failCurrent->assertStatus(422);

        // Success change password
        $success = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/auth/password/change', [
                'current_password' => 'CurrentPassword123!',
                'new_password' => 'BrandNewPassword123!',
                'new_password_confirmation' => 'BrandNewPassword123!',
            ]);

        $success->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('BrandNewPassword123!', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_role_based_access_control_enforcement(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::OPERATOR,
            'status' => 'active',
        ]);
        $operatorToken = $operator->createToken('op_token')->plainTextToken;

        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => 'active',
        ]);
        $adminToken = $admin->createToken('adm_token')->plainTextToken;

        // Operator accessing operator endpoint: 200 OK
        app('auth')->forgetGuards();
        $opAccess = $this->withHeader('Authorization', 'Bearer '.$operatorToken)
            ->getJson('/api/v1/operator/tasks');
        $opAccess->assertStatus(200);

        // Operator attempting admin endpoint: 403 Forbidden
        app('auth')->forgetGuards();
        $opBlocked = $this->withHeader('Authorization', 'Bearer '.$operatorToken)
            ->getJson('/api/v1/admin/overview');
        $opBlocked->assertStatus(403);

        // Admin accessing admin endpoint: 200 OK
        app('auth')->forgetGuards();
        $adminAccess = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/overview');
        $adminAccess->assertStatus(200);

        // Unauthenticated request: 401 Unauthorized
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $unauth = $this->getJson('/api/v1/admin/overview');
        $unauth->assertStatus(401);
    }
}
