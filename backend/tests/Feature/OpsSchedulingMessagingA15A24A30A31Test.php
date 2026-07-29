<?php

namespace Tests\Feature;

use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\Brand;
use App\Models\BusinessHoursProfile;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\Calendar\CalendarConflictService;
use App\Services\Calendar\CalendarService;
use App\Services\Workflow\BusinessHoursCalculator;
use App\Services\Workflow\WorkflowSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A-15 business-hours thresholds, A-24 availability safeguards,
 * A-30 admin messaging surface, A-31 unified calendar.
 */
class OpsSchedulingMessagingA15A24A30A31Test extends TestCase
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
        if (! Schema::hasTable('business_hours_profiles')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000011_a15_a24_a30_a31_ops.php',
                '--force' => true,
            ]);
        }
    }

    private function owner(): User
    {
        return User::create([
            'name' => 'A15 Owner',
            'email' => 'a15-owner-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function pm(): User
    {
        return User::create([
            'name' => 'A15 PM',
            'email' => 'a15-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    private function contractor(): User
    {
        return User::create([
            'name' => 'A15 Contractor',
            'email' => 'a15-ct-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
        ]);
    }

    private function brand(): Brand
    {
        return Brand::create([
            'domain' => 'a15-'.uniqid().'.test',
            'company_name' => 'A15 Brand',
            'slug' => 'a15-'.uniqid(),
            'status' => 'active',
            'service_categories' => ['drywall'],
        ]);
    }

    private function ensureBusinessHours(): BusinessHoursProfile
    {
        Setting::set('workflow_clock_mode', 'business');
        $profile = BusinessHoursProfile::query()->where('is_default', true)->first();
        if (! $profile) {
            $profile = BusinessHoursProfile::create([
                'name' => 'Default 9-5',
                'timezone' => 'America/Vancouver',
                'weekly_hours' => BusinessHoursProfile::defaultWeeklyHours(),
                'holidays' => [],
                'is_default' => true,
            ]);
        }
        Setting::set('workflow_business_hours_profile_id', (string) $profile->id);

        return $profile;
    }

    /** TC1 — 4h contact at 6pm does not land overnight; due is next business morning+hours. */
    public function test_1_business_hours_four_hour_rule_from_6pm(): void
    {
        $this->ensureBusinessHours();
        Setting::set('workflow_pm_contact_lead_hours', '4');

        $from = Carbon::parse('2026-07-27 18:00:00', 'America/Vancouver'); // Mon 6pm
        $calc = app(BusinessHoursCalculator::class);
        $profile = $calc->resolveProfile();
        $due = $calc->addThresholdHours($from, 4, $profile, 'business');

        $this->assertTrue($due->greaterThan(Carbon::parse('2026-07-28 09:00:00', 'America/Vancouver')));
        $this->assertSame('2026-07-28', $due->format('Y-m-d'));
        $this->assertSame('13:00', $due->format('H:i'));

        // Wall-clock would have been 22:00 same night — business mode must not.
        $this->assertNotSame('2026-07-27', $due->format('Y-m-d'));

        $settings = app(WorkflowSettings::class);
        Carbon::setTestNow(Carbon::parse('2026-07-27 18:00:00', 'America/Vancouver')->utc());
        $dueAt = $settings->pmContactDueAt();
        Carbon::setTestNow();
        $this->assertTrue($dueAt->greaterThanOrEqualTo(Carbon::parse('2026-07-28 09:00:00', 'America/Vancouver')));
    }

    public function test_1b_invalid_threshold_blocked(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->putJson('/api/workflow/thresholds', [
            'pm_contact_lead_hours' => 0,
        ])->assertStatus(422);
    }

    public function test_1c_preview_timeline_shows_actors(): void
    {
        $this->ensureBusinessHours();
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->postJson('/api/workflow/thresholds/preview', [
            'pm_contact_lead_hours' => 4,
            'pm_contact_escalation_hours' => 4,
            'clock_mode' => 'business',
            'from' => '2026-07-27T18:00:00-07:00',
        ])->assertOk()
            ->assertJsonPath('notified.0.who', 'assigned_pm')
            ->assertJsonPath('notified.1.who', 'owner')
            ->assertJsonStructure(['preview_timeline']);
    }

    /** TC2 — deactivate blocked with resolution options. */
    public function test_2_deactivate_blocked_with_resolution_options(): void
    {
        $owner = $this->owner();
        $brand = $this->brand();
        $window = AvailabilityWindow::create([
            'brand_id' => $brand->id,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
            'capacity' => 1,
            'travel_buffer_minutes' => 15,
        ]);

        $lead = Lead::create([
            'brand_id' => $brand->id,
            'contact_name' => 'Booked Lead',
            'phone' => '+16045550100',
            'email' => 'booked-'.uniqid().'@test.local',
            'address' => '1 Test St',
            'service_category' => 'drywall',
            'status' => 'new',
            'source' => 'test',
        ]);

        Booking::create([
            'brand_id' => $brand->id,
            'lead_id' => $lead->id,
            'resource_key' => $window->resourceKey(),
            'slot_start' => now()->addDay(),
            'slot_end' => now()->addDay()->addHour(),
            'status' => 'confirmed',
            'timezone' => 'America/Vancouver',
        ]);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/availability/windows/'.$window->id)
            ->assertStatus(422)
            ->assertJsonPath('active_bookings', 1)
            ->assertJsonStructure([
                'booking_details',
                'resolution_options' => [['action', 'label']],
            ]);

        $this->getJson('/api/availability/windows/'.$window->id.'/deactivation-preview')
            ->assertOk()
            ->assertJsonPath('blocked', true);

        $this->postJson('/api/availability/windows/'.$window->id.'/resolve-deactivate', [
            'resolution' => 'cancel_then_deactivate',
            'confirm' => true,
        ])->assertOk();

        $this->assertSame('inactive', $window->fresh()->status);
        $this->assertSame('cancelled', Booking::query()->where('lead_id', $lead->id)->value('status'));
    }

    /** TC4 — site visit identical on Admin / PM / Contractor calendars. */
    public function test_4_site_visit_consistent_across_roles(): void
    {
        $owner = $this->owner();
        $pm = $this->pm();
        $contractor = $this->contractor();
        $customer = User::create([
            'name' => 'Cust',
            'email' => 'cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);

        $lead = Lead::create([
            'contact_name' => 'Walkthrough Tester',
            'phone' => '+16045550111',
            'email' => 'walk-'.uniqid().'@test.local',
            'address' => '99 Calendar Ave',
            'service_category' => 'drywall',
            'status' => 'site_visit_scheduled',
            'source' => 'test',
            'assigned_pm_id' => $pm->id,
            'assigned_contractor_id' => $contractor->id,
            'customer_id' => $customer->id,
        ]);

        $visitDate = now()->startOfMonth()->addDays(10);
        $sv = SiteVisit::create([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'customer_id' => $customer->id,
            'visit_date' => $visitDate->toDateString(),
            'visit_time' => '10:30:00',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $month = $visitDate->format('Y-m');
        $calendar = app(CalendarService::class);

        $ownerFeed = $calendar->forUser($owner, $month);
        $pmFeed = $calendar->forUser($pm, $month);
        $ctFeed = $calendar->forUser($contractor, $month);

        $pick = fn ($feed) => collect($feed['all'])->first(
            fn ($e) => ($e['type'] ?? '') === 'site_visit' && (int) $e['id'] === (int) $sv->id
        );

        $o = $pick($ownerFeed);
        $p = $pick($pmFeed);
        $c = $pick($ctFeed);

        $this->assertNotNull($o);
        $this->assertNotNull($p);
        $this->assertNotNull($c);
        $this->assertSame($o['title'], $p['title']);
        $this->assertSame($o['title'], $c['title']);
        $this->assertSame($o['date'], $c['date']);
        $this->assertSame($o['url'], $c['url']);
        $this->assertSame('/leads/'.$lead->id, $o['url']);
    }

    /** TC5 — double-book conflict for accepted assignment. */
    public function test_5_contractor_double_book_conflict_detected(): void
    {
        $contractor = $this->contractor();
        $customer = User::create([
            'name' => 'Cust2',
            'email' => 'cust2-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'contact_name' => 'Conflict Lead',
            'phone' => '+16045550122',
            'email' => 'conf-'.uniqid().'@test.local',
            'address' => '1 Conflict St',
            'service_category' => 'drywall',
            'status' => 'site_visit_scheduled',
            'source' => 'test',
            'assigned_contractor_id' => $contractor->id,
            'customer_id' => $customer->id,
        ]);

        $date = now()->addDays(3)->toDateString();
        SiteVisit::create([
            'lead_id' => $lead->id,
            'contractor_id' => $contractor->id,
            'customer_id' => $customer->id,
            'visit_date' => $date,
            'visit_time' => '11:00:00',
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $result = app(CalendarConflictService::class)->checkContractorSlot(
            $contractor->id,
            $date,
            '11:00',
            null,
            null,
            60,
            0
        );

        $this->assertTrue($result['conflict']);
        $this->assertNotEmpty($result['conflicts']);

        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->postJson('/api/schedule/conflicts/check', [
            'contractor_id' => $contractor->id,
            'date' => $date,
            'time' => '11:00',
        ])->assertStatus(409)
            ->assertJsonPath('conflict', true);
    }

    /** A-30 — owner can open contractor messaging channel. */
    public function test_30_owner_contractor_messages_channel(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->getJson('/api/pm-contractor-messages/conversations')->assertOk();
    }

    /** A-24 — duplicate window. */
    public function test_24_duplicate_availability_window(): void
    {
        $owner = $this->owner();
        $brand = $this->brand();
        $window = AvailabilityWindow::create([
            'brand_id' => $brand->id,
            'day_of_week' => 3,
            'start_time' => '13:00',
            'end_time' => '16:00',
            'slot_duration_minutes' => 60,
            'timezone' => 'America/Vancouver',
            'status' => 'active',
        ]);

        Sanctum::actingAs($owner);
        $this->postJson('/api/availability/windows/'.$window->id.'/duplicate')
            ->assertCreated()
            ->assertJsonPath('brand_id', $brand->id);
    }
}
