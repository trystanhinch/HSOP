<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Milestone 6A Phase 4 — secret hygiene for demo / milestone seeders.
 * Production is a hard stop. Demo passwords come from DEMO_SEED_PASSWORD only.
 */
trait GuardsDemoSeedExecution
{
    protected function assertDemoSeedAllowed(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                static::class.' refused to run: demo/milestone seeders must never execute in production.'
            );
        }
    }

    /**
     * Password for seeded interactive demo users. Must be set explicitly via DEMO_SEED_PASSWORD.
     */
    protected function demoSeedPassword(): string
    {
        $password = (string) env('DEMO_SEED_PASSWORD', '');
        if ($password === '') {
            throw new RuntimeException(
                static::class.' refused to run: set DEMO_SEED_PASSWORD in the environment before seeding interactive demo users. '
                .'This value is never hardcoded in the repository.'
            );
        }

        return $password;
    }
}
