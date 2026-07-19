<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = [
        'event_key',
        'channel',
        'label',
        'body',
        'variables',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function render(string $eventKey, array $vars, ?string $fallback = null): string
    {
        $template = static::query()->where('event_key', $eventKey)->where('is_active', true)->first();
        $body = $template?->body ?? $fallback ?? '';

        foreach ($vars as $key => $value) {
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
        }

        // Strip any leftover placeholders
        return preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $body) ?? $body;
    }
}
