<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevents the browser (and its back-forward cache) from redisplaying an
 * authenticated page after logout - session invalidation alone doesn't stop
 * bfcache restoring a previously rendered page without hitting the server.
 */
class PreventCachedResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
