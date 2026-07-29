<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * A-23 — Database diagnostics require owner + is_developer + recent password unlock.
 */
class EnsureDeveloper
{
    public const UNLOCK_CACHE_PREFIX = 'developer_unlock:';

    public const UNLOCK_TTL_MINUTES = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== 'owner' || ! $user->is_developer) {
            return response()->json([
                'message' => 'Developer permission required.',
                'code' => 'developer_required',
            ], 403);
        }

        $unlocked = Cache::get(self::UNLOCK_CACHE_PREFIX.$user->id);
        if (! $unlocked) {
            return response()->json([
                'message' => 'Re-authenticate to access developer diagnostics.',
                'code' => 'developer_reauth_required',
            ], 403);
        }

        return $next($request);
    }

    public static function unlock(int $userId): void
    {
        Cache::put(self::UNLOCK_CACHE_PREFIX.$userId, true, now()->addMinutes(self::UNLOCK_TTL_MINUTES));
    }

    public static function isUnlocked(int $userId): bool
    {
        return (bool) Cache::get(self::UNLOCK_CACHE_PREFIX.$userId);
    }
}
