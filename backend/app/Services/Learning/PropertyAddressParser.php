<?php

namespace App\Services\Learning;

use App\Models\Property;
use App\Models\Region;

/**
 * Best-effort parse of free-text Canadian addresses into property components.
 * Preserves raw_address always; never invents missing values.
 */
class PropertyAddressParser
{
    /**
     * @return array{
     *   raw_address: string,
     *   street: ?string,
     *   city: ?string,
     *   postal_code: ?string,
     *   region_id: ?int,
     *   property_type: ?string
     * }
     */
    public function parse(string $raw, ?string $propertyType = null): array
    {
        $raw = trim($raw);
        $postal = null;
        if (preg_match('/\b([A-Za-z]\d[A-Za-z]\s?\d[A-Za-z]\d)\b/', $raw, $m)) {
            $postal = strtoupper(preg_replace('/\s+/', ' ', trim($m[1])));
            if (strlen(str_replace(' ', '', $postal)) === 6 && ! str_contains($postal, ' ')) {
                $postal = substr($postal, 0, 3).' '.substr($postal, 3);
            }
        }

        $city = null;
        $regionId = null;
        $regions = Region::query()->orderBy('sort_order')->get(['id', 'name']);
        foreach ($regions as $region) {
            if (preg_match('/\b'.preg_quote($region->name, '/').'\b/i', $raw)) {
                $city = $region->name;
                $regionId = $region->id;
                break;
            }
        }

        $street = $raw;
        if ($postal) {
            $street = trim(str_ireplace($postal, '', $street));
        }
        if ($city) {
            $street = trim(preg_replace('/,?\s*'.preg_quote($city, '/').'\b.*$/i', '', $street));
        }
        $street = trim($street, " \t\n\r\0\x0B,");
        if ($street === '' || strcasecmp($street, $raw) === 0) {
            // Could not confidently separate street — leave null rather than invent
            $street = ($city || $postal) ? ($street !== '' && strcasecmp($street, $raw) !== 0 ? $street : null) : null;
        }

        $type = $propertyType;
        if ($type !== null && ! in_array($type, ['residential', 'commercial'], true)) {
            $type = null;
        }

        return [
            'raw_address' => $raw,
            'street' => $street ?: null,
            'city' => $city,
            'postal_code' => $postal,
            'region_id' => $regionId,
            'property_type' => $type,
        ];
    }

    /**
     * Find-or-create a Property from a raw address string (idempotent on raw_address).
     */
    public function resolveProperty(?string $raw, ?string $propertyType = null): ?Property
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parsed = $this->parse($raw, $propertyType);
        $existing = Property::query()->where('raw_address', $parsed['raw_address'])->first();
        if ($existing) {
            return $existing;
        }

        return Property::create($parsed);
    }
}
