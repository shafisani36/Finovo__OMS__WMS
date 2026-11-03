<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests sent with an Idempotency-Key header (scans a phone saved offline and sends when back online) run once
 * per user and key: a resend gets the saved answer with Idempotent-Replayed: true, a key reused for different
 * details is refused. Server errors are not saved, so the request can be tried again.
 */
class Idempotent
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,100}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if ($key === null || $user === null) {
            return $next($request);
        }

        if (! preg_match(self::KEY_PATTERN, $key)) {
            return response()->json(['status' => 'error', 'message' => 'The scan ID (Idempotency-Key) must be 8 to 100 letters, digits, dashes or underscores.'], 400);
        }

        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        // One request per key at a time, so two copies sent together cannot both run.
        return Cache::lock("idempotency:{$user->id}:{$key}", 30)->block(15, function () use ($request, $next, $user, $key, $hash) {
            $saved = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->first();

            if ($saved) {
                return $saved->request_hash === $hash
                    ? response($saved->response, $saved->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true'])
                    : response()->json(['status' => 'error', 'message' => 'This scan was already sent with different details. Scan it again.'], 409);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                IdempotencyKey::create([
                    'user_id' => $user->id,
                    'key' => $key,
                    'request_hash' => $hash,
                    'status_code' => $response->getStatusCode(),
                    'response' => (string) $response->getContent(),
                ]);
            }

            return $response;
        });
    }
}
