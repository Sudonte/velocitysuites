<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // GET/HEAD requests are page views, not activity - and anything
        // that already called Support\Activity::log() itself has written
        // a more meaningful row than this generic fallback ever could, so
        // skip both to keep the dashboard activity feeds signal, not noise.
        if (auth()->check() && ! $request->isMethod('GET') && ! $request->isMethod('HEAD') && ! $request->attributes->get('activity_logged')) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $request->method() . ' ' . $request->path(),
                'description' => $request->getQueryString(),
                'ip_address' => $request->ip(),
            ]);
        }

        return $response;
    }
}
