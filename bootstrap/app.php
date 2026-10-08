<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'account.status' => \App\Http\Middleware\CheckAccountStatus::class,
            'log.activity' => \App\Http\Middleware\LogActivity::class,
            'auth.api' => \App\Http\Middleware\AuthenticateApiToken::class,
            'no.cache' => \App\Http\Middleware\PreventCachedResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Failed queries are still logged - but through SafeExceptionLog, which leaves out every bound value, so a
        // failing INSERT/UPDATE can never write a password hash, token or one-time code to the log file.
        $exceptions->report(function (\Illuminate\Database\QueryException $e) {
            [$message, $context] = \App\Support\SafeExceptionLog::forQueryException($e);
            \Illuminate\Support\Facades\Log::error($message, $context);
        })->stop();
    })->create();
