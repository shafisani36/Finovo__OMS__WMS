<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is suspended or inactive. Please contact your system administrator.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        if (empty($roles)) {
            return $next($request);
        }

        $userRole = $user->role?->value ?? (string) $user->role;

        if (! in_array($userRole, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: You do not have permission to access this resource.',
                'data' => null,
                'errors' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
