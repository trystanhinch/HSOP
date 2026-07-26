<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandCustomPageController extends Controller
{
    use ResolvesEditableBrand;

    public function index(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $rows = $brand->customPages()->orderByDesc('updated_at')->get()->map->publicPayload();

        return response()->json([
            'pages' => $rows,
            'templates' => BrandPage::TEMPLATE_TYPES,
            'duplicable_sources' => $this->duplicableSources($brand),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $this->validated($request, $brand->id);
        $page = $brand->customPages()->create($data);

        return response()->json(['page' => $page->publicPayload()], 201);
    }

    public function duplicate(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $request->validate([
            'source_key' => ['required', 'string', 'max:160'],
            'title' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brand_pages', 'slug')->where(fn ($q) => $q->where('brand_id', $brand->id)),
            ],
        ]);

        $seed = $this->seedFromSource($brand, $data['source_key']);
        $title = trim((string) ($data['title'] ?? '')) ?: ($seed['title'].' (copy)');
        $slug = $data['slug'] ?? Str::slug($title);
        if ($slug === '' || BrandPage::query()->where('brand_id', $brand->id)->where('slug', $slug)->exists()) {
            $slug = Str::slug($title).'-'.Str::lower(Str::random(4));
        }

        $page = $brand->customPages()->create([
            'title' => $title,
            'slug' => $slug,
            'template_type' => $seed['template_type'],
            'content' => $seed['content'],
            'seo_title' => $seed['seo_title'],
            'seo_description' => $seed['seo_description'],
            'og_image' => $seed['og_image'],
            'status' => 'draft',
            'source_key' => $data['source_key'],
        ]);

        return response()->json(['page' => $page->publicPayload()], 201);
    }

    public function update(Request $request, BrandPage $brandPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);
        $data = $this->validated($request, $brand->id, $brandPage->id);
        $brandPage->update($data);

        return response()->json(['page' => $brandPage->fresh()->publicPayload()]);
    }

    public function destroy(Request $request, BrandPage $brandPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);
        $brandPage->delete();

        return response()->json(['message' => 'Page deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $brandId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:160',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brand_pages', 'slug')
                    ->where(fn ($q) => $q->where('brand_id', $brandId))
                    ->ignore($ignoreId),
            ],
            'template_type' => ['required', Rule::in(BrandPage::TEMPLATE_TYPES)],
            'content' => ['sometimes', 'array'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'og_image' => ['nullable', 'string', 'max:2048'],
            'status' => ['sometimes', 'in:draft,published'],
            'source_key' => ['nullable', 'string', 'max:160'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['title']);
        if ($slug === '') {
            $slug = 'page-'.Str::random(6);
        }
        $data['slug'] = $slug;
        $data['status'] = $data['status'] ?? 'draft';
        $data['content'] = $data['content'] ?? [];

        return $data;
    }

    /**
     * @return list<array{key: string, label: string, template_type: string}>
     */
    private function duplicableSources(Brand $brand): array
    {
        $sources = [
            ['key' => 'system:home', 'label' => 'Home page', 'template_type' => 'home'],
            ['key' => 'system:quote', 'label' => 'Quote page', 'template_type' => 'quote'],
        ];
        foreach ($brand->serviceCatalog() as $service) {
            $sources[] = [
                'key' => 'system:service:'.$service['key'],
                'label' => 'Service: '.$service['label'],
                'template_type' => 'service',
            ];
        }
        foreach ($brand->customPages()->orderBy('title')->get(['id', 'title', 'template_type']) as $page) {
            $sources[] = [
                'key' => 'page:'.$page->id,
                'label' => 'Custom: '.$page->title,
                'template_type' => $page->template_type,
            ];
        }

        return $sources;
    }

    /**
     * @return array{title: string, template_type: string, content: array<string, mixed>, seo_title: ?string, seo_description: ?string, og_image: ?string}
     */
    private function seedFromSource(Brand $brand, string $sourceKey): array
    {
        if ($sourceKey === 'system:home') {
            return [
                'title' => $brand->company_name.' Home',
                'template_type' => 'home',
                'content' => [
                    'headline' => $brand->branding['hero_headline'] ?? 'Welcome',
                    'lede' => $brand->branding['hero_lede'] ?? '',
                    'cta_label' => $brand->branding['cta_label'] ?? 'Get a quote',
                    'services_intro' => $brand->branding['services_intro'] ?? ('What '.$brand->company_name.' fixes'),
                    'steps' => $brand->content['home']['steps'] ?? [],
                ],
                'seo_title' => null,
                'seo_description' => null,
                'og_image' => null,
            ];
        }

        if ($sourceKey === 'system:quote') {
            return [
                'title' => 'Get a quote',
                'template_type' => 'quote',
                'content' => [
                    'heading' => $brand->content['quote']['heading'] ?? 'Talk through the fix',
                    'lede' => $brand->content['quote']['lede'] ?? 'Describe what you see — photos help.',
                ],
                'seo_title' => null,
                'seo_description' => null,
                'og_image' => null,
            ];
        }

        if (str_starts_with($sourceKey, 'system:service:')) {
            $key = substr($sourceKey, strlen('system:service:'));
            $service = collect($brand->serviceCatalog())->firstWhere('key', $key);
            if (! $service) {
                abort(422, 'Unknown service source.');
            }

            return [
                'title' => $service['label'],
                'template_type' => 'service',
                'content' => [
                    'service_key' => $service['key'],
                    'label' => $service['label'],
                    'lede' => $service['lede'],
                    'points' => $service['points'],
                ],
                'seo_title' => null,
                'seo_description' => null,
                'og_image' => null,
            ];
        }

        if (str_starts_with($sourceKey, 'page:')) {
            $id = (int) substr($sourceKey, 5);
            $page = $brand->customPages()->find($id);
            if (! $page) {
                abort(422, 'Unknown page source.');
            }

            return [
                'title' => $page->title,
                'template_type' => $page->template_type,
                'content' => $page->content ?? [],
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'og_image' => $page->og_image,
            ];
        }

        abort(422, 'Invalid source_key.');
    }

    private function assertOwns(int $brandId, int $pageBrandId): void
    {
        if ($brandId !== $pageBrandId) {
            abort(403, 'Page does not belong to your assigned brand.');
        }
    }
}
