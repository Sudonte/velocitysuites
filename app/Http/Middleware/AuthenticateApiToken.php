<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Authenticate the request via a Bearer token issued at /api/login,
     * as the app's only existing auth (session-cookie) doesn't work for
     * a native Android client.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $apiToken = ApiToken::where('token', hash('sha256', $bearer))->first();

        if (! $apiToken || ($apiToken->expires_at && $apiToken->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = $apiToken->user;

        // Rejects a stale token from before suspension/deactivation too -
        // deactivateAccount() already revokes every token at the moment of
        // deactivation, but this is the actual enforcement boundary all
        // protected guest routes share, so it must never trust a token's
        // mere existence as proof the account is still usable.
        if (! $user || in_array($user->status, ['suspended', 'deactivated'], true)) {
            return response()->json(['message' => 'This account is no longer active.'], 403);
        }

        $apiToken->update(['last_used_at' => now()]);

        auth()->setUser($user);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}
