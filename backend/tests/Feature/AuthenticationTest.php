<?php

namespace Tests\Feature;

use App\Enums\ChallengePurpose;
use App\Enums\UserRole;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_login_requires_valid_credentials_and_returns_generic_error(): void
    {
        User::factory()->create([
            'email' => 'operator@finovo.com',
            'password' => Hash::make('CorrectPassword123!'),
            'role' => UserRole::OPERATOR,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@finovo.com',
            'password' => 'WrongPassword999!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid email or password.',
            ]);

        // Non-existent email also receives identical generic failure
        $nonExistent = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@finovo.com',
            'password' => 'AnyPassword123!',
        ]);

        $nonExistent->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid email or password.',
            ]);
    }

    public function test_valid_login_issues_otp_challenge_and_dispatches_notification(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@finovo.com',
            'password' => Hash::make('AdminSecretPass123!'),
            'role' => UserRole::ADMIN,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@finovo.com',
            'password' => 'AdminSecretPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'requires_otp' => true,
                ],
            ]);

        $challengeId = $response->json('data.challenge_id');
        $this->assertNotNull($challengeId);

        $this->assertDatabaseHas('otp_challenges', [
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'purpose' => ChallengePurpose::LOGIN->value,
            'consumed_at' => null,
        ]);

        Notification::assertSentTo($user, OtpCodeNotification::class);
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'banned@finovo.com',
            'password' => Hash::make('ValidPass123456!'),
            'role' => UserRole::OPERATOR,
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'banned@finovo.com',
            'password' => 'ValidPass123456!',
        ]);

        $response->assertStatus(403);
    }

    public function test_successful_otp_verification_authenticates_user_and_issues_token(): void
    {
        $user = User::factory()->create([
            'email' => 'warehouse@finovo.com',
            'role' => UserRole::OPERATOR,
            'status' => 'active',
        ]);

        // Issue OTP
        $challengeId = (string) Str::uuid();
        $code = '654321';
        $key = config('app.key') ?: 'finovo_oms_wms_secret_key_salt';
        $codeHash = hash_hmac('sha256', $code, $key.':'.$challengeId);

        OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => $codeHash,
            'purpose' => ChallengePurpose::LOGIN,
            'destination' => $user->email,
            'expires_at' => now()->addMinutes(5),
            'resend_available_at' => now()->addSeconds(60),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => '654321',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user' => [
                        'email' => 'warehouse@finovo.com',
                        'role' => 'operator',
                    ],
                ],
            ]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Challenge should be consumed
        $this->assertNotNull(OtpChallenge::where('challenge_id', $challengeId)->first()->consumed_at);

        // Test authenticated endpoint with token
        $meResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('data.user.email', 'warehouse@finovo.com');
    }

    public function test_otp_verification_rejects_incorrect_code_and_tracks_attempts(): void
    {
        $user = User::factory()->create();
        $challengeId = (string) Str::uuid();
        $code = '112233';
        $key = config('app.key') ?: 'finovo_oms_wms_secret_key_salt';
        $codeHash = hash_hmac('sha256', $code, $key.':'.$challengeId);

        $challenge = OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => $codeHash,
            'purpose' => ChallengePurpose::LOGIN,
            'destination' => $user->email,
            'expires_at' => now()->addMinutes(5),
            'resend_available_at' => now()->addSeconds(60),
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => '999999',
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, $challenge->fresh()->attempts);
    }

    public function test_consumed_otp_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $challengeId = (string) Str::uuid();
        $code = '334455';
        $key = config('app.key') ?: 'finovo_oms_wms_secret_key_salt';
        $codeHash = hash_hmac('sha256', $code, $key.':'.$challengeId);

        OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => $codeHash,
            'purpose' => ChallengePurpose::LOGIN,
            'destination' => $user->email,
            'expires_at' => now()->addMinutes(5),
            'resend_available_at' => now()->addSeconds(60),
            'attempts' => 0,
            'max_attempts' => 5,
            'consumed_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => '334455',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => ['This verification code has already been used. Please log in again.']]);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = User::factory()->create();
        $challengeId = (string) Str::uuid();
        $code = '778899';
        $key = config('app.key') ?: 'finovo_oms_wms_secret_key_salt';
        $codeHash = hash_hmac('sha256', $code, $key.':'.$challengeId);

        OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => $codeHash,
            'purpose' => ChallengePurpose::LOGIN,
            'destination' => $user->email,
            'expires_at' => now()->subMinute(),
            'resend_available_at' => now()->subMinutes(2),
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => '778899',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => ['The verification code has expired. Please request a new one.']]);
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $user = User::factory()->create();
        $challengeId = (string) Str::uuid();

        OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => 'hash',
            'purpose' => ChallengePurpose::LOGIN,
            'destination' => $user->email,
            'expires_at' => now()->addMinutes(5),
            'resend_available_at' => now()->addSeconds(45), // In cooldown
            'attempts' => 0,
            'max_attempts' => 5,
        ]);

        $response = $this->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $challengeId,
        ]);

        $response->assertStatus(429);
    }
}
