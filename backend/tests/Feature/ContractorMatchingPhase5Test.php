<?php

namespace Tests\Feature;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\Contractor;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\ContractorBookingMatcher;
use Carbon\Carbon;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractorMatchingPhase5Test extends TestCase
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
        RateLimiter::clear('public-intake');
        RateLimiter::clear('public-intake-start');
        RateLimiter::clear('public-intake-message');
        RateLimiter::clear('public-intake-submit');
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

    /**
     * @return array{user: User, contractor: Contractor}
     */
    private function makeApprovedContractor(string $name, array $services): array
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
            'phone' => '604555'.random_int(1000, 9999),
        ]);
        $contractor = Contractor::create([
            'user_id' => $user->id,
            'legal_name' => $name.' Ltd',
            'operating_name' => $name,
            'contact_name' => $name,
            'phone' => $user->phone,
            'email' => $user->email,
            'services' => $services,
            'approval_status' => 'approved',
        ]);

        return compact('user', 'contractor');
    }

    public function test_booking_confirm_auto_matches_least_recently_assigned(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $source = CompanySource::findOrFail($brand->company_source_id);

        $a = $this->makeApprovedContractor('Match Alpha', ['Drywall', 'Painting']);
        $b = $this->makeApprovedContractor('Match Bravo', ['Drywall', 'Painting']);
        $source->update(['default_contractor_ids' => [$a['user']->id, $b['user']->id]]);

        // Alpha was assigned more recently → Bravo should win (least-recently-assigned)
        Booking::create([
            'brand_id' => $brand->id,
            'lead_id' => Lead::create([
                'contact_name' => 'Prior',
                'email' => 'prior-'.uniqid().'@test.local',
                'phone' => '6045550001',
                'service_category' => 'drywall_paint',
                'status' => 'site_visit_scheduled',
                'brand_id' => $brand->id,
                'company_source_id' => $source->id,
            ])->id,
            'resource_key' => 'brand:'.$brand->id,
            'contractor_id' => $a['user']->id,
            'service_category' => 'drywall_paint',
            'slot_start' => now()->subDays(2),
            'slot_end' => now()->subDays(2)->addHour(),
            'timezone' => 'America/Vancouver',
            'status' => 'confirmed',
            'confirmed_at' => now()->subDay(),
            'auto_matched' => true,
            'match_rule' => ContractorBookingMatcher::RULE,
        ]);

        $headers = $this->brandHeaders();
        $slot = app(BookingService::class)->availableSlots($brand, 'drywall_paint')[0];
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'I need drywall repair about 100 sqft in Surrey',
        ], $headers)->assertOk();
        $this->postJson('/api/public/availability/hold', [
            'session_token' => $token,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
            'service' => 'drywall_paint',
        ], $headers)->assertCreated();

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Match Lead '.$suffix,
            'phone' => '(604) 711-'.$suffix,
            'email' => 'match-'.$suffix.'@example.com',
            'project_description' => 'Drywall match test '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Surrey',
        ], $headers);
        $submit->assertOk();

        $lead = Lead::findOrFail($submit->json('lead_id'));
        $booking = Booking::where('lead_id', $lead->id)->first();
        $this->assertNotNull($booking);
        $this->assertTrue((bool) $booking->auto_matched);
        $this->assertSame(ContractorBookingMatcher::RULE, $booking->match_rule);
        $this->assertSame($b['user']->id, (int) $booking->contractor_id);
        $this->assertSame($b['user']->id, (int) $lead->site_visit_contractor_id);
        $this->assertSame($b['user']->id, (int) $lead->assigned_contractor_id);
    }

    public function test_no_eligible_contractor_creates_next_action(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $source = CompanySource::findOrFail($brand->company_source_id);
        $source->update(['default_contractor_ids' => []]); // empty pool

        $headers = $this->brandHeaders();
        $slot = app(BookingService::class)->availableSlots($brand, 'drywall_paint')[0];
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'Drywall patch about 80 sqft Burnaby',
        ], $headers)->assertOk();
        $this->postJson('/api/public/availability/hold', [
            'session_token' => $token,
            'slot_start' => $slot['slot_start'],
            'slot_end' => $slot['slot_end'],
            'resource_key' => $slot['resource_key'],
        ], $headers)->assertCreated();

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'No Pool '.$suffix,
            'phone' => '(604) 722-'.$suffix,
            'email' => 'nopool-'.$suffix.'@example.com',
            'project_description' => 'No pool test '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Burnaby',
        ], $headers);
        $submit->assertOk();

        $lead = Lead::findOrFail($submit->json('lead_id'));
        $booking = Booking::where('lead_id', $lead->id)->first();
        $this->assertNotNull($booking);
        $this->assertNull($booking->contractor_id);
        $this->assertFalse((bool) $booking->auto_matched);
        $this->assertNotNull($booking->match_meta['next_action_id'] ?? null);
        $this->assertTrue(
            NextAction::where('subject_id', $lead->id)
                ->where('escalation_rule', 'assign_contractor_booking')
                ->where('status', 'pending')
                ->exists()
        );
    }

    public function test_pm_manual_reassign_overrides_match(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $source = CompanySource::findOrFail($brand->company_source_id);
        $a = $this->makeApprovedContractor('Override A', ['Drywall']);
        $b = $this->makeApprovedContractor('Override B', ['Drywall']);
        $source->update(['default_contractor_ids' => [$a['user']->id]]);

        $owner = User::where('role', 'owner')->firstOrFail();
        $lead = Lead::create([
            'contact_name' => 'Override Lead',
            'email' => 'ovr-'.uniqid().'@test.local',
            'phone' => '6045550444',
            'address' => '11 Override St',
            'service_category' => 'drywall_paint',
            'status' => 'site_visit_scheduled',
            'brand_id' => $brand->id,
            'company_source_id' => $source->id,
            'assigned_pm_id' => $owner->id,
            'assigned_contractor_id' => $a['user']->id,
            'site_visit_contractor_id' => $a['user']->id,
            'customer_portal_token' => Str::random(64),
        ]);
        $booking = Booking::create([
            'brand_id' => $brand->id,
            'lead_id' => $lead->id,
            'resource_key' => 'brand:'.$brand->id,
            'contractor_id' => $a['user']->id,
            'auto_matched' => true,
            'match_rule' => ContractorBookingMatcher::RULE,
            'match_meta' => ['reason' => 'auto'],
            'service_category' => 'drywall_paint',
            'slot_start' => now()->addDay(),
            'slot_end' => now()->addDay()->addHour(),
            'timezone' => 'America/Vancouver',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum');
        $res = $this->putJson("/api/leads/{$lead->id}", [
            'assigned_contractor_id' => $b['user']->id,
        ]);
        $res->assertOk();
        $booking->refresh();
        $lead->refresh();
        $this->assertSame($b['user']->id, (int) $lead->assigned_contractor_id);
        $this->assertSame($b['user']->id, (int) $booking->contractor_id);
        $this->assertFalse((bool) $booking->auto_matched);
        $this->assertSame('manual_pm_override', $booking->match_rule);
    }

    public function test_second_brand_pool_is_independent(): void
    {
        $acutera = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $acuteraSource = CompanySource::findOrFail($acutera->company_source_id);
        $acuteraCon = $this->makeApprovedContractor('Acutera Only', ['Drywall']);
        $acuteraSource->update(['default_contractor_ids' => [$acuteraCon['user']->id]]);

        $roofSource = CompanySource::create([
            'company_name' => 'Roof Co '.uniqid(),
            'service_categories' => ['Roofing'],
            'status' => 'active',
        ]);
        $roofBrand = Brand::create([
            'domain' => 'example-roofing-p5.test',
            'slug' => 'example-roofing-p5',
            'company_name' => 'Example Roofing P5',
            'company_source_id' => $roofSource->id,
            'status' => 'active',
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof']],
            ],
        ]);
        $roofCon = $this->makeApprovedContractor('Roof Only', ['Roofing', 'Shingles']);
        $roofSource->update(['default_contractor_ids' => [$roofCon['user']->id]]);

        AvailabilityWindow::create([
            'brand_id' => $roofBrand->id,
            'specific_date' => now('America/Vancouver')->addDay()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
            'service_category' => 'roofing',
        ]);

        $lead = Lead::create([
            'contact_name' => 'Roof Lead',
            'email' => 'roof-'.uniqid().'@test.local',
            'phone' => '6045550555',
            'service_category' => 'roofing',
            'status' => 'new',
            'brand_id' => $roofBrand->id,
            'company_source_id' => $roofSource->id,
        ]);

        $result = app(ContractorBookingMatcher::class)->matchForLead($lead, $roofBrand);
        $this->assertTrue($result['matched']);
        $this->assertSame($roofCon['user']->id, $result['contractor_user_id']);
        $this->assertNotSame($acuteraCon['user']->id, $result['contractor_user_id']);
    }

    public function test_milestone4_manual_site_visit_assignment_unaffected(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $con = $this->makeApprovedContractor('Manual SV', ['Drywall']);
        $lead = Lead::create([
            'contact_name' => 'Manual SV Lead',
            'email' => 'mansv-'.uniqid().'@test.local',
            'phone' => '6045550666',
            'address' => '55 Manual Ave',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'brand_id' => Brand::where('domain', 'acuteradrywall.ca')->value('id'),
            'assigned_pm_id' => $owner->id,
            'customer_portal_token' => Str::random(64),
        ]);

        $this->actingAs($owner, 'sanctum');
        $res = $this->postJson("/api/leads/{$lead->id}/schedule-site-visit", [
            'site_visit_date' => now('America/Vancouver')->addDays(2)->toDateString(),
            'site_visit_time' => '11:00',
            'site_visit_contractor_id' => $con['user']->id,
            'site_visit_notes' => 'M4 regression Phase 5',
        ]);
        $res->assertOk();
        $lead->refresh();
        $this->assertSame($con['user']->id, (int) $lead->site_visit_contractor_id);
        $this->assertSame('site_visit_scheduled', $lead->status);
    }
}
