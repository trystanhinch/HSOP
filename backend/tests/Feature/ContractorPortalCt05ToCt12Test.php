<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Job;
use App\Models\Lead;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\Contractors\ContractorAssignmentLifecycleService;
use App\Services\Contractors\ContractorAvailabilityService;
use App\Services\Contractors\ContractorOnboardingService;
use App\Services\Contractors\ContractorProfileCompleteness;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Final audit batch — CT-05 … CT-12 contractor portal.
 */
class ContractorPortalCt05ToCt12Test extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasColumn('site_visits', 'assignment_state')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_30_000001_contractor_portal_ct05_ct12.php',
                '--force' => true,
            ]);
        }
    }

    private function makeContractor(array $userAttrs = [], array $profileAttrs = []): array
    {
        $suffix = substr(uniqid(), -6);
        $user = User::create(array_merge([
            'name' => 'CT Cont '.$suffix,
            'email' => "ct-cont-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'status' => 'active',
            'phone' => '+1604555'.substr($suffix, -4),
            'is_test_data' => false,
        ], $userAttrs));

        $profile = app(ContractorProfileCompleteness::class)->ensureProfileForUser($user);
        $profile->fill(array_merge([
            'legal_name' => $user->name,
            'operating_name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'services' => ['drywall_paint'],
            'cities' => ['Vancouver'],
            'wcb_status' => 'approved',
            'liability_insurance_status' => 'approved',
            'state' => 'approved',
            'approval_status' => 'approved',
        ], $profileAttrs));
        $profile->save();

        return [$user, $profile->fresh()];
    }

    private function makePm(): User
    {
        return User::create([
            'name' => 'CT PM',
            'email' => 'ct-pm-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
    }

    /** TC1 — Stripe status: masked ref, no raw requirement paths. */
    public function test_1_stripe_status_plain_language_and_masked(): void
    {
        [$user] = $this->makeContractor([
            'stripe_account_id' => 'acct_1RawPathNeverShow',
            'stripe_onboarding_status' => 'pending',
            'stripe_payout_ready' => false,
            'stripe_requirements_due' => ['individual.verification.document', 'external_account'],
        ]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/stripe/connect/status')->assertOk();
        $this->assertNull($res->json('stripe_account_id'));
        $this->assertSame('…Show', $res->json('stripe_account_ref'));
        $body = $res->getContent();
        $this->assertStringNotContainsString('acct_1RawPathNeverShow', $body);
        $this->assertStringNotContainsString('individual.verification.document', $body);
        $plain = $res->json('requirements_plain');
        $this->assertNotEmpty($plain);
        $this->assertTrue(collect($plain)->contains(fn ($s) => str_contains(strtolower($s), 'identity') || str_contains(strtolower($s), 'bank')));
    }

    /** TC2 — Offered until accept; never confirmed beforehand. */
    public function test_2_offer_not_confirmed_until_accept(): void
    {
        [$contractor] = $this->makeContractor();
        $pm = $this->makePm();
        $lead = Lead::create([
            'contact_name' => 'Offer Cust',
            'phone' => '6045552001',
            'email' => 'offer-'.uniqid().'@example.com',
            'address' => '10 Offer St',
            'status' => 'site_visit_scheduled',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractor->id,
            'is_test_data' => false,
        ]);
        $lifecycle = app(ContractorAssignmentLifecycleService::class);
        $sv = SiteVisit::create(array_merge([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'visit_date' => now()->addDays(2)->toDateString(),
            'visit_time' => '10:00',
        ], $lifecycle->offerAttributes()));

        Sanctum::actingAs($contractor);
        $show = $this->getJson('/api/site-visits/'.$sv->id)->assertOk();
        $this->assertFalse((bool) $show->json('assignment.is_confirmed'));
        $this->assertContains($show->json('assignment.assignment_state'), ['offered', 'viewed']);
        $this->assertNotEquals('Confirmed', $show->json('assignment.assignment_state_label'));

        $this->postJson('/api/site-visits/'.$sv->id.'/accept')->assertOk()
            ->assertJsonPath('assignment.assignment_state', 'confirmed')
            ->assertJsonPath('assignment.is_confirmed', true);

        Sanctum::actingAs($pm);
        $sv->refresh();
        $this->assertSame('confirmed', app(ContractorAssignmentLifecycleService::class)->effectiveState($sv));
    }

    /** TC3 — Schedule agenda payload has detail fields. */
    public function test_3_contractor_schedule_agenda_details(): void
    {
        [$contractor] = $this->makeContractor();
        $pm = $this->makePm();
        $lead = Lead::create([
            'contact_name' => 'Agenda Cust',
            'phone' => '6045552002',
            'address' => '55 Agenda Rd',
            'status' => 'site_visit_scheduled',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractor->id,
            'is_test_data' => false,
        ]);
        $lifecycle = app(ContractorAssignmentLifecycleService::class);
        SiteVisit::create(array_merge([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'visit_date' => now()->toDateString(),
            'visit_time' => '11:00',
        ], $lifecycle->offerAttributes()));

        Sanctum::actingAs($contractor);
        $res = $this->getJson('/api/schedule?month='.now()->format('Y-m').'&view=agenda')->assertOk();
        $event = collect($res->json('all') ?? [])->firstWhere('type', 'site_visit');
        $this->assertNotNull($event);
        $this->assertArrayHasKey('assignment_state_label', $event);
        $this->assertArrayHasKey('customer_name', $event);
        $this->assertArrayHasKey('directions_url', $event);
        $this->assertArrayHasKey('next_action', $event);
    }

    /** TC4 — Unavailable window blocks new offers; accepted work untouched. */
    public function test_4_availability_blocks_new_offers_not_accepted(): void
    {
        [$contractor, $profile] = $this->makeContractor();
        $avail = app(ContractorAvailabilityService::class);
        $avail->update($profile, [
            'blackout_ranges' => [[
                'start' => now()->addDays(3)->toDateString(),
                'end' => now()->addDays(5)->toDateString(),
            ]],
        ]);
        $profile->refresh();

        $blocked = Carbon::parse(now()->addDays(4)->toDateString().' 10:00');
        $this->assertFalse($avail->canReceiveNewOffer($profile, $blocked));

        $pm = $this->makePm();
        $lifecycle = app(ContractorAssignmentLifecycleService::class);
        $lead = Lead::create([
            'contact_name' => 'Accepted Keep',
            'phone' => '6045552003',
            'address' => '99 Keep St',
            'status' => 'site_visit_scheduled',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractor->id,
            'is_test_data' => false,
        ]);
        $sv = SiteVisit::create(array_merge([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'visit_date' => now()->addDays(4)->toDateString(),
            'visit_time' => '10:00',
        ], $lifecycle->offerAttributes()));
        $lifecycle->accept($sv);
        $sv->refresh();
        $this->assertTrue($lifecycle->isConfirmed($sv));
        $this->assertSame($contractor->id, (int) $sv->contractor_id);
    }

    /** TC5 — Empty payouts explain why. */
    public function test_5_payouts_empty_reason(): void
    {
        [$contractor] = $this->makeContractor();
        Sanctum::actingAs($contractor);
        $res = $this->getJson('/api/payouts')->assertOk();
        $this->assertSame(0, (int) ($res->json('total') ?? 0));
        $this->assertNotEmpty($res->json('empty_reason.message'));
        $this->assertSame('no_jobs', $res->json('empty_reason.reason_code'));
    }

    /** TC6 — API failure must not look like empty success (controller returns 403 for wrong role). */
    public function test_6_availability_permission_not_empty(): void
    {
        $pm = $this->makePm();
        Sanctum::actingAs($pm);
        $this->getJson('/api/me/contractor/availability')->assertForbidden();
    }

    /** TC7 — Onboarding checklist + blocking reasons. */
    public function test_7_onboarding_checklist_and_readiness(): void
    {
        $suffix = substr(uniqid(), -6);
        $user = User::create([
            'name' => 'New Cont '.$suffix,
            'email' => "new-cont-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'status' => 'active',
            'is_test_data' => false,
        ]);
        // Incomplete profile
        app(ContractorProfileCompleteness::class)->ensureProfileForUser($user);

        Sanctum::actingAs($user);
        $res = $this->getJson('/api/me/contractor/onboarding')->assertOk();
        $this->assertGreaterThanOrEqual(5, count($res->json('steps')));
        $this->assertFalse($res->json('readiness.can_receive_site_visits.ready'));
        $this->assertNotEmpty($res->json('readiness.can_receive_site_visits.blocking'));
        $this->assertFalse($res->json('readiness.can_receive_payouts.ready'));
    }

    /** Decline with reason alerts lifecycle. */
    public function test_decline_captures_reason(): void
    {
        [$contractor] = $this->makeContractor();
        $pm = $this->makePm();
        $lead = Lead::create([
            'contact_name' => 'Decline Cust',
            'phone' => '6045552004',
            'address' => '1 Decline Ave',
            'status' => 'site_visit_scheduled',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractor->id,
            'is_test_data' => false,
        ]);
        $lifecycle = app(ContractorAssignmentLifecycleService::class);
        $sv = SiteVisit::create(array_merge([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'visit_date' => now()->addDay()->toDateString(),
            'visit_time' => '09:00',
        ], $lifecycle->offerAttributes()));

        Sanctum::actingAs($contractor);
        $this->postJson('/api/site-visits/'.$sv->id.'/decline', ['reason' => 'Schedule conflict'])
            ->assertOk()
            ->assertJsonPath('assignment.assignment_state', 'declined');
        $this->assertSame('Schedule conflict', $sv->fresh()->decline_reason);
    }
}
