<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refresh CORS allowlist from active brand domains before HandleCors runs.
 * Runs on every request (cached) so newly added brands work without app restart.
 */
class RefreshBrandCorsOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (Schema::hasTable('brands')) {
                $brandOrigins = Cache::remember('cors.active_brand_origins', 60, function () {
                    return Brand::query()
                        ->where('status', 'active')
                        ->pluck('domain')
                        ->filter()
                        ->flatMap(fn (string $domain) => [
                            'https://'.$domain,
                            'http://'.$domain,
                            'https://www.'.$domain,
                            'http://www.'.$domain,
                        ])
                        ->values()
                        ->all();
                });

                $merged = array_values(array_unique(array_filter(array_merge(
                    config('cors.allowed_origins', []),
                    config('public.extra_cors_origins', []),
                    $brandOrigins,
                ))));

                config(['cors.allowed_origins' => $merged]);
                config(['cors.allowed_origins_patterns' => []]);
            }
        } catch (\Throwable) {
            // ignore during migrate / early boot
        }

        return $next($request);
    }
}
