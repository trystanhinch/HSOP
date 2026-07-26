<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandPageSeoOverride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content/branding fields only — no pricing, availability, or ops data.
 */
class BrandContentController extends Controller
{
    use ResolvesEditableBrand;
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
            'content' => ['sometimes', 'array'],
            'content.header' => ['sometimes', 'array'],
            'content.header.quote_cta_label' => ['sometimes', 'string', 'max:120'],
            'content.header.call_label' => ['sometimes', 'string', 'max:80'],
            'content.home' => ['sometimes', 'array'],
            'content.home.details_label' => ['sometimes', 'string', 'max:120'],
            'content.home.steps' => ['sometimes', 'array', 'size:3'],
            'content.home.steps.*.eyebrow' => ['required_with:content.home.steps', 'string', 'max:120'],
            'content.home.steps.*.title' => ['required_with:content.home.steps', 'string', 'max:160'],
            'content.home.steps.*.description' => ['required_with:content.home.steps', 'string', 'max:500'],
            'content.home.licensed_label' => ['sometimes', 'string', 'max:120'],
            'content.home.insured_label' => ['sometimes', 'string', 'max:120'],
            'content.home.serving_prefix' => ['sometimes', 'string', 'max:80'],
            'content.home.trust_fallback' => ['sometimes', 'string', 'max:240'],
            'content.home.bottom_cta_label' => ['sometimes', 'string', 'max:120'],
            'content.home.intake_headline' => ['sometimes', 'string', 'max:200'],
            'content.home.intake_lede' => ['sometimes', 'string', 'max:500'],
            'content.home.mode_type_label' => ['sometimes', 'string', 'max:80'],
            'content.home.mode_talk_label' => ['sometimes', 'string', 'max:80'],
            'content.home.mode_upload_label' => ['sometimes', 'string', 'max:80'],
            'content.home.go_label' => ['sometimes', 'string', 'max:80'],
            'content.home.manual_quote_label' => ['sometimes', 'string', 'max:160'],
            'content.home.reassurance' => ['sometimes', 'string', 'max:400'],
            'content.home.call_label' => ['sometimes', 'string', 'max:80'],
            'content.home.composer_placeholder' => ['sometimes', 'string', 'max:200'],
            'content.service' => ['sometimes', 'array'],
            'content.service.home_label' => ['sometimes', 'string', 'max:80'],
            'content.service.request_prefix' => ['sometimes', 'string', 'max:80'],
            'content.quote' => ['sometimes', 'array'],
            'content.quote.heading' => ['sometimes', 'string', 'max:160'],
            'content.quote.lede' => ['sometimes', 'string', 'max:800'],
            'content.footer' => ['sometimes', 'array'],
            'content.footer.fallback_label' => ['sometimes', 'string', 'max:160'],
            'service_categories' => ['sometimes', 'array'],
            'service_categories.*.key' => ['required_with:service_categories', 'string', 'max:80'],
            'service_categories.*.label' => ['required_with:service_categories', 'string', 'max:120'],
            'service_categories.*.keywords' => ['sometimes', 'array'],
            'service_categories.*.keywords.*' => ['string', 'max:80'],
            'service_categories.*.lede' => ['nullable', 'string', 'max:1200'],
            'service_categories.*.points' => ['sometimes', 'array', 'max:12'],
            'service_categories.*.points.*' => ['string', 'max:240'],
            'seo_pages' => ['sometimes', 'array'],
            'seo_pages.*.page_key' => ['required_with:seo_pages', 'string', 'max:120'],
            'seo_pages.*.title' => ['nullable', 'string', 'max:255'],
            'seo_pages.*.description' => ['nullable', 'string', 'max:1000'],
            'seo_pages.*.og_image' => ['nullable', 'string', 'max:2048'],
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
        if (array_key_exists('content', $data)) {
            $brand->content = $this->mergeJson($brand->content ?? [], $data['content']);
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
        if (array_key_exists('seo_pages', $data)) {
            $this->syncPageSeo($brand, $data['seo_pages']);
        }

        return response()->json($this->contentPayload($brand->fresh()));
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
     * Content editors may change copy on existing services only —
     * cannot add, remove, or rename service category keys (ops/pricing coupling).
     *
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array{key: string, label: string, keywords: list<string>, lede: string|null, points: list<string>}>
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
            $lede = $existing['lede'];
            $points = $existing['points'];

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
                if (array_key_exists('lede', $patch)) {
                    $value = trim((string) ($patch['lede'] ?? ''));
                    $lede = $value !== '' ? $value : null;
                }
                if (isset($patch['points']) && is_array($patch['points'])) {
                    $points = array_values(array_filter(array_map(
                        static fn ($point) => trim((string) $point),
                        $patch['points']
                    )));
                }
            }

            $out[] = [
                'key' => $key,
                'label' => $label,
                'keywords' => $keywords,
                'lede' => $lede,
                'points' => $points,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncPageSeo(Brand $brand, array $items): void
    {
        $allowedKeys = $this->allowedSeoPageKeys($brand);

        foreach ($items as $item) {
            $pageKey = trim((string) ($item['page_key'] ?? ''));
            if (! in_array($pageKey, $allowedKeys, true)) {
                abort(422, "Invalid page_key [{$pageKey}] for this brand.");
            }

            $values = [
                'title' => $this->nullableTrimmed($item['title'] ?? null),
                'description' => $this->nullableTrimmed($item['description'] ?? null),
                'og_image' => $this->nullableTrimmed($item['og_image'] ?? null),
            ];

            if ($values['title'] === null && $values['description'] === null && $values['og_image'] === null) {
                $brand->pageSeoOverrides()->where('page_key', $pageKey)->delete();

                continue;
            }

            $brand->pageSeoOverrides()->updateOrCreate(
                ['page_key' => $pageKey],
                $values
            );
        }
    }

    /**
     * @return list<string>
     */
    private function allowedSeoPageKeys(Brand $brand): array
    {
        return [
            'home',
            'quote',
            ...array_map(
                static fn (array $service) => 'service:'.$service['key'],
                $brand->serviceCatalog()
            ),
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
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
            'content' => $brand->content ?? [],
            'images' => $brand->normalizedImages(),
            'image_slots' => [
                'logo',
                'hero_image',
                'og_image',
                ...array_map(
                    static fn (array $service) => 'service:'.$service['key'],
                    $brand->serviceCatalog()
                ),
            ],
            'service_categories' => $brand->serviceCatalog(),
            'locations' => $brand->locationPages()->orderBy('city_name')->get()->map->publicPayload()->values()->all(),
            'pages' => $brand->customPages()->orderByDesc('updated_at')->get()->map->publicPayload()->values()->all(),
            'seo_pages' => array_map(function (string $pageKey) use ($brand) {
                /** @var BrandPageSeoOverride|null $override */
                $override = $brand->pageSeoOverrides()
                    ->where('page_key', $pageKey)
                    ->first();

                return [
                    'page_key' => $pageKey,
                    'title' => $override?->title,
                    'description' => $override?->description,
                    'og_image' => $override?->og_image,
                ];
            }, $this->allowedSeoPageKeys($brand)),
            'editable_fields' => [
                'branding',
                'contact_info',
                'seo_defaults',
                'content',
                'images',
                'service_categories',
                'seo_pages',
                'locations',
                'pages',
            ],
        ];
    }
}
