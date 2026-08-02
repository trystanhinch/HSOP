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
        // Milestone 6A.4 — correlation ID on every API request (ARC-07 foundation)
        $middleware->appendToGroup('api', \App\Http\Middleware\AssignCorrelationId::class);
        // Milestone 6A.2 — staging-only gates (no-op when STAGING_MODE is false); global so /up is covered
        $middleware->append(\App\Http\Middleware\RequireStagingBasicAuth::class);
        $middleware->append(\App\Http\Middleware\AddStagingNoIndexHeaders::class);
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'public.brand' => \App\Http\Middleware\ResolvePublicBrand::class,
            'restrict.content_editor' => \App\Http\Middleware\RestrictContentEditor::class,
            'active.user' => \App\Http\Middleware\EnsureActiveUser::class,
            'developer' => \App\Http\Middleware\EnsureDeveloper::class,
            'review.ai' => \App\Http\Middleware\EnsureReviewAiAbility::class,
            'review.gateway.log' => \App\Http\Middleware\LogReviewGatewayAccess::class,
            'learning.ai' => \App\Http\Middleware\EnsureLearningAiAbility::class,
            'learning.gateway.log' => \App\Http\Middleware\LogLearningGatewayAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Public + API routes must never HTML-redirect on validation errors
        $exceptions->shouldRenderJsonWhen(function (\Illuminate\Http\Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Milestone 6A.4 — attach correlation ID even when auth/exceptions short-circuit the middleware return
        $exceptions->respond(function ($response, \Throwable $e, $request) {
            $correlationId = $request->attributes->get('correlation_id');
            if (is_string($correlationId) && $correlationId !== '' && ! $response->headers->has('X-Correlation-Id')) {
                $response->headers->set('X-Correlation-Id', $correlationId);
            }

            return $response;
        });
    })->create();
