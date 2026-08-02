<?php

namespace App\Console\Commands;

use App\Services\Staging\StagingIsolationGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Milestone 6A.2 — reset staging DB to a seeded baseline.
 * Hard-stops unless STAGING_MODE=true AND environment is not production.
 */
class StagingResetCommand extends Command
{
    protected $signature = 'staging:reset
                            {--force : Required confirmation — refuse without it}
                            {--skip-seed : migrate:fresh only (no seeders)}';

    protected $description = 'Reset staging database (migrate:fresh + baseline seeders). Staging-only.';

    public function handle(): int
    {
        try {
            $this->assertSafeToReset();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to reset without --force (destructive: migrate:fresh).');
            $this->line('Example: php artisan staging:reset --force');

            return self::FAILURE;
        }

        $this->warn('Running migrate:fresh on staging…');
        $exit = Artisan::call('migrate:fresh', ['--force' => true]);
        $this->output->write(Artisan::output());
        if ($exit !== 0) {
            $this->error('migrate:fresh failed.');

            return self::FAILURE;
        }

        if ($this->option('skip-seed')) {
            $this->info('Skip-seed: schema only. Done.');

            return self::SUCCESS;
        }

        if (! env('DEMO_SEED_PASSWORD')) {
            $this->error('DEMO_SEED_PASSWORD must be set to seed interactive demo users.');

            return self::FAILURE;
        }

        $seeders = [
            \Database\Seeders\SettingsSeeder::class,
            \Database\Seeders\Milestone4Seeder::class,
            \Database\Seeders\MessageTemplateSeeder::class,
            \Database\Seeders\DemoSeeder::class,
        ];

        $summary = [];
        foreach ($seeders as $seeder) {
            $this->line('Seeding '.$seeder.'…');
            $code = Artisan::call('db:seed', [
                '--class' => $seeder,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
            $summary[] = [
                'seeder' => class_basename($seeder),
                'ok' => $code === 0,
            ];
            if ($code !== 0) {
                $this->error("Seeder failed: {$seeder}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Staging reset complete.');
        $this->table(['Seeder', 'Status'], array_map(
            fn ($row) => [$row['seeder'], $row['ok'] ? 'OK' : 'FAIL'],
            $summary
        ));
        $this->comment('Next: php artisan staging:verify-isolation');

        return self::SUCCESS;
    }

    private function assertSafeToReset(): void
    {
        if (! config('app.staging_mode')) {
            throw new RuntimeException(
                'staging:reset refused: config(app.staging_mode) is false. Set STAGING_MODE=true on the staging app only.'
            );
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'staging:reset refused: APP_ENV is production (independent of STAGING_MODE).'
            );
        }

        // Defense in depth — same guard DemoSeeder uses.
        app(StagingIsolationGuard::class)->assertBootSafe();
    }
}
