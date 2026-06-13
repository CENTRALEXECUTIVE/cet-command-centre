<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control. Usage: ->middleware('role:admin') or
 * 'role:admin,driver' to allow several roles. Enforces the principle of least
 * privilege — anything not explicitly granted is denied with a 403.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, 'Your account is not active.');
        }

        if (! in_array($user->role->value, $roles, true)) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
