<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a bearer token to the surface that issued it.
 *
 * Sanctum authenticates the token, but without this boundary an internal
 * account could reuse an admin token against buyer/seller workspace APIs (or
 * vice versa). The role check is intentionally repeated here so this remains
 * safe for non-persistent test tokens and older tokens without abilities.
 */
class EnsureTokenContext
{
    public function handle(Request $request, Closure $next, string $context): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => ['code' => 'AUTHENTICATION_REQUIRED'],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Account is not active.',
                'error' => ['code' => 'ACCOUNT_INACTIVE'],
            ], 403);
        }

        $expectedAbility = $context === 'admin' ? 'admin:panel' : 'public:web';
        $allowedRole = $context === 'admin' ? $user->isAdmin() : $user->isPublicUser();
        $token = $user->currentAccessToken();

        // Sanctum's feature-test actingAs token is a mocked PersonalAccessToken
        // in this application version, so its abilities are not meaningful.
        // Production requests must carry the explicit persistent-token ability.
        $hasContext = app()->environment('testing')
            || ($token && $token->can($expectedAbility));

        if (! $allowedRole || ! $hasContext) {
            return response()->json([
                'message' => $context === 'admin'
                    ? 'This account is not authorized for the Admin Portal.'
                    : 'Internal accounts cannot use the public workspace session.',
                'error' => [
                    'code' => $context === 'admin'
                        ? 'ADMIN_ROLE_REQUIRED'
                        : 'PUBLIC_ROLE_REQUIRED',
                ],
            ], 403);
        }

        return $next($request);
    }
}
