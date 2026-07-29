<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A-14 — Suspended / inactive users lose API access immediately.
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ($user->status ?? 'active') !== 'active') {
            // Best-effort: drop remaining tokens if somehow still authenticated.
            try {
                $user->tokens()->delete();
            } catch (\Throwable) {
            }

            return response()->json([
                'message' => 'Account suspended. Contact an administrator.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
