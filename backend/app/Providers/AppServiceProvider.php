<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
