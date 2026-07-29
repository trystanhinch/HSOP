<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CompanySource;
use App\Models\CompanySourceVersion;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\LeadIntake\LeadIntakePipeline;
use App\Services\LeadIntake\LeadIntakeQuarantineService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-20 / A-25 — pricing calculator + company source parser health.
 */
class PricingSourcesA20A25Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('mail.default', 'array');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::set('gst_rate', '5');
        Setting::set('split_contractor_pct', '80');
        Setting::set('split_pm_pct', '10');
        Setting::set('split_company_pct', '10');
        Setting::set('markup_divisor', '0.80');

        if (! Schema::hasTable('pricing_setting_versions')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000008_a20_a25_pricing_sources.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::where('role', 'owner')->firstOrFail();
    }

    /** TC-1: $800 / 80/10/10 live calculator. */
    public function test_1_calculator_800_split_preview(): void
    {
        Sanctum::actingAs($this->owner());
        $res = $this->getJson('/api/settings/pricing-preview?contractor_price=800&split_contractor_pct=80&split_pm_pct=10&split_company_pct=10&gst_rate=5');
        $res->assertOk();
        $this->assertEquals(1000.0, (float) $res->json('customer_subtotal'));
        $this->assertEquals(50.0, (float) $res->json('gst'));
        $this->assertEquals(1050.0, (float) $res->json('customer_total'));
        $this->assertEquals(800.0, (float) $res->json('contractor_share'));
        $this->assertEquals(100.0, (float) $res->json('pm_share'));
        $this->assertEquals(100.0, (float) $res->json('company_share'));
        $this->assertStringContainsString('GST', (string) $res->json('gst_label'));
    }

    /** TC-2: splits that don't total 100 are blocked. */
    public function test_2_invalid_split_blocked(): void
    {
        Sanctum::actingAs($this->owner());
        $this->postJson('/api/settings', [
            'split_contractor_pct' => 70,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
            'confirm_pricing_change' => true,
        ])->assertStatus(422);

        $this->getJson('/api/settings/pricing-preview?contractor_price=800&split_contractor_pct=70&split_pm_pct=10&split_company_pct=10')
            ->assertStatus(422);
    }

    /** TC-3: existing jobs keep their original split after settings change. */
    public function test_3_existing_jobs_keep_split(): void
    {
        $owner = $this->owner();
        $lead = Lead::create([
            'contact_name' => 'Split Keep',
            'email' => 'split-keep-'.uniqid().'@test.local',
            'phone' => '6045550666',
            'address' => '1 Keep St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
        ]);
        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $owner->id,
            'address' => '1 Keep St',
            'status' => 'scheduled',
            'contractor_base_price' => 800,
            'split_contractor_pct' => 80,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/settings', [
            'split_contractor_pct' => 70,
            'split_pm_pct' => 15,
            'split_company_pct' => 15,
            'gst_rate' => 5,
            'confirm_pricing_change' => true,
        ])->assertOk();

        $job->refresh();
        $this->assertEquals(80.0, (float) $job->split_contractor_pct);
        $this->assertEquals(10.0, (float) $job->split_pm_pct);
        $this->assertEquals(10.0, (float) $job->split_company_pct);
        $this->assertEquals('70', Setting::get('split_contractor_pct'));
    }

    /** TC-4: Test Parser dry-run — extract + match, no lead. */
    public function test_4_test_parser_no_lead_created(): void
    {
        $source = CompanySource::create([
            'company_name' => 'Acutera Drywall Forms',
            'domain' => 'acuteradrywall.ca',
            'sender_identity' => 'forms@acuteradrywall.ca',
            'status' => 'active',
            'priority' => 10,
            'parser_type' => 'lead_email_v1',
            'parser_version' => '1.0',
            'fallback_behavior' => 'category_then_quarantine',
            'intake_allow_patterns' => ['new website lead'],
        ]);

        $before = Lead::count();
        Sanctum::actingAs($this->owner());

        $raw = "From: forms@acuteradrywall.ca\nSubject: New website lead\n\nName: Jane Parser\nPhone: 604-555-0199\nEmail: jane@example.com\nAddress: 12 Test Ave\nService: drywall repair\nMessage: Need ceiling patch\n";
        $res = $this->postJson('/api/company-sources/test-parser', ['raw_email' => $raw]);
        $res->assertOk();
        $this->assertFalse((bool) $res->json('creates_lead'));
        $this->assertSame($source->id, $res->json('matched_source.id'));
        $this->assertNotEmpty($res->json('extracted.phone'));
        $this->assertSame($before, Lead::count());
    }

    /** TC-5: auto-created lead identifies matched source rule. */
    public function test_5_auto_lead_stores_matched_source_rule(): void
    {
        $pm = User::create([
            'name' => 'Source PM',
            'email' => 'src-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
        $source = CompanySource::create([
            'company_name' => 'Roofing Forms Co',
            'domain' => 'roofing-forms-'.uniqid().'.ca',
            'sender_identity' => 'leads@roofing-forms.test',
            'default_pm_id' => $pm->id,
            'status' => 'active',
            'priority' => 5,
            'service_categories' => ['roofing'],
        ]);

        $raw = "From: leads@roofing-forms.test\nSubject: Contact form\n\nName: Auto Lead\nPhone: 6045550888\nEmail: auto@example.com\nAddress: 9 Auto Rd Vancouver\nService: roofing inspection\nMessage: Leak near chimney\n";

        $parsed = app(LeadIntakePipeline::class)->getParser()->parse($raw);
        $result = app(LeadIntakeQuarantineService::class)->ingest($raw, $parsed, [
            'channel' => 'gmail',
            'mailbox_email' => 'inbox@test.local',
            'send_notifications' => false,
            'is_test_data' => true,
        ]);

        $this->assertNotNull($result->lead, 'Expected auto-approve lead; outcome='.$result->outcome.' reason='.($result->quarantine?->quarantine_reason ?? ''));
        $this->assertSame($source->id, $result->lead->company_source_id);
        $meta = $result->lead->parse_metadata ?? [];
        $this->assertSame($source->id, $meta['matched_source_rule']['company_source_id'] ?? null);
        $this->assertNotEmpty($meta['matched_source_rule']['match_method'] ?? null);
    }

    /** TC-6: changing a source rule is audited + versioned. */
    public function test_6_source_rule_change_audited_versioned(): void
    {
        $owner = $this->owner();
        $source = CompanySource::create([
            'company_name' => 'Versioned Source',
            'domain' => 'old-domain.example',
            'status' => 'active',
            'priority' => 50,
        ]);

        Sanctum::actingAs($owner);
        $this->putJson('/api/company-sources/'.$source->id, [
            'company_name' => 'Versioned Source',
            'domain' => 'new-domain.example',
            'status' => 'active',
            'priority' => 20,
            'intake_allow_patterns' => ['hello form'],
        ])->assertOk();

        $this->assertDatabaseHas('company_source_versions', [
            'company_source_id' => $source->id,
            'version' => 1,
        ]);
        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'company_source_updated')
                ->where('object_id', $source->id)
                ->exists()
        );
        $ver = CompanySourceVersion::where('company_source_id', $source->id)->first();
        $this->assertSame('old-domain.example', $ver->previous_values['domain'] ?? null);
        $this->assertSame('new-domain.example', $ver->new_values['domain'] ?? null);
    }
}
