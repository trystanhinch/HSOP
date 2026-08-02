<?php

namespace Tests\Feature\Monitoring;

use App\Models\Alert;
use App\Models\Job as DomainJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: duplicate JobFailed alert registration + queue table ≠ domain jobs.
 */
class QueueAlertAndTableCollisionFixTest extends TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('logging.channels.slack.url', '');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.table', 'queue_jobs');
        $app['config']->set('queue.connections.database.retry_after', 90);
        // phpunit.xml sets DB_CONNECTION=sqlite — failed job logger must use the same MySQL DB as alerts.
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'mysql');
        $app['config']->set('queue.failed.table', 'failed_jobs');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'database/migrations/2026_08_01_000001_create_failed_jobs_table_if_missing.php' => 'failed_jobs',
            'database/migrations/2026_08_01_000002_create_alerts_table.php' => 'alerts',
            'database/migrations/2026_08_02_000001_create_queue_jobs_table.php' => 'queue_jobs',
        ] as $path => $table) {
            if (! Schema::hasTable($table)) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true]);
            }
        }

        DB::table('queue_jobs')->whereIn('queue', ['alert_regression', 'table_collision_probe'])->delete();
    }

    public function test_job_failed_listener_registered_exactly_once(): void
    {
        $resolved = Event::getListeners(JobFailed::class);
        $this->assertCount(
            1,
            $resolved,
            'JobFailed must have exactly one listener (manual Event::listen + auto-discovery caused duplicates). Count='.count($resolved)
        );
    }

    public function test_permanent_queue_failure_creates_exactly_one_alert(): void
    {
        $marker = 'intentional queue alert regression '.uniqid('', true);
        $domainJobCountBefore = DomainJob::query()->count();

        dispatch(function () use ($marker) {
            throw new \RuntimeException($marker);
        })->onConnection('database')->onQueue('alert_regression');

        $this->assertSame(
            1,
            (int) DB::table('queue_jobs')->where('queue', 'alert_regression')->count(),
            'Pending payload must land in queue_jobs'
        );
        $this->assertSame($domainJobCountBefore, DomainJob::query()->count());

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'alert_regression',
            '--once' => true,
            '--tries' => 1,
        ]);

        $matching = Alert::query()
            ->where('severity', 'high')
            ->where('context->source', 'queue.job_failed')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->filter(fn (Alert $a) => ($a->context['exception'] ?? '') === $marker)
            ->values();

        $failedRows = DB::table('failed_jobs')
            ->where('exception', 'like', '%'.$marker.'%')
            ->get();

        try {
            $this->assertCount(
                1,
                $matching,
                'Expected exactly one alert for one permanent failure; got '.$matching->count()
                .' artisan_out='.Artisan::output()
            );
            $this->assertCount(1, $failedRows, 'Expected one failed_jobs row for marker');
            $this->assertSame(0, (int) DB::table('queue_jobs')->where('queue', 'alert_regression')->count());
            $this->assertSame($domainJobCountBefore, DomainJob::query()->count());
        } finally {
            foreach ($matching as $alert) {
                DB::table('alerts')->where('id', $alert->id)->delete();
            }
            DB::table('failed_jobs')->where('exception', 'like', '%'.$marker.'%')->delete();
            DB::table('queue_jobs')->where('queue', 'alert_regression')->delete();
        }
    }

    public function test_database_queue_uses_queue_jobs_table_not_domain_jobs(): void
    {
        $this->assertSame('queue_jobs', config('queue.connections.database.table'));
        $this->assertTrue(Schema::hasTable('queue_jobs'));
        $this->assertTrue(Schema::hasTable('jobs'), 'Domain jobs table must still exist');

        $domainCols = Schema::getColumnListing('jobs');
        $queueCols = Schema::getColumnListing('queue_jobs');
        $this->assertContains('payload', $queueCols);
        $this->assertContains('attempts', $queueCols);
        $this->assertNotContains('payload', $domainCols);
        $this->assertContains('service_category', $domainCols);

        $domainBefore = DomainJob::query()->count();
        $queueBefore = (int) DB::table('queue_jobs')->where('queue', 'table_collision_probe')->count();

        dispatch(function () {
            // success path
        })->onConnection('database')->onQueue('table_collision_probe');

        $this->assertSame($queueBefore + 1, (int) DB::table('queue_jobs')->where('queue', 'table_collision_probe')->count());
        $this->assertSame($domainBefore, DomainJob::query()->count());

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'table_collision_probe',
            '--once' => true,
            '--tries' => 1,
        ]);

        $this->assertSame(0, (int) DB::table('queue_jobs')->where('queue', 'table_collision_probe')->count());
        $this->assertSame($domainBefore, DomainJob::query()->count());
    }
}
