<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\SingleSignOnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sign in with Microsoft or the company's identity provider (the login page's SSO buttons).
 */
class SingleSignOnController extends Controller
{
    public function __construct(
        protected SingleSignOnService $sso,
        protected AuditService $audit
    ) {}

    public function providers(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->sso->available()]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless($this->sso->configured($provider), 404, 'This sign-in option is not set up.');

        try {
            return redirect()->away($this->sso->authorizationUrl($provider));
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage());
        }
    }

    /**
     * The provider sends the user back here. The token goes to the app in the URL fragment, which browsers never
     * send to a server or write to its logs.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->sso->configured($provider), 404, 'This sign-in option is not set up.');

        if ($request->filled('error') || ! $request->filled('code') || ! $request->filled('state')) {
            return $this->failed('Sign-in was cancelled or refused by '.$this->sso->label($provider).'.');
        }

        try {
            $user = $this->sso->user($provider, (string) $request->query('code'), (string) $request->query('state'));
        } catch (RuntimeException $e) {
            $this->audit->record('login.sso_failed', null, ['provider' => $provider, 'reason' => $e->getMessage()], $request);

            return $this->failed($e->getMessage());
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $token = $user->createToken('auth_token')->plainTextToken;
        $this->audit->record('login.sso_success', $user->id, ['provider' => $provider], $request);

        return redirect()->away(config('app.frontend_url').'/sso/callback#token='.urlencode($token));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/login?sso_error='.urlencode($message));
    }
}
