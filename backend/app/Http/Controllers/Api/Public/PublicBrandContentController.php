<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandPage;
use App\Models\LocationPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBrandContentController extends Controller
{
    public function location(Request $request, string $slug): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $page = LocationPage::query()
            ->where('brand_id', $brand->id)
            ->where('slug', $slug)
            ->firstOrFail();

        if (! $page->isPublished()) {
            abort(404);
        }

        return response()->json([
            'location' => $page->publicPayload(),
            'brand' => $brand->publicConfig(),
        ]);
    }

    public function page(Request $request, string $slug): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $page = BrandPage::query()
            ->where('brand_id', $brand->id)
            ->where('slug', $slug)
            ->firstOrFail();

        if (! $page->isPublished()) {
            abort(404);
        }

        return response()->json([
            'page' => $page->publicPayload(),
            'brand' => $brand->publicConfig(),
        ]);
    }

    public function sitemap(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $config = $brand->publicConfig();

        $urls = [
            ['path' => '/', 'page_key' => 'home', 'label' => 'Home page'],
            ['path' => '/quote', 'page_key' => 'quote', 'label' => 'Quote page'],
        ];
        foreach ($config['service_categories'] as $service) {
            $urls[] = [
                'path' => '/services/'.$service['key'],
                'page_key' => 'service:'.$service['key'],
                'label' => $service['label'] ?? $service['key'],
            ];
        }
        $urls[] = ['path' => '/locations', 'page_key' => 'locations', 'label' => 'Locations'];
        foreach ($config['locations'] as $location) {
            if (($location['sitemap_include'] ?? true) === false) {
                continue;
            }
            $urls[] = [
                'path' => '/locations/'.$location['slug'],
                'page_key' => 'location:'.$location['slug'],
                'label' => $location['city_name'] ?? $location['slug'],
                'canonical_url' => $location['canonical_url'] ?? null,
            ];
        }
        foreach ($config['pages'] as $page) {
            if (($page['sitemap_include'] ?? true) === false) {
                continue;
            }
            $urls[] = [
                'path' => '/pages/'.$page['slug'],
                'page_key' => 'page:'.$page['slug'],
                'label' => $page['title'] ?? $page['slug'],
                'canonical_url' => $page['canonical_url'] ?? null,
            ];
        }

        $redirects = \App\Models\BrandRedirect::query()
            ->where('brand_id', $brand->id)
            ->where('is_active', true)
            ->get(['from_path', 'to_path', 'status_code']);

        return response()->json([
            'brand' => [
                'id' => $brand->id,
                'domain' => $brand->domain,
                'company_name' => $brand->company_name,
            ],
            'urls' => $urls,
            'redirects' => $redirects,
            'robots' => [
                'allow' => ['/'],
                'disallow' => [],
                'sitemap' => '/api/public/sitemap',
            ],
        ]);
    }
}
