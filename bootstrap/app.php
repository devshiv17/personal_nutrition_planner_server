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
        // Global middleware
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // API Middleware
        $middleware->api(prepend: [
            \App\Http\Middleware\ApiCors::class,
            \App\Http\Middleware\ApiVersion::class,
            \App\Http\Middleware\ApiLogger::class,
        ]);

        // Middleware aliases
        $middleware->alias([
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'api.throttle' => \App\Http\Middleware\ApiRateLimit::class,
            'login.rate.limit' => \App\Http\Middleware\LoginRateLimiter::class,
            'jwt.auth' => \App\Http\Middleware\JWTAuthMiddleware::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
            'session.security' => \App\Http\Middleware\SessionSecurity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
