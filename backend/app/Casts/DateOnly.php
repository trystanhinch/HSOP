<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Support\Carbon;

/**
 * Date-only cast that serializes to Y-m-d in JSON.
 *
 * Laravel's default `date` cast serializes as UTC midnight ISO-8601
 * (e.g. 2026-07-13T00:00:00.000000Z). Clients that parse that with
 * `new Date(...)` in Americas timezones display the previous calendar day.
 */
class DateOnly implements CastsAttributes, SerializesCastableAttributes
{
    public function get($model, string $key, $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay();
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    public function serialize($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }
}
