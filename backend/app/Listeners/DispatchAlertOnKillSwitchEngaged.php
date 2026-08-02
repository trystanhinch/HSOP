<?php

namespace App\Listeners;

use App\Services\Monitoring\AlertDispatcher;

/**
 * Phase 10 — gateway kill switch engaged (ON) → AlertDispatcher.
 * OFF toggles must not call this.
 */
class DispatchAlertOnKillSwitchEngaged
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(string $gateway, bool $enabled, ?int $actorId = null): void
    {
        if (! $enabled) {
            return;
        }

        $this->dispatcher->dispatch('high', ucfirst($gateway).' gateway kill switch engaged', [
            'source' => 'gateway.kill_switch_engaged',
            'gateway' => $gateway,
            'actor_id' => $actorId,
        ]);
    }
}
