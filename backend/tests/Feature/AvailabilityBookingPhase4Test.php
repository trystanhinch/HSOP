<?php

namespace Tests\Feature;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Models\SlotClaim;
use App\Models\User;
use App\Services\Booking\BookingService;
use Carbon\Carbon;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvailabilityBookingPhase4Test extends TestCase
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
        $app['config']->set('booking.hold_ttl_seconds', 600);
        $app['config']->set('booking.default_timezone', 'America/Vancouver');

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
        // Monday afternoon UTC → Monday morning Pacific (seeded Mon–Fri windows apply)
        $this->travelTo(Carbon::parse('2026-07-27 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function brandHeaders(string $domain = 'acuteradrywall.ca'): array
    {
        return [
            'X-Brand-Domain' => $domain,
            'Host' => $domain,
        ];
    }

    private function nextOpenSlot(Brand $brand, ?string $service = 'drywall_paint'): array
    {
        $slots = app(BookingService::class)->availableSlots($brand, $service);
        $this->assertNotEmpty($slots, 'Expected at least one open slot for brand '.$brand->domain);

        return $slots[0];
    }

    public function test_public_availability_returns_brand_scoped_slots(): void
    {
        $headers = $this->brandHeaders();
        $res = $this->getJson('/api/public/availability?service=drywall_paint&days=14', $headers);
        $res->assertOk();
        $this->assertGreaterThan(0, $res->json('count'));
        $this->assertNotEmpty($res->json('slots.0.slot_start'));
        $this->assertNotEmpty($res->json('slots.0.resource_key'));
    }

    public function test_hold_then_submit_confirms_booking_and_site_visit(): void
    {
        $headers = $this->brandHeaders();
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $slot = $this->nextOpenSlot($brand);

        $start = $this->postJson('/api/public/intake/start', [], $headers)->json();
        $token = $start['session_token'];

        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'I need drywall repair about 120 sqft in Coquitlam',
        ], $headers)->assertOk();

        $hold = $this->postJson('/api/public/availability/hold', [
            'session_token' => $token,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
            'service' => 'drywall_paint',
        ], $headers);
        $hold->assertCreated();
        $holdToken = $hold->json('hold_token');

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Book Tester '.$suffix,
            'phone' => '(604) 700-'.$suffix,
            'email' => 'book-'.$suffix.'@example.com',
            'project_description' => 'Drywall booking flow '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Coquitlam',
        ], $headers);

        $submit->assertOk()->assertJsonPath('duplicate', false);
        $this->assertNotNull($submit->json('booking.id'));

        $lead = Lead::findOrFail($submit->json('lead_id'));
        $this->assertSame('site_visit_scheduled', $lead->status);
        $this->assertNotNull($lead->site_visit_date);
        $this->assertNotNull($lead->site_visit_time);
        $this->assertTrue(SiteVisit::where('lead_id', $lead->id)->exists());
        $this->assertTrue(Booking::where('lead_id', $lead->id)->where('status', 'confirmed')->exists());
        $this->assertSame('confirmed', BookingHold::where('hold_token', $holdToken)->value('status'));
    }

    public function test_second_hold_on_same_slot_fails_with_409(): void
    {
        $headers = $this->brandHeaders();
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $slot = $this->nextOpenSlot($brand);

        $a = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $b = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');

        $first = $this->postJson('/api/public/availability/hold', [
            'session_token' => $a,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
        ], $headers);
        $first->assertCreated();

        $second = $this->postJson('/api/public/availability/hold', [
            'session_token' => $b,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
        ], $headers);
        $second->assertStatus(409)->assertJsonPath('code', 'slot_unavailable');
    }

    /**
     * Concurrent proof: unique index on slot_claims rejects a second insert for the same slot
     * even when both pass the pre-check (simulated race). Only one claim row exists afterward.
     */
    public function test_concurrent_slot_claim_unique_constraint_allows_only_one(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $slot = $this->nextOpenSlot($brand);
        $start = Carbon::parse($slot['slot_start'])->utc();
        $end = Carbon::parse($slot['slot_end'])->utc();
        $resource = $slot['resource_key'];

        $ok = 0;
        $fail = 0;

        for ($i = 0; $i < 2; $i++) {
            try {
                DB::transaction(function () use ($brand, $resource, $start, $end, $i) {
                    // Mimic race: both transactions see no claim, both attempt insert
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
                        'claim_id' => 900000 + $i,
                        'expires_at' => now()->addMinutes(10),
                    ]);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->assertTrue(
                    str_contains(strtolower($e->getMessage()), 'duplicate')
                    || str_contains($e->getMessage(), '1062')
                    || str_contains($e->getMessage(), 'slot_claims_unique_slot'),
                    'Expected unique violation, got: '.$e->getMessage()
                );
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, $fail);
        $this->assertSame(1, SlotClaim::where('brand_id', $brand->id)->where('resource_key', $resource)->where('slot_start', $start)->count());
    }

    public function test_hold_expires_and_slot_reopens(): void
    {
        $headers = $this->brandHeaders();
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $slot = $this->nextOpenSlot($brand);
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');

        config(['booking.hold_ttl_seconds' => 30]);
        $hold = $this->postJson('/api/public/availability/hold', [
            'session_token' => $token,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
        ], $headers);
        $hold->assertCreated();

        // Force expiry
        BookingHold::where('hold_token', $hold->json('hold_token'))->update([
            'held_until' => now()->subMinute(),
        ]);
        SlotClaim::where('claim_type', 'hold')
            ->where('claim_id', BookingHold::where('hold_token', $hold->json('hold_token'))->value('id'))
            ->update(['expires_at' => now()->subMinute()]);

        $released = app(BookingService::class)->releaseExpiredHolds($brand->id);
        $this->assertGreaterThanOrEqual(1, $released);

        $again = $this->postJson('/api/public/availability/hold', [
            'session_token' => $this->postJson('/api/public/intake/start', [], $headers)->json('session_token'),
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
        ], $headers);
        $again->assertCreated();
    }

    public function test_second_brand_availability_is_independent(): void
    {
        $roof = Brand::create([
            'domain' => 'example-roofing.test',
            'slug' => 'example-roofing-phase4',
            'company_name' => 'Example Roofing',
            'status' => 'active',
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof']],
            ],
        ]);

        // Tomorrow morning roofing-only window
        $tomorrow = now('America/Vancouver')->addDay()->startOfDay();
        AvailabilityWindow::create([
            'brand_id' => $roof->id,
            'day_of_week' => null,
            'specific_date' => $tomorrow->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
            'service_category' => 'roofing',
        ]);

        $acutera = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $aSlot = $this->nextOpenSlot($acutera, 'drywall_paint');

        $roofSlots = app(BookingService::class)->availableSlots($roof, 'roofing');
        $this->assertNotEmpty($roofSlots);
        foreach ($roofSlots as $s) {
            $this->assertStringStartsWith('brand:'.$roof->id, $s['resource_key']);
        }

        // Hold Acutera slot — must not block roofing brand same wall-clock if different resource_key/brand
        $headersA = $this->brandHeaders('acuteradrywall.ca');
        $tokenA = $this->postJson('/api/public/intake/start', [], $headersA)->json('session_token');
        $this->postJson('/api/public/availability/hold', [
            'session_token' => $tokenA,
            'slot_start' => $aSlot['slot_start'],
            'slot_end' => $aSlot['slot_end'],
            'resource_key' => $aSlot['resource_key'],
        ], $headersA)->assertCreated();

        $roofStillOpen = app(BookingService::class)->availableSlots($roof, 'roofing');
        $this->assertNotEmpty($roofStillOpen);

        $headersR = $this->brandHeaders('example-roofing.test');
        $tokenR = $this->postJson('/api/public/intake/start', [], $headersR)->json('session_token');
        $this->postJson('/api/public/availability/hold', [
            'session_token' => $tokenR,
            'slot_start' => $roofStillOpen[0]['slot_start'],
            'slot_end' => $roofStillOpen[0]['slot_end'],
            'resource_key' => $roofStillOpen[0]['resource_key'],
            'service' => 'roofing',
        ], $headersR)->assertCreated();
    }

    public function test_milestone4_manual_site_visit_schedule_unaffected(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $contractor = User::where('role', 'contractor')->first()
            ?: User::create([
                'name' => 'Sched Con', 'email' => 'sched-con-'.uniqid().'@test.local',
                'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active', 'phone' => '6045550999',
            ]);

        $lead = Lead::create([
            'contact_name' => 'Manual Visit',
            'email' => 'manual-'.uniqid().'@test.local',
            'phone' => '6045550888',
            'address' => '99 Manual Ave',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'brand_id' => Brand::where('domain', 'acuteradrywall.ca')->value('id'),
            'assigned_pm_id' => $owner->id,
            'customer_portal_token' => Str::random(64),
        ]);

        $this->actingAs($owner, 'sanctum');
        $res = $this->postJson("/api/leads/{$lead->id}/schedule-site-visit", [
            'site_visit_date' => now('America/Vancouver')->addDays(3)->toDateString(),
            'site_visit_time' => '10:30',
            'site_visit_contractor_id' => $contractor->id,
            'site_visit_notes' => 'M4 regression check',
        ]);
        $res->assertOk();
        $lead->refresh();
        $this->assertSame('site_visit_scheduled', $lead->status);
        $this->assertSame('10:30', substr((string) $lead->site_visit_time, 0, 5));
        $this->assertTrue(SiteVisit::where('lead_id', $lead->id)->exists());
    }
}
