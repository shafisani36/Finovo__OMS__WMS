<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single sign-on with OpenID Connect: Microsoft (Entra ID) and the company's own identity provider (Okta, Google
 * Workspace, Keycloak, ...). Authorization code flow with PKCE and a one-time state. Only existing, active Finovo
 * users can sign in; the provider proves the email address, Finovo decides access.
 */
class SingleSignOnService
{
    public const PROVIDERS = ['microsoft', 'oidc'];

    /** Minutes a sign-in may take at the provider. */
    private const STATE_MINUTES = 10;

    /**
     * Providers set up on this server, for the login buttons.
     *
     * @return list<array{key: string, label: string}>
     */
    public function available(): array
    {
        return collect(self::PROVIDERS)
            ->filter(fn (string $provider) => $this->configured($provider))
            ->map(fn (string $provider) => ['key' => $provider, 'label' => $this->label($provider)])
            ->values()
            ->all();
    }

    public function configured(string $provider): bool
    {
        $settings = config("services.sso.{$provider}", []);

        return in_array($provider, self::PROVIDERS, true) && filled($settings['client_id'] ?? null) && filled($settings['client_secret'] ?? null)
            && ($provider === 'microsoft' || filled($settings['issuer'] ?? null));
    }

    /**
     * The provider's sign-in page for this attempt.
     */
    public function authorizationUrl(string $provider): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        Cache::put("sso:state:{$state}", ['provider' => $provider, 'verifier' => $verifier], now()->addMinutes(self::STATE_MINUTES));

        return $this->discovery($provider)['authorization_endpoint'].'?'.http_build_query([
            'client_id' => config("services.sso.{$provider}.client_id"),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri($provider),
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * The Finovo user the provider vouched for.
     *
     * @throws RuntimeException with a message for the sign-in page
     */
    public function user(string $provider, string $code, string $state): User
    {
        $attempt = Cache::pull("sso:state:{$state}");
        if (! $attempt || $attempt['provider'] !== $provider) {
            throw new RuntimeException('The sign-in link has expired. Please try again.');
        }

        $endpoints = $this->discovery($provider);

        try {
            $tokens = Http::asForm()->acceptJson()->timeout(15)->post($endpoints['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri($provider),
                'client_id' => config("services.sso.{$provider}.client_id"),
                'client_secret' => config("services.sso.{$provider}.client_secret"),
                'code_verifier' => $attempt['verifier'],
            ]);
            $profile = $tokens->successful()
                ? Http::withToken((string) $tokens->json('access_token'))->acceptJson()->timeout(15)->get($endpoints['userinfo_endpoint'])
                : null;
        } catch (ConnectionException) {
            throw new RuntimeException("{$this->label($provider)} could not be reached. Please try again.");
        }

        $email = strtolower(trim((string) ($profile?->json('email') ?? $profile?->json('preferred_username') ?? '')));
        if (! $profile?->successful() || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("{$this->label($provider)} did not confirm your email address.");
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user) {
            throw new RuntimeException("There is no Finovo account for {$email}. Ask your administrator to add you.");
        }
        if ($user->status !== 'active') {
            throw new RuntimeException('Your account is suspended. Please contact your system administrator.');
        }

        return $user;
    }

    public function label(string $provider): string
    {
        return $provider === 'microsoft' ? 'Microsoft' : (config('services.sso.oidc.label') ?: 'Enterprise SSO');
    }

    private function redirectUri(string $provider): string
    {
        return route('auth.sso.callback', ['provider' => $provider]);
    }

    /**
     * The provider's endpoints from its OpenID configuration (kept for a day).
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discovery(string $provider): array
    {
        $issuer = $provider === 'microsoft'
            ? 'https://login.microsoftonline.com/'.(config('services.sso.microsoft.tenant') ?: 'common').'/v2.0'
            : rtrim((string) config('services.sso.oidc.issuer'), '/');

        return Cache::remember('sso:discovery:'.sha1($issuer), now()->addDay(), function () use ($issuer, $provider) {
            try {
                $document = Http::acceptJson()->timeout(15)->get("{$issuer}/.well-known/openid-configuration");
            } catch (ConnectionException) {
                throw new RuntimeException("{$this->label($provider)} could not be reached. Please try again.");
            }

            $endpoints = array_intersect_key((array) $document->json(), array_flip(['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint']));
            if (! $document->successful() || count($endpoints) !== 3) {
                throw new RuntimeException("{$this->label($provider)} sign-in is not set up correctly. Contact your administrator.");
            }

            return $endpoints;
        });
    }
}
