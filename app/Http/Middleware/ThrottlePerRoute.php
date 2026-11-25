<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Laravel's "throttle:N,M" counts EVERY throttled route for the same visitor (or the
 * same signed-in user) on ONE shared counter. A customer typing two addresses into the
 * booking form (address lookups) used up the booking's own limit, so the booking itself
 * was refused with a 429 (QA 25 Nov 2026). Each route now has its own counter.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $routeKey = $route ? ($route->getName() ?: $request->method().' '.$route->uri()) : $request->path();

        return sha1(parent::resolveRequestSignature($request).'|'.$routeKey);
    }
}
