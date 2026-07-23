<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = [
        'domain',
        'slug',
        'company_name',
        'company_source_id',
        'service_categories',
        'branding',
        'contact_info',
        'seo_defaults',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_categories' => 'array',
            'branding' => 'array',
            'contact_info' => 'array',
            'seo_defaults' => 'array',
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

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Normalized service catalog for intake / prompts.
     *
     * @return list<array{key: string, label: string, keywords: list<string>}>
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

            $out[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $key,
                'keywords' => array_values(array_unique(array_filter(array_map(
                    static fn ($k) => strtolower(trim((string) $k)),
                    $keywords
                )))),
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
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'company_name' => $this->company_name,
            'service_categories' => $this->serviceCatalog(),
            'branding' => $this->branding ?? [],
            'contact_info' => $this->contact_info ?? [],
            'seo_defaults' => $this->seo_defaults ?? [],
        ];
    }
}
