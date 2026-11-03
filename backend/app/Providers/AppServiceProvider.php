<?php

namespace App\Providers;

use App\Services\AccessControl;
use App\Services\StoreSyncOutbox;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request, so each employee's permissions are worked out once per request and never kept longer.
        $this->app->scoped(AccessControl::class);
        // Collects the store updates of one request so they are sent once, after the response.
        $this->app->scoped(StoreSyncOutbox::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureUrls();
        // No call to a store, courier or other service may hang a request or a worker: calls that set no time limit
        // of their own give up after these many seconds.
        Http::globalOptions(['connect_timeout' => 10, 'timeout' => 30]);
        // The /up health check (for the load balancer and uptime monitor) fails when the database or cache is down.
        Event::listen(DiagnosingHealth::class, function () {
            DB::select('SELECT 1');
            Cache::put('health-check', true, 10);
        });
        // Rate Limiter for Login Attempts
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($request->ip().'|'.strtolower($email))->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many login attempts. Please try again in 60 seconds.',
                    'data' => null,
                    'errors' => ['email' => ['Too many login attempts. Please try again in 60 seconds.']],
                ], 429);
            });
        });

        // Rate Limiter for OTP Verification
        RateLimiter::for('otp-verify', function (Request $request) {
            $challengeId = (string) $request->input('challenge_id');

            return Limit::perMinute(5)->by($request->ip().'|'.$challengeId)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many verification attempts. Please wait before trying again.',
                    'data' => null,
                    'errors' => ['code' => ['Too many verification attempts. Please wait before trying again.']],
                ], 429);
            });
        });

        // Rate Limiter for OTP Resend
        RateLimiter::for('otp-resend', function (Request $request) {
            $challengeId = (string) $request->input('challenge_id');

            return Limit::perMinutes(15, 5)->by($request->ip().'|'.$challengeId)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many code resend requests. Please wait a few minutes.',
                    'data' => null,
                    'errors' => ['resend' => ['Too many code resend requests. Please wait a few minutes.']],
                ], 429);
            });
        });

        // Rate Limiter for Forgot Password Requests
        RateLimiter::for('forgot-password', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinutes(15, 5)->by($request->ip().'|'.strtolower($email))->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many password reset requests. Please try again later.',
                    'data' => null,
                    'errors' => ['email' => ['Too many password reset requests. Please try again later.']],
                ], 429);
            });
        });

        // Signed-in API traffic, per user (per address before sign-in).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('app.api_rate_limit'))
            ->by((string) ($request->user()?->id ?: $request->ip())));

        // Rate Limiter for Password Changes (Authenticated)
        RateLimiter::for('password-change', function (Request $request) {
            $userId = $request->user()?->id ?: $request->ip();

            return Limit::perHour(5)->by((string) $userId)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many password change attempts. Please wait an hour.',
                    'data' => null,
                    'errors' => ['current_password' => ['Too many password change attempts. Please wait an hour.']],
                ], 429);
            });
        });
    }

    /**
     * Callback and download links sent to store platforms must use the public HTTPS address,
     * also when TLS ends at a load balancer or reverse proxy in front of the app.
     */
    private function configureUrls(): void
    {
        $proxies = (string) config('app.trusted_proxies');

        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Every link (platform callbacks, install pages, module downloads) is built from APP_URL, not from the
        // address the request came in on: behind a proxy or tunnel (or the frontend's dev proxy) the request host is
        // the internal one, which Shopify, WooCommerce, BigCommerce, Wix, eBay and the shop modules cannot reach.
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }
}
