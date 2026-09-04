<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'kyc.verified' => \App\Http\Middleware\EnsureKycVerified::class,
        ]);

        // Every API route gets an optional user resolve so public endpoints can
        // vary their response for signed-in callers without requiring a token.
        $middleware->appendToGroup('api', \App\Http\Middleware\ResolveOptionalUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
