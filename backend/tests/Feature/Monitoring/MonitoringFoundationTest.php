<?php

namespace Tests\Feature\Monitoring;

use App\Listeners\DispatchAlertOnJobFailed;
use App\Models\AiActionLog;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\NextAction;
use App\Models\SmsLog;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\WorkflowEscalationLog;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Milestone 6A.4 — System Health / monitoring foundation.
 */
class MonitoringFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('logging.channels.slack.url', '');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'database/migrations/2026_08_01_000001_create_failed_jobs_table_if_missing.php' => 'failed_jobs',
            'database/migrations/2026_08_01_000002_create_alerts_table.php' => 'alerts',
        ] as $path => $table) {
            if (! Schema::hasTable($table)) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true]);
            }
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'Owner Mon',
            'email' => 'owner-mon-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function seedFailedJob(?string $uuid = null): int
    {
        $uuid ??= (string) Str::uuid();
        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\ExampleJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'App\\Jobs\\ExampleJob', 'command' => 'O:1:"x":0:{}'],
            ]),
            'exception' => "RuntimeException: boom\nStack trace:\n#0 {main}",
            'failed_at' => now(),
        ]);
    }

    public function test_1_failed_jobs_owner_only_and_actions_are_audit_logged(): void
    {
        $id = $this->seedFailedJob();

        Sanctum::actingAs(User::create([
            'name' => 'PM',
            'email' => 'pm-mon-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'pm',
            'status' => 'active',
        ]));
        $this->getJson('/api/admin/monitoring/failed-jobs')->assertForbidden();
        $this->postJson('/api/admin/monitoring/failed-jobs/'.$id.'/retry')->assertForbidden();
        $this->deleteJson('/api/admin/monitoring/failed-jobs/'.$id)->assertForbidden();

        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $list = $this->getJson('/api/admin/monitoring/failed-jobs')->assertOk();
        $this->assertTrue(collect($list->json('data'))->contains(fn ($r) => (int) $r['id'] === $id));
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertArrayNotHasKey('payload', $row);
        $this->assertSame('App\\Jobs\\ExampleJob', $row['job_name']);
        $this->assertStringContainsString('RuntimeException', $row['exception_summary']);

        // Avoid depending on a live queue worker / jobs table for the audit path.
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', \Mockery::type('array'))
            ->andReturn(0);
        \Illuminate\Support\Facades\Artisan::shouldReceive('output')->andReturn('Job queued for retry.');

        $retry = $this->postJson('/api/admin/monitoring/failed-jobs/'.$id.'/retry');
        $retry->assertOk()->assertJsonPath('result.id', $id);

        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'monitoring_failed_job_retry')
                ->where('object_id', $id)
                ->where('user_id', $owner->id)
                ->exists()
        );

        // Re-seed for dismiss (retry may have removed the row depending on queue driver)
        if (! DB::table('failed_jobs')->where('id', $id)->exists()) {
            $id = $this->seedFailedJob();
        }

        $this->deleteJson('/api/admin/monitoring/failed-jobs/'.$id)
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->assertDatabaseMissing('failed_jobs', ['id' => $id]);
        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'monitoring_failed_job_dismiss')
                ->where('object_id', $id)
                ->where('user_id', $owner->id)
                ->exists()
        );
    }

    public function test_2_monitoring_summary_aggregates_seeded_signals(): void
    {
        SmsLog::create([
            'to_phone' => '+16045550100',
            'message_body' => 'x',
            'trigger_event' => 'monitoring_test',
            'status' => 'failed',
            'error_message' => 'twilio down',
            'is_test_data' => false,
        ]);
        EmailLog::create([
            'to_email' => 'a@example.com',
            'subject' => 'x',
            'message_body' => 'x',
            'trigger_event' => 'monitoring_test',
            'status' => 'provider_unavailable',
            'error_message' => 'resend down',
            'is_test_data' => false,
        ]);
        AiActionLog::create([
            'trigger_event' => 'test',
            'actor_id' => $this->owner()->id,
            'decision' => 'error',
            'action_taken' => 'none',
            'error' => 'model timeout',
            'trace_id' => (string) Str::uuid(),
        ]);
        StripeWebhookEvent::create([
            'event_id' => 'evt_'.uniqid(),
            'type' => 'invoice.paid',
            'status' => 'failed',
            'error' => 'handler boom',
            'processed_at' => now(),
        ]);
        NextAction::create([
            'subject_type' => 'lead',
            'subject_id' => 1,
            'action_description' => 'Call lead',
            'responsible_role' => 'pm',
            'due_at' => now()->subHour(),
            'status' => 'overdue',
        ]);
        if (Schema::hasTable('workflow_escalation_logs')) {
            $na = NextAction::query()->where('status', 'overdue')->orderByDesc('id')->first();
            WorkflowEscalationLog::create([
                'next_action_id' => $na->id,
                'rule_key' => 'pm_contact_lead_'.uniqid(),
                'stage' => 'escalation',
                'fired_at' => now(),
                'meta' => [],
            ]);
        }
        $this->seedFailedJob();

        Sanctum::actingAs($this->owner());
        $res = $this->getJson('/api/admin/monitoring/summary')->assertOk();

        $this->assertGreaterThanOrEqual(1, $res->json('failed_jobs'));
        $this->assertGreaterThanOrEqual(1, $res->json('sms_delivery_failures'));
        $this->assertGreaterThanOrEqual(1, $res->json('email_delivery_failures'));
        $this->assertGreaterThanOrEqual(1, $res->json('ai_action_errors'));
        $this->assertGreaterThanOrEqual(1, $res->json('stripe_webhook_failures'));
        $this->assertGreaterThanOrEqual(1, $res->json('overdue_next_actions'));
        $this->assertArrayHasKey('gmail_last_fetched_at', $res->json());
        $this->assertArrayHasKey('gmail_last_run_note', $res->json());
    }

    public function test_3_correlation_id_generated_preserved_and_in_log_context(): void
    {
        Sanctum::actingAs($this->owner());

        $res = $this->getJson('/api/admin/monitoring/summary');
        $res->assertOk();
        $this->assertTrue($res->headers->has('X-Correlation-Id'));
        $generated = $res->headers->get('X-Correlation-Id');
        $this->assertNotEmpty($generated);

        $custom = 'signoff-corr-'.Str::uuid();
        $res2 = $this->withHeader('X-Correlation-Id', $custom)
            ->getJson('/api/admin/monitoring/summary');
        $res2->assertOk();
        $this->assertSame($custom, $res2->headers->get('X-Correlation-Id'));

        // Direct middleware invocation — proves Log::shareContext wiring.
        Log::flushSharedContext();
        $middleware = app(\App\Http\Middleware\AssignCorrelationId::class);
        $request = \Illuminate\Http\Request::create('/api/admin/monitoring/summary', 'GET');
        $request->headers->set('X-Correlation-Id', $custom);
        $middleware->handle($request, fn () => response('ok'));
        $this->assertSame($custom, $request->attributes->get('correlation_id'));
        $this->assertSame($custom, Log::sharedContext()['correlation_id'] ?? null);
    }

    public function test_4_alert_dispatcher_persists_and_skips_slack_when_unconfigured(): void
    {
        config(['logging.channels.slack.url' => '']);
        Log::spy();

        $alert = app(AlertDispatcher::class)->dispatch('high', 'Test alert', ['k' => 'v']);
        $this->assertDatabaseHas('alerts', [
            'id' => $alert->id,
            'severity' => 'high',
            'message' => 'Test alert',
        ]);
        Log::shouldNotHaveReceived('channel');
    }

    public function test_5_alert_dispatcher_uses_slack_channel_when_configured(): void
    {
        config(['logging.channels.slack.url' => 'https://hooks.slack.test/services/fake']);

        $fake = Mockery::mock();
        $fake->shouldReceive('critical')->once()->andReturnNull();
        Log::shouldReceive('channel')->once()->with('slack')->andReturn($fake);
        // Allow other log calls during request lifecycle if any
        Log::shouldReceive('withContext')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('shareContext')->zeroOrMoreTimes();
        Log::shouldReceive('sharedContext')->zeroOrMoreTimes();

        app(AlertDispatcher::class)->dispatch('high', 'Slack path', ['a' => 1]);
        $this->assertGreaterThanOrEqual(1, Alert::query()->where('message', 'Slack path')->count());
    }

    public function test_6_job_failed_event_dispatches_exactly_one_alert(): void
    {
        $before = Alert::count();

        $job = Mockery::mock(QueueJobContract::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ProbeFailJob');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('uuid')->andReturn((string) Str::uuid());

        $listener = app(DispatchAlertOnJobFailed::class);
        $listener->handle(new JobFailed('database', $job, new \RuntimeException('permanent fail')));

        $this->assertSame($before + 1, Alert::count());
        $latest = Alert::query()->orderByDesc('id')->first();
        $this->assertSame('high', $latest->severity);
        $this->assertStringContainsString('ProbeFailJob', $latest->message);
        $this->assertSame('queue.job_failed', $latest->context['source'] ?? null);
    }

    public function test_7_alerts_list_and_acknowledge_owner_only(): void
    {
        $alert = app(AlertDispatcher::class)->dispatch('medium', 'Ack me', []);

        Sanctum::actingAs(User::create([
            'name' => 'Cust',
            'email' => 'cust-mon-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
        ]));
        $this->getJson('/api/admin/monitoring/alerts')->assertForbidden();
        $this->patchJson('/api/admin/monitoring/alerts/'.$alert->id.'/acknowledge')->assertForbidden();

        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/monitoring/alerts?acknowledged=false')
            ->assertOk()
            ->assertJsonFragment(['id' => $alert->id]);

        $this->patchJson('/api/admin/monitoring/alerts/'.$alert->id.'/acknowledge')
            ->assertOk()
            ->assertJsonPath('alert.acknowledged_by', $owner->id);

        $this->assertNotNull(Alert::find($alert->id)->acknowledged_at);
        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'monitoring_alert_acknowledged')
                ->where('object_id', $alert->id)
                ->exists()
        );
    }
}
