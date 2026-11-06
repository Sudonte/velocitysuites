<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account that signed in with a temporary password (an administrator-approved reset or a new staff account) can reach nothing but the
 * "set a new password" screen and sign-out until it has chosen a permanent one - typing a dashboard address does not get round it. A
 * temporary password that has passed its 24 hours ends the session.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($user->temporaryPasswordExpired()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your temporary password has expired. Request a new staff password reset from the Forgot password page.');
        }

        if ($request->routeIs('force-password-change.*', 'logout')) {
            return $next($request);
        }

        return redirect()->route('force-password-change.show');
    }
}
