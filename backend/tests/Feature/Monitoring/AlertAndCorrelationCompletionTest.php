<?php

namespace Tests\Feature\Monitoring;

use App\Listeners\DispatchAlertOnKillSwitchEngaged;
use App\Models\AiActionLog;
use App\Models\Alert;
use App\Models\EmailLog;
use App\Models\GmailOauthToken;
use App\Models\NextAction;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\WorkflowEscalationLog;
use App\Services\EmailService;
use App\Services\Monitoring\GmailStalenessMonitor;
use App\Services\Payments\StripePaymentProvider;
use App\Services\SmsService;
use App\Support\CorrelationId;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Milestone 6A Phase 10 — remaining alert wiring + outbound correlation propagation.
 */
class AlertAndCorrelationCompletionTest extends TestCase
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
        $app['config']->set('monitoring.gmail_staleness_hours', 2);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('alerts')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_08_01_000002_create_alerts_table.php',
                '--force' => true,
            ]);
        }
        if (! Schema::hasColumn('sms_logs', 'correlation_id')
            || ! Schema::hasColumn('gmail_oauth_tokens', 'staleness_alerted')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_08_01_000010_phase10_alert_correlation_columns.php',
                '--force' => true,
            ]);
        }
    }

    private function alertCount(?string $source = null): int
    {
        $q = Alert::query();
        if ($source) {
            $q->where('context->source', $source);
        }

        return $q->count();
    }

    public function test_1_sms_delivery_failure_alerts_once(): void
    {
        $before = $this->alertCount('sms.delivery_failed');

        SmsLog::create([
            'to_phone' => '+16045550100',
            'message_body' => 'x',
            'trigger_event' => 'test_sms_fail',
            'status' => 'failed',
            'error_code' => 'send_failed',
            'is_test_data' => false,
        ]);

        $this->assertSame($before + 1, $this->alertCount('sms.delivery_failed'));

        // Sent status must not alert
        SmsLog::create([
            'to_phone' => '+16045550101',
            'message_body' => 'x',
            'trigger_event' => 'test_sms_ok',
            'status' => 'sent',
            'is_test_data' => false,
        ]);
        $this->assertSame($before + 1, $this->alertCount('sms.delivery_failed'));
    }

    public function test_2_email_delivery_failure_alerts_once(): void
    {
        $before = $this->alertCount('email.delivery_failed');

        EmailLog::create([
            'to_email' => 'fail@example.com',
            'trigger_event' => 'test_email_fail',
            'status' => 'provider_unavailable',
            'is_test_data' => false,
        ]);

        $this->assertSame($before + 1, $this->alertCount('email.delivery_failed'));

        EmailLog::create([
            'to_email' => 'ok@example.com',
            'trigger_event' => 'test_email_ok',
            'status' => 'sent',
            'is_test_data' => false,
        ]);
        $this->assertSame($before + 1, $this->alertCount('email.delivery_failed'));
    }

    public function test_3_ai_action_error_alerts_once_per_row(): void
    {
        $before = $this->alertCount('ai.action_error');
        $actor = User::create([
            'name' => 'AI Actor',
            'email' => 'ai-actor-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);

        AiActionLog::create([
            'trigger_event' => 'openai_api',
            'actor_id' => $actor->id,
            'decision' => 'failed',
            'error' => 'rate limited',
        ]);
        $this->assertSame($before + 1, $this->alertCount('ai.action_error'));

        // Success row — no alert
        AiActionLog::create([
            'trigger_event' => 'openai_api',
            'actor_id' => $actor->id,
            'decision' => 'completed',
            'error' => null,
        ]);
        $this->assertSame($before + 1, $this->alertCount('ai.action_error'));

        // Second error row — second alert (once per row, not deduped across rows)
        AiActionLog::create([
            'trigger_event' => 'openai_api',
            'actor_id' => $actor->id,
            'decision' => 'failed',
            'error' => 'timeout',
        ]);
        $this->assertSame($before + 2, $this->alertCount('ai.action_error'));
    }

    public function test_4_stripe_webhook_failure_alerts_high_once(): void
    {
        $before = $this->alertCount('stripe.webhook_failed');

        StripeWebhookEvent::create([
            'event_id' => 'evt_fail_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'status' => 'failed',
            'error' => 'boom',
            'processed_at' => now(),
        ]);

        $this->assertSame($before + 1, $this->alertCount('stripe.webhook_failed'));
        $alert = Alert::query()->where('context->source', 'stripe.webhook_failed')->orderByDesc('id')->first();
        $this->assertSame('high', $alert->severity);

        StripeWebhookEvent::create([
            'event_id' => 'evt_ok_'.uniqid(),
            'type' => 'payment_intent.succeeded',
            'status' => 'processed',
            'processed_at' => now(),
        ]);
        $this->assertSame($before + 1, $this->alertCount('stripe.webhook_failed'));
    }

    public function test_5_workflow_escalation_alerts_once(): void
    {
        $before = $this->alertCount('workflow.escalation_fired');

        $na1 = NextAction::create([
            'subject_type' => 'lead',
            'subject_id' => 1,
            'action_description' => 'Call lead',
            'responsible_role' => 'pm',
            'due_at' => now()->subHour(),
            'status' => 'overdue',
        ]);
        WorkflowEscalationLog::create([
            'next_action_id' => $na1->id,
            'rule_key' => 'pm_contact_lead_'.uniqid(),
            'stage' => 'reminder',
            'fired_at' => now(),
            'meta' => null,
        ]);
        $this->assertSame($before + 1, $this->alertCount('workflow.escalation_fired'));
        $reminder = Alert::query()->where('context->source', 'workflow.escalation_fired')->orderByDesc('id')->first();
        $this->assertSame('medium', $reminder->severity);

        $na2 = NextAction::create([
            'subject_type' => 'lead',
            'subject_id' => 2,
            'action_description' => 'Escalate lead',
            'responsible_role' => 'pm',
            'due_at' => now()->subHours(2),
            'status' => 'overdue',
        ]);
        WorkflowEscalationLog::create([
            'next_action_id' => $na2->id,
            'rule_key' => 'pm_contact_lead_'.uniqid(),
            'stage' => 'escalation',
            'fired_at' => now(),
            'meta' => ['severity' => 'critical'],
        ]);
        $escalation = Alert::query()->where('context->source', 'workflow.escalation_fired')->orderByDesc('id')->first();
        $this->assertSame('critical', $escalation->severity);
        $this->assertSame($before + 2, $this->alertCount('workflow.escalation_fired'));
    }

    public function test_6_gmail_staleness_once_per_episode(): void
    {
        $mailbox = 'stale-'.uniqid().'@example.com';
        $token = GmailOauthToken::create([
            'mailbox_email' => $mailbox,
            'access_token_encrypted' => 'x',
            'refresh_token_encrypted' => 'y',
            'connected_at' => now()->subDay(),
            'last_fetched_at' => now()->subHours(5),
            'staleness_alerted' => false,
        ]);

        $before = $this->alertCount('gmail.poll_stale');
        $monitor = app(GmailStalenessMonitor::class);

        $r1 = $monitor->check();
        $this->assertSame(1, $r1['alerted']);
        $this->assertSame($before + 1, $this->alertCount('gmail.poll_stale'));
        $this->assertTrue($token->fresh()->staleness_alerted);

        // Still stale — no second alert
        $r2 = $monitor->check();
        $this->assertSame(0, $r2['alerted']);
        $this->assertSame($before + 1, $this->alertCount('gmail.poll_stale'));

        // Fresh fetch clears flag
        $token->update(['last_fetched_at' => now(), 'staleness_alerted' => false]);
        $r3 = $monitor->check();
        $this->assertSame(0, $r3['alerted']);
        $this->assertSame($before + 1, $this->alertCount('gmail.poll_stale'));

        // Goes stale again — new episode alerts again
        $token->update(['last_fetched_at' => now()->subHours(5), 'staleness_alerted' => false]);
        $r4 = $monitor->check();
        $this->assertSame(1, $r4['alerted']);
        $this->assertSame($before + 2, $this->alertCount('gmail.poll_stale'));
    }

    public function test_7_kill_switch_on_alerts_off_does_not(): void
    {
        $owner = User::create([
            'name' => 'Owner KS',
            'email' => 'owner-ks-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        Sanctum::actingAs($owner);

        $reviewKey = config('review_gateway.kill_switch_setting_key');
        $learningKey = config('learning_ai.kill_switch_setting_key');
        Setting::setBool($reviewKey, false);
        Setting::setBool($learningKey, false);

        $before = $this->alertCount('gateway.kill_switch_engaged');

        $this->patchJson('/api/admin/review-gateway/kill-switch', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('kill_switch', true);
        $this->assertSame($before + 1, $this->alertCount('gateway.kill_switch_engaged'));

        $this->patchJson('/api/admin/review-gateway/kill-switch', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('kill_switch', false);
        $this->assertSame($before + 1, $this->alertCount('gateway.kill_switch_engaged'));

        $this->patchJson('/api/admin/learning-gateway/kill-switch', ['enabled' => true])
            ->assertOk();
        $this->assertSame($before + 2, $this->alertCount('gateway.kill_switch_engaged'));

        $this->patchJson('/api/admin/learning-gateway/kill-switch', ['enabled' => false])
            ->assertOk();
        $this->assertSame($before + 2, $this->alertCount('gateway.kill_switch_engaged'));

        // Listener itself ignores OFF
        app(DispatchAlertOnKillSwitchEngaged::class)->handle('review', false, $owner->id);
        $this->assertSame($before + 2, $this->alertCount('gateway.kill_switch_engaged'));
    }

    public function test_8_correlation_id_on_sms_email_openai_and_stripe(): void
    {
        $cid = 'corr-phase10-'.uniqid();
        Log::shareContext(['correlation_id' => $cid]);
        $this->assertSame($cid, CorrelationId::current());

        // Twilio fallback: SmsLog.correlation_id (no Twilio Message metadata API)
        $sms = SmsLog::create([
            'to_phone' => '+16045550999',
            'message_body' => 'x',
            'trigger_event' => 'corr_sms',
            'status' => 'failed',
            'is_test_data' => false,
        ]);
        $this->assertSame($cid, $sms->fresh()->correlation_id);

        // Resend fallback + header path: EmailLog.correlation_id
        $email = EmailLog::create([
            'to_email' => 'corr@example.com',
            'subject' => 'x',
            'message_body' => 'x',
            'trigger_event' => 'corr_email',
            'status' => 'failed',
            'is_test_data' => false,
        ]);
        $this->assertSame($cid, $email->fresh()->correlation_id);

        // OpenAI: AiActionLog.correlation_id sibling to trace_id
        $actor = User::create([
            'name' => 'Corr AI',
            'email' => 'corr-ai-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $ai = AiActionLog::create([
            'trace_id' => 'trace-'.uniqid(),
            'actor_id' => $actor->id,
            'trigger_event' => 'openai_api',
            'decision' => 'completed',
            'error' => null,
        ]);
        $this->assertSame($cid, $ai->fresh()->correlation_id);
        $this->assertNotSame($cid, $ai->trace_id);

        // Stripe: metadata map includes correlation_id
        $method = new ReflectionMethod(StripePaymentProvider::class, 'withCorrelationMeta');
        $method->setAccessible(true);
        $meta = $method->invoke(app(StripePaymentProvider::class), ['invoice_id' => '1']);
        $this->assertSame($cid, $meta['correlation_id']);
        $this->assertSame('1', $meta['invoice_id']);
    }

    public function test_9_email_service_attaches_correlation_header_when_sending(): void
    {
        $cid = 'corr-mail-'.uniqid();
        Log::shareContext(['correlation_id' => $cid]);
        Setting::setBool('email_globally_enabled', false);
        Setting::setBool('email_enabled', false);

        app(EmailService::class)->send(
            'header-test@example.com',
            'Subject',
            'emails.notification',
            ['body' => 'hi'],
            'corr_header_test'
        );

        $log = EmailLog::withTestData()->where('trigger_event', 'corr_header_test')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($cid, $log->correlation_id);
        $this->assertSame('failed', $log->status);
    }

    public function test_10_sms_service_stamps_correlation_on_log(): void
    {
        $cid = 'corr-sms-svc-'.uniqid();
        Log::shareContext(['correlation_id' => $cid]);
        Setting::setBool('sms_globally_enabled', false);
        Setting::setBool('sms_enabled', false);

        app(SmsService::class)->send('+16045550000', 'hello', 'corr_sms_svc');

        $log = SmsLog::withTestData()->where('trigger_event', 'corr_sms_svc')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($cid, $log->correlation_id);
    }
}
