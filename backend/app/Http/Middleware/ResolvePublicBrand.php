<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Services\Brands\BrandResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicBrand
{
    public function __construct(private BrandResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $brand = $this->resolver->resolveFromRequest($request);

        if (! $brand instanceof Brand) {
            return response()->json([
                'message' => 'Unknown or inactive brand for this domain',
                'error' => 'brand_not_found',
            ], 404);
        }

        $request->attributes->set('brand', $brand);
        app()->instance(Brand::class, $brand);

        return $next($request);
    }
}
