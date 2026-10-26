<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the request through only when the employee may perform that action, e.g. ->middleware('permission:orders.view').
 * Several keys separated by "|" let the request through when any one of them is allowed.
 * Which warehouse or record the request touches is checked separately (AccessControl, controllers).
 */
class EnsureUserPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if ($user?->status === 'suspended') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account is suspended. Please contact your administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        $allowed = $user && collect(explode('|', $permissions))->contains(fn (string $permission) => $user->hasPermission($permission));

        if (! $allowed) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to do this. Ask an administrator for access.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
