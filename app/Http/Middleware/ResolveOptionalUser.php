<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public endpoints that behave differently for signed-in callers — the auction
 * listing shows staff the approval queue, and "Interested" attaches to a user
 * account when there is one. Without this, a valid bearer token on a public
 * route would be ignored, and requiring auth would lock out anonymous visitors.
 */
class ResolveOptionalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() && ! $request->user()) {
            if ($user = Auth::guard('sanctum')->user()) {
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}
