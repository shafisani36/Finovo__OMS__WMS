<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengePurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected AuditService $auditService
    ) {}

    /**
     * Authenticate user credentials and issue an OTP challenge.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));
        $password = (string) $request->validated('password');

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        // Timing-safe password check to avoid account enumeration
        $passwordMatches = $user ? Hash::check($password, $user->password) : false;

        if (! $user || ! $passwordMatches) {
            $this->auditService->record('login.failed', $user?->id, [
                'email' => $email,
                'reason' => 'invalid_credentials',
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
                'data' => null,
                'errors' => [
                    'email' => ['Invalid email or password.'],
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->isActive()) {
            $this->auditService->record('login.rejected_inactive', $user->id, [
                'status' => $user->status,
            ], $request);

            return response()->json([
                'success' => false,
                'message' => 'Your account is suspended. Please contact your system administrator.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        // OTP verification is mandatory for production security
        $challenge = $this->otpService->issueChallenge($user, ChallengePurpose::LOGIN, $request);

        $this->auditService->record('login.credentials_verified', $user->id, [
            'challenge_id' => $challenge->challenge_id,
        ], $request);

        $responseData = [
            'requires_otp' => true,
            'challenge_id' => $challenge->challenge_id,
            'destination' => $this->otpService->maskEmail($user->email),
            'expires_in_seconds' => OtpService::LOGIN_EXPIRY_MINUTES * 60,
            'resend_cooldown_seconds' => OtpService::RESEND_COOLDOWN_SECONDS,
        ];

        if (config('app.env') === 'local' && isset($challenge->raw_code)) {
            $responseData['dev_code'] = $challenge->raw_code;
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent to your registered email.',
            'data' => $responseData,
            'errors' => null,
        ]);
    }

    /**
     * Verify the 6-digit OTP code and establish the authenticated session.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $challengeId = (string) $request->validated('challenge_id');
        $code = (string) $request->validated('code');

        $user = $this->otpService->verifyChallenge(
            $challengeId,
            $code,
            ChallengePurpose::LOGIN,
            $request
        );

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        // Update login tracking metadata
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // 1. Session Login (for stateful cookie-based SPA flow)
        if ($request->hasSession()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        // 2. Personal Access Bearer Token (for decoupled API / token authentication)
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->auditService->record('login.success', $user->id, [
            'challenge_id' => $challengeId,
            'role' => $user->role->value,
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'Login successful. Welcome back.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'status' => $user->status,
                    'permissions' => $user->role->permissions(),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ],
                'token' => $token,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Resend an OTP code for an active verification challenge.
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $challengeId = (string) $request->validated('challenge_id');

        $newChallenge = $this->otpService->resendChallenge($challengeId, $request);

        $responseData = [
            'challenge_id' => $newChallenge->challenge_id,
            'destination' => $this->otpService->maskEmail($newChallenge->destination),
            'expires_in_seconds' => OtpService::LOGIN_EXPIRY_MINUTES * 60,
            'resend_cooldown_seconds' => OtpService::RESEND_COOLDOWN_SECONDS,
        ];

        if (config('app.env') === 'local' && isset($newChallenge->raw_code)) {
            $responseData['dev_code'] = $newChallenge->raw_code;
        }

        return response()->json([
            'success' => true,
            'message' => 'A new verification code has been dispatched to your email.',
            'data' => $responseData,
            'errors' => null,
        ]);
    }

    /**
     * Get the currently authenticated user's profile and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user profile retrieved.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'status' => $user->status,
                    'permissions' => $user->role->permissions(),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Terminate the authenticated session and revoke tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            // Revoke current Sanctum token
            $user->currentAccessToken()?->delete();

            $this->auditService->record('auth.logout', $user->id, null, $request);
        }

        // Invalidate session cookie if present
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'success' => true,
            'message' => 'You have been successfully logged out.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
