<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        // Defense-in-depth against a guest account whose Guest profile row
        // is missing (e.g. registration failed partway through) - every
        // guest-facing controller this middleware guards (Booking/
        // Reservation/Profile/etc.) dereferences auth()->user()->guest
        // directly and would otherwise fatal with an uncaught "property on
        // null" 500 the first time such an account touched any of them,
        // surfacing to the guest as an opaque server-error toast instead of
        // an actionable message.
        if ($user->role === 'guest' && ! $user->guest) {
            return response()->json(['message' => 'Your account setup is incomplete. Please contact support.'], 422);
        }

        $apiToken->update(['last_used_at' => now()]);

        auth()->setUser($user);
        $request->attributes->set('api_token', $apiToken);

        // Debug-diagnostic request correlation (task spec "Add Request Correlation") -
        // the Android client tags each Booking/Reservation load with an X-Request-Id
        // header (e.g. BOOKINGS_LOAD_xxxxxxxx - see DiagnosticLog#newRequestId() on the
        // mobile side); when present, log one line here so a failed mobile request can
        // be matched directly to its backend-side log entry via `grep <id>
        // storage/logs/laravel.log`. Purely additive and read-only - absent header is a
        // silent no-op, never a validation failure, so an older app build or any other
        // API consumer that never sends this header is completely unaffected.
        $requestId = $request->header('X-Request-Id');
        if ($requestId) {
            Log::info('mobile_request', [
                'request_id' => $requestId,
                'guest_id' => optional($user->guest)->id,
                'path' => $request->path(),
            ]);
        }

        return $next($request);
    }
}
