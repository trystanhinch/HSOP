<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function versions(): HasMany
    {
        return $this->hasMany(MessageTemplateVersion::class);
    }

    /**
     * Render an active template. Returns null when the template exists but is inactive
     * (A-16: inactive templates must not fall through to hardcoded fallbacks).
     */
    public static function render(string $eventKey, array $vars, ?string $fallback = null): ?string
    {
        $template = static::query()->where('event_key', $eventKey)->first();

        if ($template && ! $template->is_active) {
            return null;
        }

        $body = $template?->body ?? $fallback ?? '';
        if ($body === '') {
            return $template ? '' : ($fallback ?? '');
        }

        foreach ($vars as $key => $value) {
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
        }

        // Customer never receives raw {{variable}} text.
        return preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $body) ?? $body;
    }

    public static function isInactive(string $eventKey): bool
    {
        $template = static::query()->where('event_key', $eventKey)->first();

        return $template !== null && ! $template->is_active;
    }
}
