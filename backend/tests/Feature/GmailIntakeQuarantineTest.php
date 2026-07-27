<?php

namespace Tests\Feature;

use App\Models\IntakeAuditLog;
use App\Models\IntakeQuarantine;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeadIntake\LeadEmailParser;
use App\Services\LeadIntake\LeadIntakeQuarantineService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Audit A-02 — Gmail intake quarantine acceptance cases.
 */
class GmailIntakeQuarantineTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('intake_quarantine')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_27_000002_gmail_intake_quarantine.php',
                '--force' => true,
            ]);
        }

        $this->seed(Milestone4Seeder::class);
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);
    }

    private function ingest(string $fixture, bool $notify = true): \App\Services\LeadIntake\LeadIntakeResult
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/'.$fixture));
        $parsed = app(LeadEmailParser::class)->parse($raw);

        return app(LeadIntakeQuarantineService::class)->ingest($raw, $parsed, [
            'channel' => 'gmail',
            'mailbox_email' => 'leads@serviceop.ca',
            'gmail_message_id' => 'test-'.md5($fixture.microtime(true)),
            'send_notifications' => $notify,
            'is_test_data' => true,
        ]);
    }

    public function test_1_valid_recognized_lead_auto_creates_once(): void
    {
        $beforeLeads = Lead::withTestData()->count();

        $result = $this->ingest('gmail_quarantine_valid_form.txt');

        $this->assertSame('created', $result->outcome);
        $this->assertNotNull($result->lead);
        $this->assertTrue((bool) $result->lead->is_test_data);
        $this->assertStringNotContainsString('@', (string) $result->lead->phone);
        $this->assertStringContainsString('604', preg_replace('/\D+/', '', (string) $result->lead->phone) ?: '');
        $this->assertNotNull($result->lead->assigned_pm_id);
        $this->assertSame('Fraser Valley Drywall', $result->lead->companySource?->company_name);
        $this->assertSame($beforeLeads + 1, Lead::withTestData()->count());
        $this->assertSame('auto_approved', $result->quarantine?->status);

        $audits = IntakeAuditLog::where('intake_quarantine_id', $result->quarantine?->id)->get();
        $this->assertTrue($audits->contains(fn ($a) => $a->decision === 'auto_approved'));
    }

    public function test_2_google_workspace_security_ignored(): void
    {
        $beforeLeads = Lead::withTestData()->count();
        $result = $this->ingest('gmail_quarantine_google_security.txt');

        $this->assertSame('ignored', $result->outcome);
        $this->assertNull($result->lead);
        $this->assertSame($beforeLeads, Lead::withTestData()->count());
        $this->assertStringContainsStringIgnoringCase('security', (string) $result->quarantine?->quarantine_reason);
        $this->assertSame('ignored', $result->quarantine?->status);
    }

    public function test_3_newsletter_ignored(): void
    {
        $beforeLeads = Lead::withTestData()->count();
        $result = $this->ingest('gmail_quarantine_newsletter.txt');

        $this->assertSame('ignored', $result->outcome);
        $this->assertNull($result->lead);
        $this->assertSame($beforeLeads, Lead::withTestData()->count());
        $this->assertNotNull($result->quarantine?->quarantine_reason);
    }

    public function test_4_duplicate_voicemail_merges_to_one_lead_on_approve(): void
    {
        $a = $this->ingest('gmail_quarantine_voicemail_a.txt', notify: false);
        $b = $this->ingest('gmail_quarantine_voicemail_b.txt', notify: false);

        $this->assertSame('quarantined', $a->outcome);
        $this->assertTrue(in_array($b->outcome, ['quarantined', 'duplicate'], true));
        $this->assertSame($a->quarantine?->duplicate_group_key, $b->quarantine?->duplicate_group_key);
        $this->assertStringStartsWith('vm:', (string) $a->quarantine?->duplicate_group_key);

        // Only one pending quarantine for the group (second marked ignored as duplicate)
        $pending = IntakeQuarantine::withTestData()
            ->where('duplicate_group_key', $a->quarantine->duplicate_group_key)
            ->where('status', 'pending')
            ->count();
        $this->assertSame(1, $pending);

        $owner = User::where('role', 'owner')->first() ?? User::factory()->create(['role' => 'owner']);
        $approve = app(LeadIntakeQuarantineService::class)->approve(
            $a->quarantine->fresh(),
            $owner,
            ['contact_name' => 'Voicemail Caller QA'],
            sendNotifications: false,
        );

        $this->assertNotNull($approve->lead);
        $this->assertTrue((bool) $approve->lead->is_test_data);

        $leadsForPhone = Lead::withTestData()
            ->where('parse_metadata->voicemail_duplicate_key', $a->quarantine->duplicate_group_key)
            ->count();
        $this->assertSame(1, $leadsForPhone);
    }

    public function test_5_email_never_saved_in_phone_field(): void
    {
        $result = $this->ingest('gmail_quarantine_email_as_phone.txt');

        $this->assertSame('quarantined', $result->outcome);
        $this->assertNull($result->lead);
        $this->assertTrue(empty($result->quarantine?->parsed_fields['phone']));
        $phoneConf = collect($result->quarantine?->field_confidence ?? [])->firstWhere('field', 'phone');
        $this->assertNotNull($phoneConf);
        $this->assertFalse((bool) $phoneConf['valid']);
        $this->assertStringContainsString('@', (string) ($phoneConf['source_text'] ?? ''));
    }

    public function test_6_ambiguous_low_confidence_stays_in_needs_review(): void
    {
        $beforeLeads = Lead::withTestData()->count();
        $result = $this->ingest('gmail_quarantine_ambiguous.txt');

        $this->assertSame('quarantined', $result->outcome);
        $this->assertNull($result->lead);
        $this->assertSame($beforeLeads, Lead::withTestData()->count());
        $this->assertSame('pending', $result->quarantine?->status);
        $this->assertNotEmpty($result->quarantine?->field_confidence);
        foreach ($result->quarantine->field_confidence as $row) {
            $this->assertArrayHasKey('field', $row);
            $this->assertArrayHasKey('score', $row);
            $this->assertArrayHasKey('source_text', $row);
        }
    }

    public function test_7_reviewer_approve_creates_lead_once(): void
    {
        $result = $this->ingest('gmail_quarantine_email_as_phone.txt', notify: false);
        $this->assertSame('quarantined', $result->outcome);

        $owner = User::where('role', 'owner')->first() ?? User::factory()->create(['role' => 'owner']);

        $approve = app(LeadIntakeQuarantineService::class)->approve(
            $result->quarantine->fresh(),
            $owner,
            [
                'contact_name' => 'Phone Poison Fixed',
                'phone' => '+16045550177',
                'email' => 'person@example.com',
                'project_description' => 'Small drywall repair — corrected from quarantine',
            ],
            sendNotifications: true,
        );

        $this->assertSame('created', $approve->outcome);
        $this->assertNotNull($approve->lead);
        $this->assertStringNotContainsString('@', (string) $approve->lead->phone);
        $this->assertSame('approved', $approve->quarantine?->status);
        $this->assertNotNull($approve->lead->assigned_pm_id);

        $audits = IntakeAuditLog::where('intake_quarantine_id', $result->quarantine->id)
            ->whereIn('decision', ['manually_approved', 'edited_approved'])
            ->count();
        $this->assertGreaterThanOrEqual(1, $audits);

        // Second approve must fail
        $this->expectException(\RuntimeException::class);
        app(LeadIntakeQuarantineService::class)->approve(
            $result->quarantine->fresh(),
            $owner,
            [],
            false,
        );
    }

    public function test_parser_does_not_map_gmail_from_header_to_phone(): void
    {
        $raw = "Subject: Drywall [Voicemail] From +16045550999 to Drywall\n"
            ."From: \"Gmail Team\" <noreply@google.com>\n"
            ."Caller ID: +16045550999\n"
            ."Recording URL: https://api.twilio.com/2010-04-01/Accounts/AC/Recordings/REx1\n";

        $parsed = app(LeadEmailParser::class)->parse($raw);

        $this->assertNotNull($parsed->phone);
        $this->assertStringNotContainsString('@', $parsed->phone);
        $this->assertStringNotContainsString('Gmail', $parsed->phone);
        $this->assertStringContainsString('604', preg_replace('/\D+/', '', $parsed->phone) ?: '');
    }
}
