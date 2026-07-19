<?php

namespace App\Services\LeadIntake;

use App\Models\CompanySource;

class CompanySourceMatcher
{
    /**
     * Match by classified service category to the two real CompanySource groups.
     * Drywall → Fraser Valley Drywall; Insulation → Insulation Ethos.
     * Falls back to text matching for future granular sources.
     */
    public function matchByCategory(?string $serviceCategory): ?CompanySource
    {
        if ($serviceCategory === 'drywall_paint') {
            return CompanySource::query()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->where('company_name', 'Fraser Valley Drywall')
                        ->orWhereJsonContains('service_categories', 'Drywall');
                })
                ->orderBy('id')
                ->first();
        }

        if ($serviceCategory === 'insulation') {
            return CompanySource::query()
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->where('company_name', 'Insulation Ethos')
                        ->orWhereJsonContains('service_categories', 'Insulation');
                })
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    public function match(?string $sourceText): ?CompanySource
    {
        if ($sourceText === null || trim($sourceText) === '') {
            return null;
        }

        $normalized = $this->normalize($sourceText);

        // Category keyword shortcut when text clearly indicates group
        if (str_contains($normalized, 'drywall')) {
            $byCategory = $this->matchByCategory('drywall_paint');
            if ($byCategory) {
                return $byCategory;
            }
        }
        if (str_contains($normalized, 'insulation')) {
            $byCategory = $this->matchByCategory('insulation');
            if ($byCategory) {
                return $byCategory;
            }
        }

        $sources = CompanySource::query()->where('status', 'active')->get();

        foreach ($sources as $source) {
            $candidates = array_filter([
                $source->company_name,
                $source->sender_identity,
                $source->domain,
            ]);

            foreach ($candidates as $candidate) {
                if ($candidate && $this->matches($normalized, $this->normalize($candidate))) {
                    return $source;
                }
            }
        }

        foreach ($sources as $source) {
            if ($source->company_name && str_contains($normalized, $this->normalize($source->company_name))) {
                return $source;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/https?:\/\//', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function matches(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return $haystack === $needle || str_contains($haystack, $needle) || str_contains($needle, $haystack);
    }
}
