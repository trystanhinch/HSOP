<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Milestone 6A Phase 4 — demo/milestone seeders must not run in production
 * and must not embed hardcoded interactive passwords.
 */
class DemoSeederGuardTest extends TestCase
{
    use DatabaseTransactions;

    private ?string $priorDemoSeedPassword = null;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorDemoSeedPassword = $_ENV['DEMO_SEED_PASSWORD']
            ?? $_SERVER['DEMO_SEED_PASSWORD']
            ?? getenv('DEMO_SEED_PASSWORD')
            ?: null;
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        $restore = $this->priorDemoSeedPassword ?? 'phpunit-demo-seed-only';
        putenv('DEMO_SEED_PASSWORD='.$restore);
        $_ENV['DEMO_SEED_PASSWORD'] = $restore;
        $_SERVER['DEMO_SEED_PASSWORD'] = $restore;
        parent::tearDown();
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        putenv('DEMO_SEED_PASSWORD=test-only-password');
        $_ENV['DEMO_SEED_PASSWORD'] = 'test-only-password';
        $_SERVER['DEMO_SEED_PASSWORD'] = 'test-only-password';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never execute in production');
        (new DemoSeeder)->run();
    }

    public function test_milestone4_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        putenv('DEMO_SEED_PASSWORD=test-only-password');
        $_ENV['DEMO_SEED_PASSWORD'] = 'test-only-password';
        $_SERVER['DEMO_SEED_PASSWORD'] = 'test-only-password';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never execute in production');
        (new Milestone4Seeder)->run();
    }

    public function test_demo_seeder_refuses_without_demo_seed_password(): void
    {
        $this->app['env'] = 'local';
        // Clear phpunit.xml default and process env so seeder sees empty.
        putenv('DEMO_SEED_PASSWORD');
        unset($_ENV['DEMO_SEED_PASSWORD'], $_SERVER['DEMO_SEED_PASSWORD']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_SEED_PASSWORD');
        (new DemoSeeder)->run();
    }
}
