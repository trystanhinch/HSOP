<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\LocationPage;
use App\Services\Content\ContentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandLocationPageController extends Controller
{
    use ResolvesEditableBrand;

    public function __construct(private ContentWorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $rows = $brand->locationPages()->orderBy('city_name')->get()->map->publicPayload();

        return response()->json([
            'locations' => $rows,
            'workflow_statuses' => ContentWorkflowService::STATUSES,
            'section_types' => ContentWorkflowService::SECTION_TYPES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $this->validated($request, $brand->id);
        // New pages always start as draft — publish via workflow after approval.
        $data['status'] = 'draft';
        $data['author_id'] = $request->user()->id;
        $data['revision_number'] = 1;

        $page = $brand->locationPages()->create($data);
        $this->workflow->recordRevision($page, $request->user(), 'created');

        return response()->json(['location' => $page->fresh()->publicPayload()], 201);
    }

    public function update(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);
        $data = $this->validated($request, $brand->id, $locationPage->id);

        // Content editors cannot mass-publish via status select — use workflow.
        if ($request->user()->role === 'content_editor') {
            unset($data['status']);
        } elseif (isset($data['status']) && in_array($data['status'], ['published', 'scheduled'], true)) {
            // Owners still go through publish guards when setting published directly.
            $locationPage->fill($data);
            $guard = $this->workflow->publishGuard(
                $locationPage,
                (bool) $request->boolean('acknowledge_duplicate')
            );
            if (! $guard['ok']) {
                return response()->json([
                    'message' => $guard['message'],
                    'empty' => $guard['empty'],
                    'duplicate_warning' => $guard['duplicate_warning'] ?? false,
                    'duplicates' => $guard['duplicates'],
                ], 422);
            }
        }

        $this->workflow->recordRevision($locationPage, $request->user(), 'updated');
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
            'sections' => ['sometimes', 'array'],
            'sections.*.type' => ['required_with:sections', Rule::in(ContentWorkflowService::SECTION_TYPES)],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.items' => ['nullable', 'array'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
            'canonical_url' => ['nullable', 'string', 'max:2048'],
            'schema_markup' => ['nullable', 'array'],
            'sitemap_include' => ['sometimes', 'boolean'],
            'robots_noindex' => ['sometimes', 'boolean'],
            'og_image' => ['nullable', 'string', 'max:2048'],
            'image_meta' => ['nullable', 'array'],
            'image_meta.alt' => ['nullable', 'string', 'max:255'],
            'image_meta.focal_x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image_meta.focal_y' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(ContentWorkflowService::STATUSES)],
            'scheduled_at' => ['nullable', 'date'],
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
        if (! array_key_exists('sitemap_include', $data)) {
            $data['sitemap_include'] = true;
        }

        return $data;
    }

    private function assertOwns(int $brandId, int $pageBrandId): void
    {
        if ($brandId !== $pageBrandId) {
            abort(403, 'Location page does not belong to your assigned brand.');
        }
    }
}
