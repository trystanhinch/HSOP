<?php

namespace Tests\Feature\Staging;

use App\Http\Middleware\RequireStagingBasicAuth;
use App\Services\Staging\StagingIsolationGuard;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * Milestone 6A.2 — staging reset / isolation / Basic Auth safety.
 */
class StagingFoundationTest extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('app.staging_mode', false);
        $app['config']->set('payment.stripe.secret', 'sk_test_dummy');

        return $app;
    }

    public function test_1_staging_reset_refuses_when_staging_mode_false(): void
    {
        config(['app.staging_mode' => false]);
        $this->app['env'] = 'staging';

        $this->artisan('staging:reset', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('staging_mode');
    }

    public function test_2_staging_reset_refuses_when_environment_is_production(): void
    {
        config(['app.staging_mode' => true]);
        $this->app['env'] = 'production';

        $this->artisan('staging:reset', ['--force' => true])
            ->assertFailed()
            ->expectsOutputToContain('production');
    }

    public function test_3_boot_fail_closed_on_live_stripe_key_when_staging_mode(): void
    {
        config([
            'app.staging_mode' => true,
            'payment.stripe.secret' => 'sk_live_THIS_MUST_NEVER_BOOT',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STAGING FAIL-CLOSED');
        app(StagingIsolationGuard::class)->assertBootSafe();
    }

    public function test_4_boot_safe_with_test_stripe_key_when_staging_mode(): void
    {
        config([
            'app.staging_mode' => true,
            'payment.stripe.secret' => 'sk_test_ok_for_staging',
        ]);

        app(StagingIsolationGuard::class)->assertBootSafe();
        $this->assertTrue(true);
    }

    public function test_5_verify_isolation_flags_live_stripe_and_passes_with_test_key(): void
    {
        config([
            'app.staging_mode' => true,
            'payment.stripe.secret' => 'sk_live_bad',
            'payment.provider' => 'stripe',
            'services.sms.enabled' => false,
            'mail.default' => 'log',
            'staging.basic_auth_user' => 'stage',
            'staging.basic_auth_password' => 'secret',
            'staging.forbidden_production_db_names' => ['totally_not_this_db'],
            'staging.forbidden_production_db_hosts' => [],
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'serviceop_staging_test',
            'database.connections.mysql.host' => '127.0.0.1',
        ]);
        $this->app['env'] = 'staging';

        $guard = app(StagingIsolationGuard::class);
        $issues = $guard->verify();
        $codes = array_column($issues, 'code');
        $this->assertContains('stripe_live_key', $codes);
        $this->assertTrue($guard->hasFailures($issues));

        $this->artisan('staging:verify-isolation')->assertFailed();

        config(['payment.stripe.secret' => 'sk_test_good']);
        $okIssues = $guard->verify();
        $okCodes = array_column($okIssues, 'code');
        $this->assertNotContains('stripe_live_key', $okCodes);
        $this->assertFalse($guard->hasFailures($okIssues));

        $this->artisan('staging:verify-isolation')->assertSuccessful();
    }

    public function test_6_basic_auth_required_only_when_staging_mode_true(): void
    {
        $middleware = app(RequireStagingBasicAuth::class);

        config([
            'app.staging_mode' => false,
            'staging.basic_auth_user' => 'stage',
            'staging.basic_auth_password' => 'secret',
        ]);
        $passed = false;
        $middleware->handle(Request::create('/api/admin/monitoring/summary', 'GET'), function () use (&$passed) {
            $passed = true;

            return response('ok');
        });
        $this->assertTrue($passed, 'Basic Auth must not apply when staging_mode is false');

        config(['app.staging_mode' => true]);
        $response = $middleware->handle(Request::create('/api/admin/monitoring/summary', 'GET'), function () {
            return response('should-not-reach');
        });
        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->headers->has('WWW-Authenticate'));

        $authed = Request::create('/api/admin/monitoring/summary', 'GET', [], [], [], [
            'PHP_AUTH_USER' => 'stage',
            'PHP_AUTH_PW' => 'secret',
        ]);

        $ok = false;
        $middleware->handle($authed, function () use (&$ok) {
            $ok = true;

            return response('ok');
        });
        $this->assertTrue($ok, 'Valid Basic Auth must pass when staging_mode is true');
    }

    public function test_7_staging_reset_requires_force_flag(): void
    {
        config([
            'app.staging_mode' => true,
            'payment.stripe.secret' => 'sk_test_ok',
        ]);
        $this->app['env'] = 'staging';

        $this->artisan('staging:reset')
            ->assertFailed()
            ->expectsOutputToContain('--force');
    }
}
