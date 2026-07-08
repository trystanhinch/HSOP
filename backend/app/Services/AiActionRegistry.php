<?php

namespace App\Services;

use App\Models\AiActionType;
use Illuminate\Support\Collection;

class AiActionRegistry
{
    public function all(): Collection
    {
        return collect(config('ai_actions.actions', []))
            ->map(fn (array $def, string $key) => $this->formatEntry($key, $def));
    }

    public function find(string $actionKey): ?array
    {
        $def = config("ai_actions.actions.{$actionKey}");

        return $def ? $this->formatEntry($actionKey, $def) : null;
    }

    public function syncToDatabase(): void
    {
        foreach (config('ai_actions.actions', []) as $key => $def) {
            AiActionType::updateOrCreate(
                ['action_key' => $key],
                [
                    'label' => $def['label'],
                    'permission_level' => $def['permission_level'] ?? 'ai_super_admin',
                    'requires_human_approval' => $def['requires_human_approval'] ?? true,
                    'modes_available' => $def['modes_available'] ?? ['suggestion'],
                    'description' => $def['description'] ?? null,
                ]
            );
        }
    }

    protected function formatEntry(string $key, array $def): array
    {
        return [
            'action_key' => $key,
            'label' => $def['label'] ?? $key,
            'permission_level' => $def['permission_level'] ?? 'ai_super_admin',
            'requires_human_approval' => (bool) ($def['requires_human_approval'] ?? true),
            'modes_available' => $def['modes_available'] ?? ['suggestion'],
            'description' => $def['description'] ?? null,
        ];
    }
}
