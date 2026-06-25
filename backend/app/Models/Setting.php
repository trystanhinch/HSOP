<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function isGloballyEnabled(string $channel): bool
    {
        $key = $channel === 'sms' ? 'sms_globally_enabled' : 'email_globally_enabled';
        $legacyKey = $channel === 'sms' ? 'sms_enabled' : 'email_enabled';
        $value = static::get($key) ?? static::get($legacyKey, 'false');

        return $value === 'true' || $value === true || $value === '1';
    }
}
