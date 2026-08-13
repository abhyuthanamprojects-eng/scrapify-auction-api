<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Usage: ->middleware('permission:auctions.approve')
     * Several permissions may be listed; any one of them grants access.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This action is not permitted for your role.',
            'required_permission' => $permissions,
            'role' => $user->role,
        ], 403);
    }
}
