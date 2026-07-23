<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\Setting;
use App\Services\LeadIntake\LeadIntakePipeline;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicIntakePhase1Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('ai.conversational_provider', 'mock');
        $app['config']->set('public.local_default_brand_domain', 'acuteradrywall.ca');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::setBool('ai_kill_switch', false);
        RateLimiter::clear('public-intake');
        RateLimiter::clear('public-intake-start');
        RateLimiter::clear('public-intake-message');
        RateLimiter::clear('public-intake-submit');
    }

    /** @return array<string, string> */
    private function brandHeaders(string $domain = 'acuteradrywall.ca'): array
    {
        return [
            'X-Brand-Domain' => $domain,
            'Host' => $domain,
        ];
    }

    public function test_brand_endpoint_returns_public_config(): void
    {
        $res = $this->getJson('/api/public/brand', $this->brandHeaders());

        $res->assertOk()
            ->assertJsonPath('brand.domain', 'acuteradrywall.ca')
            ->assertJsonPath('brand.company_name', 'Acutera Drywall & Paint');
    }

    public function test_unknown_domain_returns_404(): void
    {
        $this->postJson('/api/public/intake/start', [], [
            'X-Brand-Domain' => 'unknown-brand.example',
            'Host' => 'unknown-brand.example',
        ])->assertStatus(404)
            ->assertJsonPath('error', 'brand_not_found');
    }

    public function test_start_creates_session_and_returns_token(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();

        $res = $this->postJson('/api/public/intake/start', [], $this->brandHeaders());

        $res->assertOk()
            ->assertJsonStructure(['session_id', 'session_token', 'expires_at', 'brand'])
            ->assertJsonPath('brand.domain', 'acuteradrywall.ca');

        $this->assertDatabaseHas('intake_sessions', [
            'session_token' => $res->json('session_token'),
            'brand_id' => $brand->id,
        ]);
        $this->assertNotEmpty($res->headers->getCookies());
        $this->assertSame('acutera-drywall', $res->headers->get('X-Resolved-Brand'));
    }

    public function test_message_returns_mock_reply_and_updates_state(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();

        $res = $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'I need drywall repair in Coquitlam',
        ], $this->brandHeaders());

        $res->assertOk()
            ->assertJsonStructure(['reply', 'ready_to_submit', 'collected', 'provider']);

        $this->assertNotEmpty($res->json('reply'));
        $this->assertSame('mock', $res->json('provider'));
        $session = IntakeSession::where('session_token', $start['session_token'])->first();
        $this->assertCount(2, $session->messages());
        $this->assertSame('drywall_paint', $session->conversation_state['collected']['service_category'] ?? null);
        // Brand name appears in start payload / empty-turn greeting; mid-turn asks for next field.
        $this->assertSame('Acutera Drywall & Paint', $start['brand']['company_name']);
    }

    public function test_submit_creates_lead_via_shared_path(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $acuteraSourceId = CompanySource::where('company_name', 'Acutera Drywall & Paint')->value('id');
        $this->assertNotNull($acuteraSourceId);
        $this->assertSame($acuteraSourceId, $brand->company_source_id);
        $this->assertNotEquals(
            CompanySource::where('company_name', 'Fraser Valley Drywall')->value('id'),
            $acuteraSourceId,
            'Acutera must use a dedicated CompanySource, not FVD'
        );

        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $token = $start['session_token'];
        $suffix = substr(uniqid(), -6);
        $phone = '(604) 570-'.$suffix;
        $email = 'phase1-public-'.$suffix.'@example.com';

        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'I need insulation in the attic',
        ], $this->brandHeaders())->assertOk();

        $res = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Phase1 Public Tester '.$suffix,
            'phone' => $phone,
            'email' => $email,
            'address' => 'Coquitlam',
            'project_description' => 'Attic insulation top-up for a 1500 sq ft home '.$suffix,
            'service_category' => 'insulation',
        ], $this->brandHeaders());

        $res->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('intake_channel', 'website_chat')
            ->assertJsonPath('source', 'website')
            ->assertJsonPath('brand_id', $brand->id)
            ->assertJsonPath('company_source_id', $acuteraSourceId);

        $leadId = $res->json('lead_id');
        $this->assertNotNull($leadId);

        $lead = Lead::findOrFail($leadId);
        $this->assertSame('website_chat', $lead->intake_channel);
        $this->assertSame($start['session_id'], $lead->conversation_id);
        $this->assertSame('website', $lead->source);
        $this->assertSame($brand->id, $lead->brand_id);
        $this->assertSame($acuteraSourceId, $lead->company_source_id);
        $this->assertIsArray($lead->parse_metadata);
        $this->assertArrayHasKey('conversation_transcript', $lead->parse_metadata);
        $this->assertSame($brand->domain, $lead->parse_metadata['brand_domain'] ?? null);

        $session = IntakeSession::findOrFail($start['session_id']);
        $this->assertSame($leadId, $session->converted_lead_id);
    }

    public function test_submit_duplicate_phone_attaches_without_new_lead(): void
    {
        $suffix = substr(uniqid(), -6);
        $phone = '(604) 571-'.$suffix;
        $existing = Lead::create([
            'contact_name' => 'Existing Dup '.$suffix,
            'phone' => $phone,
            'email' => 'dup-existing-'.$suffix.'@example.com',
            'status' => 'new',
            'source' => 'email',
            'project_description' => 'Original project '.$suffix,
        ]);

        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $beforeCount = Lead::count();
        $res = $this->postJson('/api/public/intake/submit', [
            'session_token' => $start['session_token'],
            'contact_name' => 'Dup Visitor '.$suffix,
            'phone' => $phone,
            'email' => 'dup-visitor-'.$suffix.'@example.com',
            'project_description' => 'Another request for the same phone '.$suffix,
            'service_category' => 'drywall_paint',
        ], $this->brandHeaders());

        $res->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('duplicate_match_type', 'exact_phone')
            ->assertJsonPath('lead_id', $existing->id);

        $this->assertSame($beforeCount, Lead::count());
        $this->assertSame(0, Lead::where('contact_name', 'Dup Visitor '.$suffix)->count());
    }

    public function test_start_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->assertOk();
        }

        $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->assertStatus(429);
    }

    public function test_second_brand_zero_code_change(): void
    {
        $ethos = CompanySource::where('company_name', 'Insulation Ethos')->firstOrFail();

        $other = Brand::create([
            'domain' => 'example-roofing.test',
            'slug' => 'example-roofing',
            'company_name' => 'Example Roofing Co',
            'company_source_id' => $ethos->id,
            'service_categories' => [
                [
                    'key' => 'roofing',
                    'label' => 'Roofing',
                    'keywords' => ['roof', 'shingle', 'gutter'],
                ],
            ],
            'branding' => ['tone' => 'direct and practical'],
            'contact_info' => [],
            'seo_defaults' => [],
            'status' => 'active',
        ]);

        $headers = $this->brandHeaders('example-roofing.test');

        $brandRes = $this->getJson('/api/public/brand', $headers);
        $brandRes->assertOk()->assertJsonPath('brand.company_name', 'Example Roofing Co');

        $start = $this->postJson('/api/public/intake/start', [], $headers)->json();
        $this->assertSame($other->id, IntakeSession::find($start['session_id'])->brand_id);
        $this->assertSame('Example Roofing Co', $start['brand']['company_name']);

        $greet = $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'hello',
        ], $headers);
        $greet->assertOk();
        $this->assertStringContainsString('Example Roofing Co', $greet->json('reply'));

        $msg = $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'I need roof shingle repair near Burnaby',
        ], $headers);

        $msg->assertOk();
        $this->assertSame('roofing', $msg->json('collected.service_category'));

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $start['session_token'],
            'contact_name' => 'Roof Tester '.$suffix,
            'phone' => '(604) 580-'.$suffix,
            'email' => 'roof-'.$suffix.'@example.com',
            'project_description' => 'Replace damaged shingles on south slope '.$suffix,
            'service_category' => 'roofing',
        ], $headers);

        $submit->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('brand_id', $other->id)
            ->assertJsonPath('company_source_id', $ethos->id)
            ->assertJsonPath('source', 'website');

        $lead = Lead::findOrFail($submit->json('lead_id'));
        $this->assertSame('roofing', $lead->service_category);
        $this->assertSame($other->id, $lead->brand_id);
    }

    public function test_email_intake_pipeline_still_works(): void
    {
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);

        $raw = "Name: Email Regression ".uniqid()."\nPhone: (604) 555-0444\nEmail: email-reg-".uniqid()."@example.com\nService: Drywall\nMessage: Popcorn ceiling removal";
        $result = app(LeadIntakePipeline::class)->process($raw, sendNotifications: false);

        $this->assertFalse($result->duplicate);
        $this->assertNotNull($result->lead);
        $this->assertNull($result->lead->intake_channel);
        $this->assertNull($result->lead->brand_id);
        $this->assertNotSame('website', $result->lead->source);
    }
}
