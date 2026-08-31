<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAccountStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->status === 'suspended') {
            auth()->logout();
            return redirect()->route('login')->with('error', 'Your account has been suspended.');
        }

        // Same 30-day soft-delete/restore window the mobile API enforces
        // (Api\AuthController::login() / Api\ProfileController::deleteAccount/
        // restoreAccount) - a web guest still inside the window is allowed to
        // stay logged in, but is routed to a restore prompt instead of the
        // normal dashboard on every request until they restore. Once the
        // window has passed, treat it like a suspended account.
        if ($user && $user->role === 'guest' && $user->isPendingDeletion()) {
            if (! $user->isRestorable()) {
                auth()->logout();
                return redirect()->route('login')->with('error', 'This account is no longer available.');
            }

            if (! $request->routeIs('guest.account.restore-prompt', 'guest.account.restore', 'logout')) {
                return redirect()->route('guest.account.restore-prompt');
            }
        }

        return $next($request);
    }
}
