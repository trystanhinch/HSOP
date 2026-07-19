<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AiActionAuthorizer
{
    public function isOwnerOnly(string $action): bool
    {
        return in_array($action, config('ai_permissions.owner_only', []), true);
    }

    public function isAiForbidden(string $action): bool
    {
        return in_array($action, config('ai_permissions.ai_forbidden', []), true);
    }

    public function isAiAllowed(string $action): bool
    {
        return in_array($action, config('ai_permissions.ai_allowed', []), true);
    }

    public function canPerform(?User $actor, string $action): bool
    {
        if (! $actor) {
            return false;
        }

        if ($this->isOwnerOnly($action)) {
            return $actor->isOwner();
        }

        if ($actor->isOwner()) {
            return true;
        }

        if ($actor->isAiSuperAdmin()) {
            if ($this->isAiForbidden($action) || $this->isOwnerOnly($action)) {
                return false;
            }

            return $this->isAiAllowed($action);
        }

        return false;
    }

    public function assertCanPerform(?User $actor, string $action): void
    {
        if (! $this->canPerform($actor, $action)) {
            throw new AuthorizationException("Action [{$action}] is not authorized for this actor.");
        }
    }

    public function assertOwnerOnly(?User $actor, string $action): void
    {
        if (! $actor?->isOwner()) {
            throw new AuthorizationException('This action requires owner/admin privileges.');
        }

        if (! $this->isOwnerOnly($action)) {
            throw new AuthorizationException("Action [{$action}] is not registered as owner-only.");
        }
    }

    public function isAiEnabled(): bool
    {
        return ! Setting::getBool('ai_kill_switch', false);
    }

    public function assertAiEnabled(): void
    {
        if (! $this->isAiEnabled()) {
            throw new AuthorizationException('AI operations are paused (kill switch is on).');
        }
    }

    public function getModuleMode(string $module): string
    {
        $key = "ai_mode_{$module}";
        $mode = Setting::get($key, config('ai_actions.default_mode', 'suggestion'));
        $valid = config('ai_actions.modes', ['suggestion', 'assisted', 'autopilot']);

        return in_array($mode, $valid, true) ? $mode : 'suggestion';
    }

    public function canRunActionInMode(string $actionKey, string $module): bool
    {
        if (! $this->isAiEnabled()) {
            return false;
        }

        $registry = app(AiActionRegistry::class);
        $action = $registry->find($actionKey);

        if (! $action) {
            return false;
        }

        $mode = $this->getModuleMode($module);
        $modes = $action['modes_available'] ?? [];

        return in_array($mode, $modes, true);
    }
}
