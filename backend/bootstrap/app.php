<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/deploy.php'));
            Route::group([], base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // Before HandleCors so brand domains are in the allowlist for the same request
        $middleware->prepend(\App\Http\Middleware\RefreshBrandCorsOrigins::class);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'public.brand' => \App\Http\Middleware\ResolvePublicBrand::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Public + API routes must never HTML-redirect on validation errors
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
