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
            ->where('status', 'published')
            ->firstOrFail();

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
            ->where('status', 'published')
            ->firstOrFail();

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
            ['path' => '/', 'page_key' => 'home'],
            ['path' => '/quote', 'page_key' => 'quote'],
        ];
        foreach ($config['service_categories'] as $service) {
            $urls[] = [
                'path' => '/services/'.$service['key'],
                'page_key' => 'service:'.$service['key'],
            ];
        }
        $urls[] = ['path' => '/locations', 'page_key' => 'locations'];
        foreach ($config['locations'] as $location) {
            $urls[] = [
                'path' => '/locations/'.$location['slug'],
                'page_key' => 'location:'.$location['slug'],
            ];
        }
        foreach ($config['pages'] as $page) {
            $urls[] = [
                'path' => '/pages/'.$page['slug'],
                'page_key' => 'page:'.$page['slug'],
            ];
        }

        return response()->json([
            'brand' => [
                'id' => $brand->id,
                'domain' => $brand->domain,
                'company_name' => $brand->company_name,
            ],
            'urls' => $urls,
        ]);
    }
}
