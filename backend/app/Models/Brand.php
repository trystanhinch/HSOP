<?php

namespace App\Models;

use App\Models\Concerns\HasTestData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasTestData;

    protected $fillable = [
        'domain',
        'slug',
        'company_name',
        'company_source_id',
        'service_categories',
        'branding',
        'contact_info',
        'seo_defaults',
        'content',
        'images',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_categories' => 'array',
            'branding' => 'array',
            'contact_info' => 'array',
            'seo_defaults' => 'array',
            'content' => 'array',
            'images' => 'array',
        ];
    }

    public function companySource(): BelongsTo
    {
        return $this->belongsTo(CompanySource::class);
    }

    public function intakeSessions(): HasMany
    {
        return $this->hasMany(IntakeSession::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function contentEditors(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function pageSeoOverrides(): HasMany
    {
        return $this->hasMany(BrandPageSeoOverride::class);
    }

    public function locationPages(): HasMany
    {
        return $this->hasMany(LocationPage::class);
    }

    public function customPages(): HasMany
    {
        return $this->hasMany(BrandPage::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Public image slots only (url + alt). Paths stay internal.
     *
     * @return array<string, mixed>
     */
    public function normalizedImages(): array
    {
        $raw = is_array($this->images) ? $this->images : [];
        $out = [
            'logo' => $this->publicImageSlot($raw['logo'] ?? null),
            'hero_image' => $this->publicImageSlot($raw['hero_image'] ?? null),
            'og_image' => $this->publicImageSlot($raw['og_image'] ?? null),
            'services' => [],
        ];

        $services = is_array($raw['services'] ?? null) ? $raw['services'] : [];
        foreach ($this->serviceCatalog() as $service) {
            $key = $service['key'];
            $slot = $this->publicImageSlot($services[$key] ?? null);
            if ($slot) {
                $out['services'][$key] = $slot;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return array{url: string, alt: string|null}|null
     */
    private function publicImageSlot(mixed $value): ?array
    {
        if (! is_array($value) || empty($value['url']) || ! is_string($value['url'])) {
            return null;
        }

        return [
            'url' => $value['url'],
            'alt' => isset($value['alt']) && is_string($value['alt']) && trim($value['alt']) !== ''
                ? trim($value['alt'])
                : null,
        ];
    }

    /**
     * Normalized service catalog for intake / prompts.
     *
     * @return list<array{key: string, label: string, keywords: list<string>, lede: string|null, points: list<string>}>
     */
    public function serviceCatalog(): array
    {
        $raw = $this->service_categories ?? [];
        $out = [];

        foreach ($raw as $item) {
            if (is_string($item)) {
                $label = trim($item);
                if ($label === '') {
                    continue;
                }
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
                $key = trim($key, '_') ?: 'service';
                $out[] = [
                    'key' => $key,
                    'label' => $label,
                    'keywords' => array_values(array_filter(array_map('strtolower', preg_split('/[\s\/,&]+/', $label) ?: []))),
                    'lede' => null,
                    'points' => [],
                ];

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? $item['name'] ?? ''));
            $key = trim((string) ($item['key'] ?? $item['slug'] ?? ''));
            if ($key === '' && $label !== '') {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
                $key = trim($key, '_');
            }
            if ($key === '') {
                continue;
            }

            $keywords = $item['keywords'] ?? [];
            if (! is_array($keywords) || $keywords === []) {
                $keywords = preg_split('/[\s\/,&]+/', $label !== '' ? $label : $key) ?: [];
            }
            $lede = isset($item['lede']) && is_string($item['lede'])
                ? trim($item['lede'])
                : null;
            $points = $item['points'] ?? [];
            if (! is_array($points)) {
                $points = [];
            }

            $out[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $key,
                'keywords' => array_values(array_unique(array_filter(array_map(
                    static fn ($k) => strtolower(trim((string) $k)),
                    $keywords
                )))),
                'lede' => $lede !== '' ? $lede : null,
                'points' => array_values(array_filter(array_map(
                    static fn ($point) => trim((string) $point),
                    $points
                ))),
            ];
        }

        return $out;
    }

    /**
     * Variables for AI prompt templates (never brand-hardcoded in code).
     *
     * @return array<string, string>
     */
    public function promptVariables(): array
    {
        $catalog = $this->serviceCatalog();
        $labels = array_map(fn ($c) => $c['label'], $catalog);
        $tone = (string) (($this->branding['tone'] ?? null) ?: 'friendly, professional, and concise');

        return [
            'company_name' => (string) $this->company_name,
            'domain' => (string) $this->domain,
            'services_list' => $labels !== [] ? implode(', ', $labels) : 'our services',
            'tone' => $tone,
            'support_email' => (string) ($this->contact_info['email'] ?? ''),
            'support_phone' => (string) ($this->contact_info['phone'] ?? ''),
        ];
    }

    /**
     * Public payload for SSR / client bootstrap (no secrets).
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $pageSeo = $this->pageSeoOverrides()
            ->get(['page_key', 'title', 'description', 'og_image'])
            ->mapWithKeys(fn (BrandPageSeoOverride $override) => [
                $override->page_key => [
                    'title' => $override->title,
                    'description' => $override->description,
                    'og_image' => $override->og_image,
                ],
            ])
            ->all();

        $locations = $this->locationPages()
            ->where('status', 'published')
            ->orderBy('city_name')
            ->get(['slug', 'city_name', 'region'])
            ->map(fn (LocationPage $page) => [
                'slug' => $page->slug,
                'city_name' => $page->city_name,
                'region' => $page->region,
            ])
            ->values()
            ->all();

        $pages = $this->customPages()
            ->where('status', 'published')
            ->orderBy('title')
            ->get(['slug', 'title', 'template_type'])
            ->map(fn (BrandPage $page) => [
                'slug' => $page->slug,
                'title' => $page->title,
                'template_type' => $page->template_type,
            ])
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'company_name' => $this->company_name,
            'service_categories' => $this->serviceCatalog(),
            'branding' => $this->branding ?? [],
            'contact_info' => $this->contact_info ?? [],
            'seo_defaults' => $this->seo_defaults ?? [],
            'content' => $this->content ?? [],
            'images' => $this->normalizedImages(),
            'page_seo' => $pageSeo,
            'locations' => $locations,
            'pages' => $pages,
        ];
    }
}
