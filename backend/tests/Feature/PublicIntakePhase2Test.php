<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\LeadPhoto;
use App\Models\Setting;
use App\Services\LeadIntake\LeadIntakePipeline;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicIntakePhase2Test extends TestCase
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
        $app['config']->set('filesystems.uploads_disk', 'public');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::setBool('ai_kill_switch', false);
        Setting::set('ai_mode_public_intake', 'assisted');
        RateLimiter::clear('public-intake');
        RateLimiter::clear('public-intake-start');
        RateLimiter::clear('public-intake-message');
        RateLimiter::clear('public-intake-submit');
        RateLimiter::clear('public-intake-media');
        Storage::fake('public');
    }

    /** @return array<string, string> */
    private function brandHeaders(string $domain = 'acuteradrywall.ca'): array
    {
        return [
            'X-Brand-Domain' => $domain,
            'Host' => $domain,
        ];
    }

    public function test_sse_message_streams_delta_and_done(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();

        $response = $this->withHeaders(array_merge($this->brandHeaders(), [
            'Accept' => 'text/event-stream',
        ]))->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'I need drywall repair',
            'stream' => true,
        ]);

        $response->assertOk();
        $body = method_exists($response, 'streamedContent')
            ? $response->streamedContent()
            : $response->getContent();
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('Acutera Drywall', $body);

        $session = IntakeSession::findOrFail($start['session_id']);
        $this->assertGreaterThanOrEqual(2, count($session->messages()));
    }

    public function test_session_resume_returns_persisted_state(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'I need insulation in the attic',
        ], $this->brandHeaders())->assertOk();

        $resume = $this->getJson(
            '/api/public/intake/session?session_token='.$start['session_token'],
            $this->brandHeaders()
        );

        $resume->assertOk()
            ->assertJsonPath('session_id', $start['session_id'])
            ->assertJsonPath('brand.domain', 'acuteradrywall.ca');

        $this->assertNotEmpty($resume->json('messages'));
        $this->assertSame('insulation', $resume->json('collected.service_category'));
    }

    public function test_media_upload_attaches_to_lead_on_submit(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $token = $start['session_token'];
        $suffix = substr(uniqid(), -6);

        $tmp = tempnam(sys_get_temp_dir(), 'intake');
        file_put_contents($tmp, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z'));
        $file = new UploadedFile($tmp, 'site-photo.jpg', 'image/jpeg', null, true);

        $media = $this->withHeaders($this->brandHeaders())->post('/api/public/intake/media', [
            'session_token' => $token,
            'photos' => [$file],
        ]);

        $media->assertOk();
        $this->assertGreaterThanOrEqual(1, $media->json('count'));

        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Photo Tester '.$suffix,
            'phone' => '(604) 590-'.$suffix,
            'email' => 'photo-'.$suffix.'@example.com',
            'project_description' => 'Need drywall patch after leak '.$suffix,
            'service_category' => 'drywall_paint',
        ], $this->brandHeaders());

        $submit->assertOk()->assertJsonPath('duplicate', false);
        $leadId = $submit->json('lead_id');
        $this->assertGreaterThanOrEqual(1, LeadPhoto::where('lead_id', $leadId)->count());

        $lead = Lead::findOrFail($leadId);
        $this->assertNotEmpty($lead->parse_metadata['attachments'] ?? []);
    }

    public function test_kill_switch_returns_graceful_reply(): void
    {
        Setting::setBool('ai_kill_switch', true);
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();

        $res = $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'Hello',
        ], $this->brandHeaders());

        $res->assertOk()
            ->assertJsonPath('provider', 'mock_kill_switch');
        $this->assertStringContainsString('temporarily paused', $res->json('reply'));
    }

    public function test_second_brand_streaming_and_pages_data(): void
    {
        $ethos = CompanySource::where('company_name', 'Insulation Ethos')->firstOrFail();
        Brand::create([
            'domain' => 'example-roofing.test',
            'slug' => 'example-roofing',
            'company_name' => 'Example Roofing Co',
            'company_source_id' => $ethos->id,
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof', 'shingle']],
            ],
            'branding' => ['tone' => 'direct'],
            'contact_info' => [],
            'seo_defaults' => ['title_template' => '{{company_name}} | Quote'],
            'status' => 'active',
        ]);

        $headers = $this->brandHeaders('example-roofing.test');

        $brand = $this->getJson('/api/public/brand', $headers);
        $brand->assertOk()
            ->assertJsonPath('brand.company_name', 'Example Roofing Co')
            ->assertJsonPath('brand.service_categories.0.key', 'roofing');

        $start = $this->postJson('/api/public/intake/start', [], $headers)->json();
        $msg = $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'I need roof repair',
        ], $headers);

        $msg->assertOk();
        $this->assertStringContainsString('Example Roofing Co', $msg->json('reply'));
        $this->assertSame('roofing', $msg->json('collected.service_category'));

        $this->assertGreaterThan(
            0,
            AiActionLog::where('trigger_event', 'public_intake_chat')->count()
        );
    }

    public function test_phase1_json_message_still_works(): void
    {
        $start = $this->postJson('/api/public/intake/start', [], $this->brandHeaders())->json();
        $this->postJson('/api/public/intake/message', [
            'session_token' => $start['session_token'],
            'message' => 'drywall please',
        ], $this->brandHeaders())
            ->assertOk()
            ->assertJsonStructure(['reply', 'collected', 'provider']);
    }

    public function test_email_intake_regression(): void
    {
        Setting::set('ai_mode_lead_intake', 'suggestion');
        Setting::setBool('ai_kill_switch', false);

        $raw = "Name: Email Reg ".uniqid()."\nPhone: (604) 555-0999\nEmail: email2-".uniqid()."@example.com\nService: Drywall\nMessage: Patch hole";
        $result = app(LeadIntakePipeline::class)->process($raw, sendNotifications: false);

        $this->assertFalse($result->duplicate);
        $this->assertNotNull($result->lead);
        $this->assertNull($result->lead->intake_channel);
    }
}
