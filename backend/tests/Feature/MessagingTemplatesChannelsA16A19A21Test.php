<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Messaging\NotificationChannelHealthService;
use App\Services\SmsService;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-16 / A-19 / A-21 — templates, channel health, delivery logs.
 */
class MessagingTemplatesChannelsA16A19A21Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('services.sms.enabled', false);
        $app['config']->set('services.sms.sid', '');
        $app['config']->set('services.sms.auth_token', '');
        $app['config']->set('services.sms.from_number', '');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        $this->seed(MessageTemplateSeeder::class);
        Setting::set('sms_globally_enabled', 'false');
        Setting::set('email_globally_enabled', 'false');

        if (! Schema::hasTable('message_template_versions')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000006_a16_a19_a21_templates_channels_logs.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        $user = User::where('role', 'owner')->first()
            ?: User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $user->forceFill([
            'phone' => '6045550199',
            'email' => $user->email ?: 'owner-msg-'.uniqid().'@test.local',
            'status' => 'active',
            'password' => 'password',
        ])->save();

        return $user->fresh();
    }

    /** TC-1: Unresolved {{variable}} blocked on save. */
    public function test_1_unresolved_variable_blocks_save(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $tpl = MessageTemplate::where('event_key', 'quote_sent')->firstOrFail();

        $res = $this->putJson("/api/message-templates/{$tpl->id}", [
            'body' => 'Hello {{customer_name}} from {{totally_unknown_var}}',
        ]);

        $res->assertStatus(422);
        $this->assertArrayHasKey('body', $res->json('errors') ?? []);
    }

    /** TC-2: Test-send uses BrandResolver brand, not hardcoded ServiceOP. */
    public function test_2_test_send_uses_brand_resolver_not_serviceop(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $brand = Brand::create([
            'domain' => 'acutera-msg-'.uniqid().'.ca',
            'slug' => 'acutera-msg-'.uniqid(),
            'company_name' => 'Acutera Drywall and Paint',
            'status' => 'active',
        ]);

        $tpl = MessageTemplate::where('event_key', 'quote_sent')->firstOrFail();
        $tpl->update([
            'body' => '{{company_name}}: Your quote is ready. Total: ${{customer_total}}. Link: {{portal_url}}',
            'is_active' => true,
        ]);

        Setting::set('sms_globally_enabled', 'true');

        $res = $this->postJson("/api/message-templates/{$tpl->id}/test-send", [
            'brand_id' => $brand->id,
            'channel' => 'sms',
        ]);

        $res->assertOk();
        $this->assertSame('Acutera Drywall and Paint', $res->json('brand_name'));
        $this->assertStringContainsString('Acutera Drywall and Paint', $res->json('preview.rendered'));
        $this->assertStringNotContainsString('ServiceOP', $res->json('preview.rendered'));
        // Provider unavailable (no Twilio) — still returns visible response, not silent.
        $this->assertFalse((bool) $res->json('provider_response.success'));
        $this->assertSame('provider_unavailable', $res->json('provider_response.reason'));
    }

    /** TC-3: SMS policy ON without Twilio → blocking provider_unavailable. */
    public function test_3_enabled_but_unavailable_is_loud_failure(): void
    {
        Setting::set('sms_globally_enabled', 'true');
        $health = app(NotificationChannelHealthService::class)->smsHealth();
        $this->assertTrue($health['policy_enabled']);
        $this->assertFalse($health['provider_ready']);
        $this->assertNotEmpty($health['blocking_error']);

        $owner = $this->owner();
        $result = app(SmsService::class)->send(
            $owner->phone,
            'Customer critical quote notice',
            'quote_sent',
            $owner->id,
            null
        );

        $this->assertFalse($result['success']);
        $this->assertSame('provider_unavailable', $result['reason']);
        $this->assertNotEmpty($result['blocking_error'] ?? $result['plain'] ?? null);

        $log = SmsLog::withTestData()->where('trigger_event', 'quote_sent')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('provider_unavailable', $log->status);
        $this->assertNotEmpty($log->error_plain);
        $this->assertNotEmpty($log->correction_path);
    }

    /** TC-4: Failed SMS shows plain error; retry is idempotent. */
    public function test_4_failed_log_has_plain_error_and_idempotent_retry(): void
    {
        $owner = $this->owner();
        Setting::set('sms_globally_enabled', 'true');

        app(SmsService::class)->send('6045550100', 'Hello', 'quote_sent', $owner->id, null);
        $log = SmsLog::withTestData()->where('trigger_event', 'quote_sent')->latest('id')->firstOrFail();
        $this->assertNotEmpty($log->error_plain);
        $this->assertSame('provider_unavailable', $log->status);

        Sanctum::actingAs($owner);
        $first = $this->postJson("/api/sms-logs/{$log->id}/retry", []);
        $first->assertOk();
        $this->assertFalse((bool) ($first->json('deduplicated') ?? false));

        // Simulate a successful prior retry so second call dedupes.
        SmsLog::create([
            'to_phone' => '+16045550100',
            'recipient_normalized' => '+16045550100',
            'user_id' => $owner->id,
            'trigger_event' => 'quote_sent',
            'message_body' => 'Hello',
            'status' => 'sent',
            'provider_message_id' => 'SM_TEST_DEDUP',
            'idempotency_key' => 'sms-retry-'.$log->id,
            'retry_of_id' => $log->id,
            'attempt_count' => 2,
            'is_test_data' => false,
        ]);

        $second = $this->postJson("/api/sms-logs/{$log->id}/retry", []);
        $second->assertOk();
        $this->assertTrue((bool) $second->json('deduplicated'));
        $this->assertSame(1, SmsLog::withTestData()->where('idempotency_key', 'sms-retry-'.$log->id)->where('status', 'sent')->count());
    }

    /** TC-5: is_test_data sends excluded from production delivery-rate metrics. */
    public function test_5_test_flagged_sends_excluded_from_production_metrics(): void
    {
        $owner = $this->owner();
        Setting::set('sms_globally_enabled', 'false');

        // Production failed attempt
        SmsLog::create([
            'to_phone' => '+16045550111',
            'trigger_event' => 'quote_sent',
            'message_body' => 'prod',
            'status' => 'sent',
            'is_test_data' => false,
            'user_id' => $owner->id,
        ]);

        // Test-flagged sent (should not appear in default scope / metrics)
        SmsLog::create([
            'to_phone' => '+16045550112',
            'trigger_event' => 'quote_sent',
            'message_body' => 'test',
            'status' => 'sent',
            'is_test_data' => true,
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/sms-logs');
        $res->assertOk();
        $this->assertTrue($res->json('metrics.test_excluded'));
        $sent = (int) $res->json('metrics.production_sent_30d');
        $this->assertGreaterThanOrEqual(1, $sent);

        // Default list uses ExcludeTestDataScope — test row absent.
        $ids = collect($res->json('data.data') ?? $res->json('data') ?? [])->pluck('to_phone')->all();
        $this->assertNotContains('+16045550112', $ids);

        $healthRate = app(NotificationChannelHealthService::class)->smsHealth()['delivery_rate_30d_pct'];
        // Health also uses default scope (production only).
        $this->assertNotNull($healthRate);
    }

    public function test_inactive_template_cannot_be_triggered(): void
    {
        $tpl = MessageTemplate::where('event_key', 'quote_sent')->firstOrFail();
        $tpl->update(['is_active' => false]);

        $rendered = MessageTemplate::render('quote_sent', [
            'company_name' => 'Acutera',
            'customer_total' => '10.00',
            'portal_url' => 'https://example.com',
        ], 'FALLBACK SHOULD NOT SEND');

        $this->assertNull($rendered);

        $owner = $this->owner();
        Setting::set('sms_globally_enabled', 'true');
        $result = app(SmsService::class)->send($owner->phone, '', 'quote_sent', $owner->id);
        $this->assertSame('template_inactive', $result['reason']);
    }

    public function test_template_update_is_audited(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $tpl = MessageTemplate::where('event_key', 'pm_contact_reminder')->firstOrFail();

        $this->putJson("/api/message-templates/{$tpl->id}", [
            'body' => 'Hi {{pm_name}}, reminder: contact {{lead_name}} (lead #{{lead_id}}) — overdue please.',
        ])->assertOk();

        $this->assertTrue(
            AuditLog::query()->where('action_type', 'message_template_updated')->where('object_id', $tpl->id)->exists()
        );
        $this->assertDatabaseHas('message_template_versions', [
            'message_template_id' => $tpl->id,
            'changed_by' => $owner->id,
        ]);
    }
}
