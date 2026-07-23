<?php

namespace App\Services\Brands;

use App\Models\Brand;
use Illuminate\Http\Request;

class BrandResolver
{
    public function resolveFromRequest(Request $request): ?Brand
    {
        // Explicit override (local/testing only): honor exactly — do not fall through to localhost default.
        if (! app()->environment('production')) {
            $override = $request->header('X-Brand-Domain') ?: $request->query('brand_domain');
            if (is_string($override) && $override !== '') {
                return Brand::query()
                    ->where('status', 'active')
                    ->where('domain', $this->normalizeHost($override))
                    ->first();
            }
        }

        $candidates = $this->candidateHosts($request);

        foreach ($candidates as $host) {
            $brand = Brand::query()
                ->where('status', 'active')
                ->where('domain', $host)
                ->first();
            if ($brand) {
                return $brand;
            }
        }

        // Local/testing convenience: localhost → configured default brand domain
        if ($this->isLocalHost($request) && config('public.local_default_brand_domain')) {
            return Brand::query()
                ->where('status', 'active')
                ->where('domain', config('public.local_default_brand_domain'))
                ->first();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateHosts(Request $request): array
    {
        $hosts = [];

        foreach ([
            $request->header('X-Forwarded-Host'),
            $request->getHost(),
            parse_url((string) $request->headers->get('Origin'), PHP_URL_HOST),
            parse_url((string) $request->headers->get('Referer'), PHP_URL_HOST),
        ] as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            // X-Forwarded-Host may be a comma list
            foreach (explode(',', $raw) as $part) {
                $hosts[] = $this->normalizeHost($part);
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0]; // strip port
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    private function isLocalHost(Request $request): bool
    {
        $host = $this->normalizeHost($request->getHost());

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }
}
