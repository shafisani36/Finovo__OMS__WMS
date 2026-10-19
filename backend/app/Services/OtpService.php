<?php

namespace App\Services;

use App\Enums\ChallengePurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public const CODE_LENGTH = 6;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const LOGIN_EXPIRY_MINUTES = 5;

    public const RESET_EXPIRY_MINUTES = 10;

    public const ROLLING_WINDOW_MINUTES = 15;

    public const MAX_CHALLENGES_IN_WINDOW = 5;

    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Issue a new cryptographic OTP challenge for the given user and purpose.
     */
    public function issueChallenge(User $user, ChallengePurpose $purpose, ?Request $request = null): OtpChallenge
    {
        // 1. Enforce rolling-window limit on challenge creation
        $recentCount = OtpChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subMinutes(self::ROLLING_WINDOW_MINUTES))
            ->count();

        if ($recentCount >= self::MAX_CHALLENGES_IN_WINDOW) {
            $this->auditService->record('otp.rate_limited', $user->id, [
                'purpose' => $purpose->value,
                'reason' => 'rolling_window_exceeded',
            ], $request);

            throw ValidationException::withMessages([
                'otp' => ['Too many verification requests. Please wait a few minutes before trying again.'],
            ])->status(429);
        }

        // 2. Invalidate any existing active challenges for this purpose
        OtpChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
            ]);

        // 3. Generate cryptographic 6-digit numeric code
        $code = (string) random_int(100000, 999999);
        $challengeId = (string) Str::uuid();
        $codeHash = $this->hashCode($code, $challengeId);

        $expiryMinutes = $purpose === ChallengePurpose::LOGIN
            ? self::LOGIN_EXPIRY_MINUTES
            : self::RESET_EXPIRY_MINUTES;

        $challenge = OtpChallenge::create([
            'challenge_id' => $challengeId,
            'user_id' => $user->id,
            'code_hash' => $codeHash,
            'purpose' => $purpose,
            'destination' => $user->email,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'resend_available_at' => now()->addSeconds(self::RESEND_COOLDOWN_SECONDS),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'ip_address' => $request?->ip() ?? request()->ip(),
            'user_agent' => substr((string) ($request?->userAgent() ?? request()->userAgent()), 0, 500),
        ]);

        $challenge->raw_code = $code;

        // 4. Deliver OTP securely via notification
        try {
            $user->notify(new OtpCodeNotification($code, $purpose, $expiryMinutes));
        } catch (Exception $e) {
            $challenge->update(['revoked_at' => now()]);

            $this->auditService->record('otp.delivery_failed', $user->id, [
                'purpose' => $purpose->value,
                'error' => $e->getMessage(),
            ], $request);

            throw ValidationException::withMessages([
                'otp' => ['Unable to deliver verification code. Please contact system support.'],
            ]);
        }

        $this->auditService->record('otp.issued', $user->id, [
            'purpose' => $purpose->value,
            'challenge_id' => $challengeId,
            'expires_in_minutes' => $expiryMinutes,
        ], $request);

        return $challenge;
    }

    /**
     * Resend an OTP for an active challenge subject to cooldown and limits.
     */
    public function resendChallenge(string $challengeId, ?Request $request = null): OtpChallenge
    {
        $challenge = OtpChallenge::where('challenge_id', $challengeId)->first();

        if (! $challenge) {
            throw ValidationException::withMessages([
                'challenge_id' => ['Invalid or expired verification session.'],
            ]);
        }

        if ($challenge->isConsumed()) {
            throw ValidationException::withMessages([
                'challenge_id' => ['This verification session has already been completed.'],
            ]);
        }

        // Enforce cooldown
        if ($challenge->resend_available_at && $challenge->resend_available_at->isFuture()) {
            $secondsRemaining = now()->diffInSeconds($challenge->resend_available_at);

            throw ValidationException::withMessages([
                'resend' => ["Please wait {$secondsRemaining} seconds before requesting a new code."],
            ])->status(429);
        }

        $user = $challenge->user;

        if (! $user || ! $user->isActive()) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The associated user account is inactive or suspended.'],
            ]);
        }

        // Revoke the old challenge
        $challenge->update(['revoked_at' => now()]);

        // Issue replacement
        return $this->issueChallenge($user, $challenge->purpose, $request);
    }

    /**
     * Concurrency-safe atomic OTP verification.
     */
    public function verifyChallenge(
        string $challengeId,
        string $code,
        ChallengePurpose $expectedPurpose,
        ?Request $request = null
    ): User {
        /** @var OtpChallenge|null $challenge */
        $challenge = OtpChallenge::where('challenge_id', $challengeId)->first();

        if (! $challenge || $challenge->purpose !== $expectedPurpose) {
            $this->auditService->record('otp.failed_invalid_challenge', null, [
                'challenge_id' => $challengeId,
            ], $request);

            throw ValidationException::withMessages([
                'code' => ['The verification challenge is invalid or has expired.'],
            ]);
        }

        if ($challenge->isConsumed()) {
            $this->auditService->record('otp.replay_attempted', $challenge->user_id, [
                'challenge_id' => $challengeId,
            ], $request);

            throw ValidationException::withMessages([
                'code' => ['This verification code has already been used. Please log in again.'],
            ]);
        }

        if ($challenge->isRevoked()) {
            throw ValidationException::withMessages([
                'code' => ['This verification challenge was replaced. Please use the most recently issued code.'],
            ]);
        }

        if ($challenge->isExpired()) {
            $this->auditService->record('otp.expired_attempt', $challenge->user_id, [
                'challenge_id' => $challengeId,
            ], $request);

            throw ValidationException::withMessages([
                'code' => ['The verification code has expired. Please request a new one.'],
            ]);
        }

        if ($challenge->hasExceededMaxAttempts()) {
            $this->auditService->record('otp.max_attempts_exceeded', $challenge->user_id, [
                'challenge_id' => $challengeId,
            ], $request);

            throw ValidationException::withMessages([
                'code' => ['Maximum verification attempts exceeded. Please request a new code.'],
            ])->status(429);
        }

        // Secure digest comparison
        $expectedHash = $this->hashCode(trim($code), $challengeId);
        $isValidCode = hash_equals($challenge->code_hash, $expectedHash);

        if (! $isValidCode) {
            $challenge->increment('attempts');
            $remaining = max(0, $challenge->max_attempts - $challenge->attempts);

            $this->auditService->record('otp.failed_code', $challenge->user_id, [
                'challenge_id' => $challengeId,
                'attempts' => $challenge->attempts,
                'remaining' => $remaining,
            ], $request);

            if ($remaining === 0) {
                throw ValidationException::withMessages([
                    'code' => ['Maximum verification attempts exceeded. Please request a new code.'],
                ])->status(429);
            }

            throw ValidationException::withMessages([
                'code' => ["Invalid verification code. {$remaining} attempt(s) remaining."],
            ]);
        }

        // Atomic consumption preventing race conditions and double-spending
        $affected = OtpChallenge::where('id', $challenge->id)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
            ]);

        if ($affected === 0) {
            throw ValidationException::withMessages([
                'code' => ['This verification code has already been used.'],
            ]);
        }

        $user = $challenge->user;

        $this->auditService->record('otp.verified', $user->id, [
            'challenge_id' => $challengeId,
            'purpose' => $challenge->purpose->value,
        ], $request);

        return $user;
    }

    /**
     * Mask email address for user presentation without revealing sensitive account info.
     * e.g., "alexander@finovo.com" -> "a*******r@finovo.com"
     */
    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        $len = strlen($name);
        if ($len <= 2) {
            $maskedName = substr($name, 0, 1).'***';
        } else {
            $maskedName = $name[0].str_repeat('*', max(3, $len - 2)).$name[$len - 1];
        }

        return $maskedName.'@'.$domain;
    }

    /**
     * Cryptographic keyed digest for OTP codes.
     */
    protected function hashCode(string $code, string $salt): string
    {
        $key = config('app.key') ?: 'finovo_oms_wms_secret_key_salt';

        return hash_hmac('sha256', $code, $key.':'.$salt);
    }
}
