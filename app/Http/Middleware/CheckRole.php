<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! auth()->check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->route('login');
        }

        if (in_array(auth()->user()->role, $roles)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        return redirect('/')->with('error', 'Unauthorized access');
    }
}
