<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Setting;
use App\Services\LeadIntake\DuplicateLeadDetector;
use App\Services\LeadIntake\LeadEmailParser;
use App\Services\LeadIntake\LeadIntakePipeline;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadIntakeTest extends TestCase
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
        $this->seed(Milestone4Seeder::class);
    }

    public function test_exact_phone_duplicate(): void
    {
        Lead::create([
            'contact_name' => 'Existing Person',
            'phone' => '(604) 555-0101',
            'email' => 'existing@example.com',
            'status' => 'new',
        ]);

        $parser = new LeadEmailParser;
        $parsed = $parser->parse(file_get_contents(base_path('tests/fixtures/lead_emails/clean_lead.txt')));

        $result = app(DuplicateLeadDetector::class)->detect($parsed);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame('exact_phone', $result['match_type']);
    }

    public function test_exact_email_duplicate(): void
    {
        Lead::create([
            'contact_name' => 'Email Match',
            'phone' => '(604) 111-2222',
            'email' => 'intake-email-dup-test@example.com',
            'status' => 'new',
        ]);

        $parser = new LeadEmailParser;
        $parsed = $parser->parse("Name: Email Dup\nPhone: (604) 222-3333\nEmail: intake-email-dup-test@example.com\nService: Paint\nMessage: Test");

        $result = app(DuplicateLeadDetector::class)->detect($parsed);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame('exact_email', $result['match_type']);
    }

    public function test_no_duplicate_for_new_contact(): void
    {
        $parser = new LeadEmailParser;
        $parsed = $parser->parse("Name: Unique Person\nPhone: (604) 888-7777\nEmail: unique-intake-test@example.com\nService: Insulation\nMessage: Attic work");

        $result = app(DuplicateLeadDetector::class)->detect($parsed);

        $this->assertFalse($result['is_duplicate']);
    }

    public function test_fuzzy_name_and_description_match(): void
    {
        Lead::create([
            'contact_name' => 'Alex Morgan',
            'phone' => '(604) 999-8888',
            'email' => 'fuzzy-test-other@example.com',
            'project_description' => 'Kitchen cabinet refresh, patch walls, drywall or painting team needed.',
            'status' => 'new',
        ]);

        $parser = new LeadEmailParser;
        $parsed = $parser->parse("Name: Alex Morgan\nPhone: (604) 555-0001\nEmail: fuzzy-test-new@example.com\nService: Renovation\nMessage: Kitchen cabinet refresh, patch walls, drywall or painting team needed.");

        $result = app(DuplicateLeadDetector::class)->detect($parsed);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame('fuzzy_name_description', $result['match_type']);
    }

    public function test_pipeline_creates_lead_and_logs_in_suggestion_mode(): void
    {
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);

        $pipeline = app(LeadIntakePipeline::class);
        $result = $pipeline->process(
            "Subject: Coquitlam Drywall Client From Bil\nName: Suggestion Mode Test\nPhone: (604) 777-0001\nEmail: suggestion-mode-test@example.com\nService: Drywall\nMessage: Popcorn ceiling removal",
            sendNotifications: true,
        );

        $this->assertFalse($result->duplicate);
        $this->assertNotNull($result->lead);
        $this->assertSame('drywall_paint', $result->classification['service_category']);
        $this->assertNotNull($result->companySourceId);
        $this->assertSame('Fraser Valley Drywall', $result->lead->companySource?->company_name);
        $this->assertNotNull($result->lead->assigned_pm_id);
        $this->assertTrue($result->notifications['customer']['draft'] ?? false);
    }

    public function test_voicemail_pipeline_flags_review_and_stores_recording(): void
    {
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);

        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/voicemail_insulation_vancouver.txt'));
        // Unique caller so prior local pipeline / seed data don't trip duplicate detection.
        $raw = str_replace('+16043413809', '+16049998877', $raw);

        $pipeline = app(LeadIntakePipeline::class);
        $result = $pipeline->process($raw, sendNotifications: false);

        $this->assertFalse($result->duplicate);
        $this->assertNotNull($result->lead);
        $this->assertTrue($result->lead->needs_manual_review);
        $this->assertSame('insulation', $result->classification['service_category']);
        $this->assertSame('Insulation Ethos', $result->lead->companySource?->company_name);
        $this->assertNotNull($result->lead->parse_metadata['recording_url'] ?? null);
        $this->assertSame('voicemail', $result->lead->parse_metadata['email_format'] ?? null);
    }

    public function test_kill_switch_skips_messaging(): void
    {
        Setting::set('ai_mode_lead_intake', 'autopilot');
        Setting::setBool('ai_kill_switch', true);

        $pipeline = app(LeadIntakePipeline::class);
        $result = $pipeline->process(
            "Name: Kill Switch Test\nPhone: (604) 777-0002\nEmail: kill-switch-test@example.com\nService: Insulation\nMessage: Attic blown-in insulation",
            sendNotifications: true,
        );

        $this->assertNotNull($result->lead);
        $this->assertSame('kill_switch', $result->notifications['customer']['reason'] ?? null);
    }

    public function test_duplicate_pipeline_does_not_create_second_lead(): void
    {
        Lead::create([
            'contact_name' => 'Sample Customer',
            'phone' => '(604) 555-0101',
            'email' => 'customer@example.com',
            'status' => 'new',
        ]);

        $before = Lead::where('email', 'customer@example.com')->count();

        $pipeline = app(LeadIntakePipeline::class);
        $result = $pipeline->process(
            file_get_contents(base_path('tests/fixtures/lead_emails/clean_lead.txt')),
            sendNotifications: true,
        );

        $this->assertTrue($result->duplicate);
        $this->assertSame($before, Lead::where('email', 'customer@example.com')->count());
    }

    public function test_pipeline_creates_admin_next_action_when_no_pm(): void
    {
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);

        $pipeline = app(LeadIntakePipeline::class);
        $result = $pipeline->process(
            "Name: No PM Fallback\nPhone: (604) 777-0003\nEmail: no-pm-fallback-test@example.com\nService: (not sure)\nMessage: Basement water damage maybe?",
            sendNotifications: false,
        );

        $this->assertNotNull($result->lead);
        $this->assertNull($result->lead->assigned_pm_id);

        $nextAction = \App\Models\NextAction::where('subject_id', $result->lead->id)
            ->where('subject_type', (new Lead)->getMorphClass())
            ->latest()
            ->first();

        $this->assertNotNull($nextAction);
        $this->assertSame('owner', $nextAction->responsible_role);
        $this->assertStringContainsString('Assign a PM', $nextAction->action_description);
    }
}
