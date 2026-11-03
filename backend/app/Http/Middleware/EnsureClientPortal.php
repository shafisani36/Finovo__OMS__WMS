<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps 3PL client users and warehouse staff apart: `client_portal:client` lets only client users (linked to a
 * client) through, `client_portal:staff` lets everyone else through.
 */
class EnsureClientPortal
{
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        $isClient = $request->user()?->role === UserRole::CLIENT;

        abort_if($audience === 'staff' && $isClient, 403, 'Client accounts can only use the client portal.');
        abort_if($audience === 'client' && (! $isClient || ! $request->user()->client_id), 403, 'The client portal is for client accounts.');

        return $next($request);
    }
}
