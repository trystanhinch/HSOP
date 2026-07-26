<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content editors may ONLY hit brand-content + auth identity endpoints.
 * All other authenticated API routes return 403 — enforced here, not just UI.
 */
class RestrictContentEditor
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'api.me',
        'api.logout',
        'api.brand-content.show',
        'api.brand-content.update',
    ];

    /** @var list<string> path suffixes under /api (fallback if routes are unnamed) */
    private const ALLOWED_PATHS = [
        'me',
        'logout',
        'brand-content',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== 'content_editor') {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($path === $allowed) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Unauthorized. Content editors can only access brand content for their assigned brand.',
        ], 403);
    }
}
