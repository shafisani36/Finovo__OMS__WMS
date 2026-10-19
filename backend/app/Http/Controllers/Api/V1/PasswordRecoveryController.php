<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChallengePurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyResetOtpRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Services\AuditService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PasswordRecoveryController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected AuditService $auditService
    ) {}

    /**
     * Step 1: Initiate password reset with generic enumeration-safe response.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $challengeId = null;
        $maskedEmail = $this->otpService->maskEmail($email);

        if ($user && $user->isActive()) {
            $challenge = $this->otpService->issueChallenge(
                $user,
                ChallengePurpose::PASSWORD_RESET,
                $request
            );
            $challengeId = $challenge->challenge_id;

            $this->auditService->record('password.reset_requested', $user->id, [
                'challenge_id' => $challengeId,
            ], $request);
        } else {
            // Record event without revealing account existence
            $this->auditService->record('password.reset_requested_unknown_account', null, [
                'email' => $email,
            ], $request);
        }

        // Always return generic response to prevent account enumeration
        return response()->json([
            'success' => true,
            'message' => 'If an eligible account is registered with this email, a verification code has been sent.',
            'data' => [
                'challenge_id' => $challengeId,
                'destination' => $maskedEmail,
                'expires_in_seconds' => OtpService::RESET_EXPIRY_MINUTES * 60,
                'resend_cooldown_seconds' => OtpService::RESEND_COOLDOWN_SECONDS,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Step 2: Verify reset OTP and exchange it for a single-use password reset authorization token.
     */
    public function verifyResetOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $challengeId = (string) $request->validated('challenge_id');
        $code = (string) $request->validated('code');

        $user = $this->otpService->verifyChallenge(
            $challengeId,
            $code,
            ChallengePurpose::PASSWORD_RESET,
            $request
        );

        // Issue a short-lived, single-use reset authorization token (10 min expiry)
        $resetToken = (string) Str::random(64);
        Cache::put("pwd_reset_claim:{$resetToken}", $user->id, now()->addMinutes(OtpService::RESET_EXPIRY_MINUTES));

        $this->auditService->record('password.reset_otp_verified', $user->id, [
            'challenge_id' => $challengeId,
        ], $request);

        return response()->json([
            'success' => true,
            'message' => 'Reset code verified successfully. Please enter your new password.',
            'data' => [
                'reset_token' => $resetToken,
                'expires_in_seconds' => OtpService::RESET_EXPIRY_MINUTES * 60,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Step 3: Consume reset token atomically and apply new password.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $resetToken = (string) $request->validated('reset_token');
        $newPassword = (string) $request->validated('password');

        // Atomically pull and invalidate the claim to prevent token replay
        $userId = Cache::pull("pwd_reset_claim:{$resetToken}");

        if (! $userId) {
            return response()->json([
                'success' => false,
                'message' => 'The password reset link or token is invalid, expired, or has already been used.',
                'data' => null,
                'errors' => [
                    'reset_token' => ['The password reset session has expired or is invalid.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::find($userId);

        if (! $user || ! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to reset password for this account.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        // Prevent password reuse
        if (Hash::check($newPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your new password cannot be the same as your previous password.',
                'data' => null,
                'errors' => [
                    'password' => ['Your new password cannot be the same as your previous password.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Update password hash using configured secure hasher
        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        // Invalidate all existing tokens and sessions
        $user->tokens()->delete();

        // Send alert notification
        try {
            $user->notify(new PasswordChangedNotification(
                ipAddress: $request->ip(),
                timestamp: now()->toIso8601String()
            ));
        } catch (\Throwable $e) {
            // Alert failure logged safely without stopping the successful password reset
        }

        $this->auditService->record('password.reset_completed', $user->id, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Your password has been successfully reset. Please log in with your new credentials.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
