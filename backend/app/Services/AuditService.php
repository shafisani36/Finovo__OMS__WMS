<?php

namespace App\Services;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Redacted sensitive keys that must never appear in logs or audit records.
     */
    private const REDACTED_KEYS = [
        'password',
        'current_password',
        'new_password',
        'password_confirmation',
        'code',
        'otp',
        'token',
        'reset_token',
        'remember_token',
        'authorization',
        'cookie',
    ];

    public function record(
        string $eventType,
        ?int $userId = null,
        ?array $metadata = null,
        ?Request $request = null
    ): SecurityEvent {
        $ip = $request?->ip() ?? request()->ip();
        $userAgent = substr((string) ($request?->userAgent() ?? request()->userAgent()), 0, 255);

        $cleanMetadata = $this->sanitizeMetadata($metadata ?? []);

        // Structured logging for SIEM / observability
        Log::info("SecurityEvent: [{$eventType}]", [
            'user_id' => $userId,
            'ip' => $ip,
            'event' => $eventType,
            'metadata' => $cleanMetadata,
        ]);

        return SecurityEvent::create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'metadata' => empty($cleanMetadata) ? null : $cleanMetadata,
            'created_at' => now(),
        ]);
    }

    private function sanitizeMetadata(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, self::REDACTED_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
