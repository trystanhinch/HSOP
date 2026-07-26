<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content/branding fields only — no pricing, availability, or ops data.
 */
class BrandContentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);

        return response()->json($this->contentPayload($brand));
    }

    public function update(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $user = $request->user();

        $data = $request->validate([
            'branding' => ['sometimes', 'array'],
            'contact_info' => ['sometimes', 'array'],
            'seo_defaults' => ['sometimes', 'array'],
            'service_categories' => ['sometimes', 'array'],
            'service_categories.*.key' => ['required_with:service_categories', 'string', 'max:80'],
            'service_categories.*.label' => ['required_with:service_categories', 'string', 'max:120'],
            'service_categories.*.keywords' => ['sometimes', 'array'],
            'service_categories.*.keywords.*' => ['string', 'max:80'],
        ]);

        // Merge, never wholesale-replace: a partial payload must not silently
        // delete keys the caller did not send (e.g. brand theme tokens).
        if (array_key_exists('branding', $data)) {
            $brand->branding = $this->mergeJson($brand->branding ?? [], $data['branding']);
        }
        if (array_key_exists('contact_info', $data)) {
            $brand->contact_info = $this->mergeJson($brand->contact_info ?? [], $data['contact_info']);
        }
        if (array_key_exists('seo_defaults', $data)) {
            $brand->seo_defaults = $this->mergeJson($brand->seo_defaults ?? [], $data['seo_defaults']);
        }
        if (array_key_exists('service_categories', $data)) {
            if ($user->role === 'content_editor') {
                $brand->service_categories = $this->mergeServiceCategoryLabels(
                    $brand,
                    $data['service_categories']
                );
            } else {
                $brand->service_categories = $data['service_categories'];
            }
        }

        $brand->save();

        return response()->json($this->contentPayload($brand->fresh()));
    }

    private function resolveEditableBrand(Request $request): Brand
    {
        $user = $request->user();

        if ($user->role === 'content_editor') {
            if (! $user->brand_id) {
                abort(403, 'Content editor has no assigned brand.');
            }

            // Ignore any attempted brand_id override — scoped to assigned brand only.
            return Brand::query()->findOrFail($user->brand_id);
        }

        if ($user->role === 'owner') {
            $brandId = $request->query('brand_id') ?? $request->input('brand_id');
            if ($brandId) {
                return Brand::query()->findOrFail($brandId);
            }

            return Brand::query()->where('status', 'active')->orderBy('id')->firstOrFail();
        }

        abort(403, 'Unauthorized');
    }

    /**
     * Recursive merge for brand JSON blobs so omitted keys are preserved.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeJson(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                $isList = array_is_list($value);
                $existing[$key] = $isList
                    ? $value
                    : $this->mergeJson($existing[$key], $value);

                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    /**
     * Content editors may change labels/keywords on existing keys only —
     * cannot add, remove, or rename service category keys (ops/pricing coupling).
     *
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array{key: string, label: string, keywords: list<string>}>
     */
    private function mergeServiceCategoryLabels(Brand $brand, array $incoming): array
    {
        $byKey = [];
        foreach ($incoming as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $byKey[$key] = $item;
        }

        $out = [];
        foreach ($brand->serviceCatalog() as $existing) {
            $key = $existing['key'];
            $label = $existing['label'];
            $keywords = $existing['keywords'];

            if (isset($byKey[$key])) {
                $patch = $byKey[$key];
                if (isset($patch['label']) && is_string($patch['label']) && trim($patch['label']) !== '') {
                    $label = trim($patch['label']);
                }
                if (isset($patch['keywords']) && is_array($patch['keywords'])) {
                    $keywords = array_values(array_unique(array_filter(array_map(
                        static fn ($k) => strtolower(trim((string) $k)),
                        $patch['keywords']
                    ))));
                }
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'keywords' => $keywords,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function contentPayload(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'slug' => $brand->slug,
            'domain' => $brand->domain,
            'company_name' => $brand->company_name,
            'status' => $brand->status,
            'branding' => $brand->branding ?? [],
            'contact_info' => $brand->contact_info ?? [],
            'seo_defaults' => $brand->seo_defaults ?? [],
            'service_categories' => $brand->serviceCatalog(),
            'editable_fields' => [
                'branding',
                'contact_info',
                'seo_defaults',
                'service_categories',
            ],
        ];
    }
}
