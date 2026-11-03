<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sign in with Microsoft (Entra ID) or the company's own OpenID Connect provider. Only existing, active Finovo
 * users can sign in this way; nobody is created by it.
 */
class SingleSignOnTest extends TestCase
{
    use RefreshDatabase;

    private const DISCOVERY = [
        'authorization_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_endpoint' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'userinfo_endpoint' => 'https://graph.microsoft.com/oidc/userinfo',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'http://localhost:3000',
            'services.sso.microsoft' => ['client_id' => 'ms-client', 'client_secret' => 'ms-secret', 'tenant' => 'common'],
            'services.sso.oidc' => ['client_id' => null, 'client_secret' => null, 'issuer' => null, 'label' => null],
        ]);
    }

    public function test_only_set_up_providers_are_offered(): void
    {
        $this->getJson('/api/v1/auth/sso/providers')
            ->assertOk()
            ->assertJsonPath('data', [['key' => 'microsoft', 'label' => 'Microsoft']]);

        $this->get('/api/v1/auth/sso/oidc/redirect')->assertNotFound();
    }

    public function test_a_finovo_user_signs_in_with_microsoft(): void
    {
        $user = User::factory()->create(['email' => 'sara@company.com', 'status' => 'active', 'role' => UserRole::MANAGER]);
        $this->fakeProvider('Sara@Company.com');

        $redirect = $this->get('/api/v1/auth/sso/microsoft/redirect')->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith(self::DISCOVERY['authorization_endpoint'].'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['ms-client', 'code', 'S256'], [$query['client_id'], $query['response_type'], $query['code_challenge_method']]);

        $callback = $this->get('/api/v1/auth/sso/microsoft/callback?'.http_build_query(['code' => 'abc', 'state' => $query['state']]));
        $callback->assertRedirect();
        $this->assertStringStartsWith('http://localhost:3000/sso/callback#token=', $callback->headers->get('Location'));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotNull($user->fresh()->last_login_at);

        // A state is used once.
        $this->get('/api/v1/auth/sso/microsoft/callback?'.http_build_query(['code' => 'abc', 'state' => $query['state']]))
            ->assertRedirectContains('sso_error=');
    }

    public function test_unknown_or_suspended_users_are_turned_away(): void
    {
        User::factory()->create(['email' => 'gone@company.com', 'status' => 'suspended', 'role' => UserRole::OPERATOR]);

        foreach (['nobody@company.com', 'gone@company.com'] as $email) {
            $this->fakeProvider($email);
            parse_str((string) parse_url($this->get('/api/v1/auth/sso/microsoft/redirect')->headers->get('Location'), PHP_URL_QUERY), $query);

            $this->get('/api/v1/auth/sso/microsoft/callback?'.http_build_query(['code' => 'abc', 'state' => $query['state']]))
                ->assertRedirectContains('http://localhost:3000/login?sso_error=');
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function fakeProvider(string $email): void
    {
        Http::fake([
            'login.microsoftonline.com/common/v2.0/.well-known/openid-configuration' => Http::response(self::DISCOVERY),
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(['access_token' => 'at-1', 'token_type' => 'Bearer']),
            'graph.microsoft.com/oidc/userinfo' => Http::response(['email' => $email, 'name' => 'Sara']),
        ]);
    }
}
