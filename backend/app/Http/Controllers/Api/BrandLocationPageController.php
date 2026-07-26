<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\LocationPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandLocationPageController extends Controller
{
    use ResolvesEditableBrand;

    public function index(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $rows = $brand->locationPages()->orderBy('city_name')->get()->map->publicPayload();

        return response()->json(['locations' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $this->validated($request, $brand->id);

        $page = $brand->locationPages()->create($data);

        return response()->json(['location' => $page->publicPayload()], 201);
    }

    public function update(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);
        $data = $this->validated($request, $brand->id, $locationPage->id);
        $locationPage->update($data);

        return response()->json(['location' => $locationPage->fresh()->publicPayload()]);
    }

    public function destroy(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);
        $locationPage->delete();

        return response()->json(['message' => 'Location page deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $brandId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'city_name' => ['required', 'string', 'max:160'],
            'region' => ['nullable', 'string', 'max:160'],
            'slug' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('location_pages', 'slug')
                    ->where(fn ($q) => $q->where('brand_id', $brandId))
                    ->ignore($ignoreId),
            ],
            'content' => ['sometimes', 'array'],
            'content.headline' => ['nullable', 'string', 'max:255'],
            'content.body' => ['nullable', 'string', 'max:10000'],
            'content.cta_label' => ['nullable', 'string', 'max:120'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $slug = $data['slug'] ?? null;
        if (! $slug) {
            $slug = Str::slug($data['city_name'].'-'.($data['region'] ?? ''));
        }
        if ($slug === '') {
            $slug = 'location-'.Str::lower(Str::random(6));
        }

        $query = LocationPage::query()
            ->where('brand_id', $brandId)
            ->where('slug', $slug);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            $slug = $slug.'-'.Str::lower(Str::random(4));
        }

        $data['slug'] = $slug;
        $data['status'] = $data['status'] ?? 'draft';
        $data['content'] = $data['content'] ?? [];

        return $data;
    }

    private function assertOwns(int $brandId, int $pageBrandId): void
    {
        if ($brandId !== $pageBrandId) {
            abort(403, 'Location page does not belong to your assigned brand.');
        }
    }
}
