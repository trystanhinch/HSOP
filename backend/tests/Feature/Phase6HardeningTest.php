<?php

namespace Tests\Feature;

use App\Models\AvailabilityWindow;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SlotClaim;
use App\Services\Ai\OpenAiConversationalProvider;
use App\Services\Booking\BookingService;
use Carbon\Carbon;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Milestone 5 Phase 6 — hardening: security, load, degradation, multi-tenant E2E.
 */
class Phase6HardeningTest extends TestCase
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
        $app['config']->set('booking.default_timezone', 'America/Vancouver');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::setBool('ai_kill_switch', false);
        foreach ([
            'public-intake', 'public-intake-start', 'public-intake-message',
            'public-intake-submit', 'public-intake-media',
            'public-availability', 'public-availability-hold',
        ] as $limiter) {
            RateLimiter::clear($limiter);
        }
        \Illuminate\Support\Facades\Cache::forget('cors.active_brand_origins');
        $this->travelTo(Carbon::parse('2026-07-27 15:00:00', 'UTC'));
    }

    /** @return array<string, string> */
    private function brandHeaders(string $domain = 'acuteradrywall.ca'): array
    {
        return [
            'X-Brand-Domain' => $domain,
            'Host' => $domain,
        ];
    }

    public function test_public_intake_start_rate_limit_triggers(): void
    {
        $headers = $this->brandHeaders();
        // Limiter is 20/min — burn it down
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/public/intake/start', [], $headers)->assertOk();
        }
        $blocked = $this->postJson('/api/public/intake/start', [], $headers);
        $blocked->assertStatus(429);
    }

    public function test_submit_response_does_not_leak_parse_metadata_or_estimate_internals(): void
    {
        $headers = $this->brandHeaders();
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'Drywall repair 120 sqft Coquitlam',
        ], $headers)->assertOk();

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Secure '.$suffix,
            'phone' => '(604) 800-'.$suffix,
            'email' => 'sec-'.$suffix.'@example.com',
            'project_description' => 'Security submit '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Coquitlam',
        ], $headers);

        $submit->assertOk();
        $json = $submit->json();
        $this->assertArrayNotHasKey('parse_metadata', $json);
        $estimate = $json['price_estimate'] ?? null;
        if (is_array($estimate)) {
            $this->assertArrayNotHasKey('calculation', $estimate);
            $this->assertArrayNotHasKey('materials_assumptions', $estimate);
            $this->assertArrayNotHasKey('labour_assumptions', $estimate);
            $this->assertArrayNotHasKey('rule_id', $estimate);
        }
        $this->assertArrayNotHasKey('usage', $json);
    }

    public function test_brand_endpoint_exposes_no_secrets(): void
    {
        $res = $this->getJson('/api/public/brand', $this->brandHeaders());
        $res->assertOk();
        $brand = $res->json('brand');
        $this->assertIsArray($brand);
        foreach (['api_key', 'openai', 'password', 'secret', 'stripe', 'twilio'] as $bad) {
            $encoded = json_encode($brand);
            $this->assertStringNotContainsStringIgnoringCase($bad, (string) $encoded);
        }
        $this->assertArrayHasKey('company_name', $brand);
        $this->assertArrayHasKey('service_categories', $brand);
    }

    public function test_reject_non_image_upload(): void
    {
        $headers = $this->brandHeaders();
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $file = UploadedFile::fake()->create('payload.exe', 100, 'application/octet-stream');

        $res = $this->withHeaders(array_merge($headers, [
            'Accept' => 'application/json',
        ]))->post('/api/public/intake/media', [
            'session_token' => $token,
            'photos' => [$file],
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['photos.0']);
    }

    public function test_service_category_tool_rejects_foreign_brand_keys(): void
    {
        $provider = app(OpenAiConversationalProvider::class);
        $method = new ReflectionMethod($provider, 'applyFieldUpdates');
        $method->setAccessible(true);

        $services = [
            ['key' => 'drywall_paint', 'label' => 'Drywall & Paint', 'keywords' => ['drywall']],
        ];
        $out = $method->invoke(
            $provider,
            [],
            ['service_category' => 'roofing'],
            ['drywall_paint'],
            $services
        );

        $this->assertArrayNotHasKey('service_category', $out);
    }

    public function test_burst_holds_same_slot_only_one_wins(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $slot = app(BookingService::class)->availableSlots($brand, 'drywall_paint')[0];
        $start = Carbon::parse($slot['slot_start'])->utc();
        $end = Carbon::parse($slot['slot_end'])->utc();
        $resource = $slot['resource_key'];

        $ok = 0;
        $fail = 0;
        for ($i = 0; $i < 12; $i++) {
            try {
                DB::transaction(function () use ($brand, $resource, $start, $end, $i) {
                    SlotClaim::query()
                        ->where('brand_id', $brand->id)
                        ->where('resource_key', $resource)
                        ->where('slot_start', $start)
                        ->lockForUpdate()
                        ->get();

                    SlotClaim::create([
                        'brand_id' => $brand->id,
                        'resource_key' => $resource,
                        'slot_start' => $start,
                        'slot_end' => $end,
                        'claim_type' => 'hold',
                        'claim_id' => 800000 + $i,
                        'expires_at' => now()->addMinutes(5),
                    ]);
                });
                $ok++;
            } catch (\Throwable) {
                $fail++;
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(11, $fail);
        $this->assertSame(
            1,
            SlotClaim::where('brand_id', $brand->id)->where('resource_key', $resource)->where('slot_start', $start)->count()
        );
    }

    public function test_openai_rate_limit_falls_back_gracefully(): void
    {
        Config::set('ai.conversational_provider', 'openai');
        Config::set('ai.openai.api_key', 'sk-test-fake');
        Setting::setBool('ai_kill_switch', false);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit']], 429),
        ]);

        // Re-bind provider with fake key
        $this->app->forgetInstance(\App\Contracts\ConversationalAiProviderInterface::class);
        $provider = $this->app->make(\App\Contracts\ConversationalAiProviderInterface::class);

        $events = [];
        foreach ($provider->streamRespond(
            [['role' => 'user', 'content' => 'I need drywall help']],
            [],
            [
                'company_name' => 'Acutera Drywall & Paint',
                'service_categories' => [
                    ['key' => 'drywall_paint', 'label' => 'Drywall & Paint', 'keywords' => ['drywall']],
                ],
                'system_prompt' => 'You are the intake assistant.',
                'brand_id' => 1,
            ]
        ) as $event) {
            $events[] = $event;
        }

        $done = collect($events)->firstWhere('event', 'done');
        $this->assertNotNull($done);
        $this->assertSame('mock_openai_rate_limited', $done['provider']);
        $this->assertStringContainsString('high demand', strtolower((string) $done['reply']));
    }

    public function test_full_second_brand_flow_isolated_from_acutera(): void
    {
        $roofSource = CompanySource::create([
            'company_name' => 'Roof Phase6 '.uniqid(),
            'service_categories' => ['Roofing'],
            'status' => 'active',
            'default_contractor_ids' => [],
        ]);
        $roof = Brand::create([
            'domain' => 'example-roofing-p6.test',
            'slug' => 'example-roofing-p6',
            'company_name' => 'Example Roofing P6',
            'company_source_id' => $roofSource->id,
            'status' => 'active',
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof', 'shingle']],
            ],
        ]);
        AvailabilityWindow::create([
            'brand_id' => $roof->id,
            'specific_date' => now('America/Vancouver')->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
            'service_category' => 'roofing',
        ]);
        \App\Models\PricingRule::create([
            'brand_id' => $roof->id,
            'company_source_id' => $roofSource->id,
            'service_category' => 'roofing',
            'rule_type' => 'per_sqft',
            'base_rate' => 8,
            'size_tiers' => ['low_rate' => 6, 'high_rate' => 12, 'default_low_sqft' => 100, 'default_high_sqft' => 500],
            'complexity_modifiers' => ['simple' => 0.9, 'standard' => 1, 'complex' => 1.3, 'unknown' => 1, 'urgency_high' => 1.1],
            'min_price' => 500,
            'max_price' => 40000,
            'currency' => 'CAD',
            'is_placeholder' => true,
            'status' => 'active',
        ]);

        $headers = $this->brandHeaders('example-roofing-p6.test');
        $this->getJson('/api/public/brand', $headers)
            ->assertOk()
            ->assertJsonPath('brand.domain', 'example-roofing-p6.test');

        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $msg = $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'I need roof shingle repair about 200 sqft in Burnaby',
        ], $headers);
        $msg->assertOk();
        $this->assertSame('roofing', $msg->json('collected.service_category'));

        $avail = $this->getJson('/api/public/availability?service=roofing&days=7', $headers);
        $avail->assertOk();
        $this->assertGreaterThan(0, $avail->json('count'));
        $slot = $avail->json('slots.0');
        $this->assertStringStartsWith('brand:'.$roof->id, $slot['resource_key']);

        $this->postJson('/api/public/availability/hold', [
            'session_token' => $token,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
            'service' => 'roofing',
        ], $headers)->assertCreated();

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Roof Visitor '.$suffix,
            'phone' => '(604) 900-'.$suffix,
            'email' => 'roof6-'.$suffix.'@example.com',
            'project_description' => 'Roofing E2E '.$suffix,
            'service_category' => 'roofing',
            'address' => 'Burnaby',
        ], $headers);
        $submit->assertOk()->assertJsonPath('duplicate', false);
        $this->assertArrayNotHasKey('parse_metadata', $submit->json());

        $lead = Lead::findOrFail($submit->json('lead_id'));
        $this->assertSame($roof->id, (int) $lead->brand_id);
        $this->assertSame('roofing', $lead->service_category);
        $this->assertNotSame(
            Brand::where('domain', 'acuteradrywall.ca')->value('id'),
            $lead->brand_id
        );
    }

    public function test_http_burst_holds_same_slot_only_one_created(): void
    {
        $headers = $this->brandHeaders();
        $avail = $this->getJson('/api/public/availability?service=drywall_paint&days=7', $headers);
        $avail->assertOk();
        $slot = $avail->json('slots.0');
        $this->assertNotEmpty($slot);

        $created = 0;
        $conflicts = 0;
        for ($i = 0; $i < 10; $i++) {
            $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
            $res = $this->postJson('/api/public/availability/hold', [
                'session_token' => $token,
                'slot_start' => $slot['slot_start'],
                'slot_end' => $slot['slot_end'],
                'resource_key' => $slot['resource_key'],
                'service' => 'drywall_paint',
            ], $headers);
            if ($res->status() === 201) {
                $created++;
            } elseif ($res->status() === 409) {
                $conflicts++;
            }
        }

        $this->assertSame(1, $created);
        $this->assertSame(9, $conflicts);
    }

    public function test_http_holds_on_different_slots_all_succeed(): void
    {
        $headers = $this->brandHeaders();
        $slots = $this->getJson('/api/public/availability?service=drywall_paint&days=14', $headers)->json('slots');
        $this->assertGreaterThanOrEqual(3, count($slots));

        $ok = 0;
        foreach (array_slice($slots, 0, 3) as $slot) {
            $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
            $res = $this->postJson('/api/public/availability/hold', [
                'session_token' => $token,
                'slot_start' => $slot['slot_start'],
                'slot_end' => $slot['slot_end'],
                'resource_key' => $slot['resource_key'],
                'service' => 'drywall_paint',
            ], $headers);
            if ($res->status() === 201) {
                $ok++;
            }
        }

        $this->assertSame(3, $ok);
    }

    public function test_empty_availability_returns_sensible_payload(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        AvailabilityWindow::where('brand_id', $brand->id)->update(['status' => 'inactive']);

        $res = $this->getJson('/api/public/availability?service=drywall_paint', $this->brandHeaders());
        $res->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('slots', []);
    }

    public function test_cors_allows_registered_brand_origin_only(): void
    {
        \Illuminate\Support\Facades\Cache::forget('cors.active_brand_origins');

        $ok = $this->withHeaders([
            'Origin' => 'https://acuteradrywall.ca',
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])->get('/api/public/brand');
        $ok->assertOk();
        $this->assertSame(
            'https://acuteradrywall.ca',
            $ok->headers->get('Access-Control-Allow-Origin')
        );

        $this->assertContains('https://acuteradrywall.ca', config('cors.allowed_origins', []));
        $this->assertNotContains('*', config('cors.allowed_origins', []));
        $this->assertSame([], config('cors.allowed_origins_patterns', []));

        $bad = $this->withHeaders([
            'Origin' => 'https://evil-attacker.example',
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])->get('/api/public/brand');
        $bad->assertOk();
        $this->assertNull($bad->headers->get('Access-Control-Allow-Origin'));
    }
}
