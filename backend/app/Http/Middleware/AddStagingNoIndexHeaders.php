<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 6A.2 — prevent staging from being indexed / cached by crawlers.
 * No-op when staging_mode is false.
 */
class AddStagingNoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (config('app.staging_mode')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('X-ServiceOP-Environment', 'staging');
        }

        return $response;
    }
}
