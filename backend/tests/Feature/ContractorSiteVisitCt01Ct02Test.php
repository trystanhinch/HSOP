<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\Contractors\ContractorAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Audit CT-01 / CT-02 — contractor site-visit visibility + lead-stage PM messaging.
 */
class ContractorSiteVisitCt01Ct02Test extends TestCase
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
        if (! Schema::hasColumn('messages', 'lead_id')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_29_000001_messages_lead_id_ct02.php',
                '--force' => true,
            ]);
        }
    }

    private function makeUsers(string $suffix): array
    {
        $pm = User::create([
            'name' => 'CT PM '.$suffix,
            'email' => "ct-pm-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'pm',
            'status' => 'active',
            'phone' => '6045550101',
            'is_test_data' => false,
        ]);
        $contractor = User::create([
            'name' => 'CT Contractor '.$suffix,
            'email' => "ct-con-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'status' => 'active',
            'phone' => '6045550102',
            'is_test_data' => false,
        ]);
        $other = User::create([
            'name' => 'Other Con '.$suffix,
            'email' => "ct-other-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'status' => 'active',
            'is_test_data' => false,
        ]);
        $owner = User::where('role', 'owner')->first() ?: User::create([
            'name' => 'Owner',
            'email' => "ct-owner-{$suffix}@test.local",
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
            'is_test_data' => false,
        ]);

        return compact('pm', 'contractor', 'other', 'owner');
    }

    private function makeSiteVisitLead(User $pm, User $contractor, string $suffix): Lead
    {
        $lead = Lead::create([
            'contact_name' => 'Walkthrough Tester '.$suffix,
            'email' => "walkthrough.tester.{$suffix}@example.com",
            'phone' => '604555'.substr($suffix, -4),
            'address' => '100 CT-01 Visit Ave',
            'service_category' => 'drywall_paint',
            'status' => 'site_visit_scheduled',
            'source' => 'website',
            'project_description' => 'CT-01 site visit scope',
            'assigned_pm_id' => $pm->id,
            'site_visit_contractor_id' => $contractor->id,
            'site_visit_date' => now()->addDays(2)->toDateString(),
            'site_visit_time' => '10:00:00',
            'is_test_data' => false,
        ]);

        SiteVisit::create([
            'lead_id' => $lead->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'visit_date' => $lead->site_visit_date,
            'visit_time' => '10:00:00',
            'status' => 'scheduled',
        ]);

        return $lead->fresh();
    }

    public function test_1_jobs_endpoint_previously_missed_site_visits_now_includes_them(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        // Reproduce the old bug class: Job query alone finds nothing.
        $this->assertSame(0, Job::where('contractor_id', $users['contractor']->id)->count());

        $this->actingAs($users['contractor'], 'sanctum');
        $jobs = $this->getJson('/api/jobs');
        $jobs->assertOk();
        $data = $jobs->json('data');
        $this->assertNotEmpty($data);
        $visit = collect($data)->firstWhere('lead_id', $lead->id);
        $this->assertNotNull($visit, 'Site visit must appear on Jobs list');
        $this->assertSame('site_visit', $visit['type']);
        $this->assertSame('/leads/'.$lead->id, $visit['url']);

        $leads = $this->getJson('/api/contractor/leads');
        $leads->assertOk();
        $this->assertTrue(collect($leads->json('data'))->contains(fn ($r) => (int) $r['id'] === (int) $lead->id
            || (int) ($r['lead_id'] ?? 0) === (int) $lead->id));

        $dash = $this->getJson('/api/dashboard/contractor/kpis');
        $dash->assertOk();
        $this->assertTrue(collect($dash->json('site_visits'))->contains(fn ($sv) => (int) $sv['lead_id'] === (int) $lead->id));
        $this->assertSame('/leads/'.$lead->id, collect($dash->json('site_visits'))->firstWhere('lead_id', $lead->id)['url']);

        $month = now()->addDays(2)->format('Y-m');
        $sched = $this->getJson('/api/schedule?month='.$month);
        $sched->assertOk();
        $this->assertTrue(collect($sched->json('site_visits'))->contains(fn ($sv) => (int) $sv['lead_id'] === (int) $lead->id));
    }

    public function test_2_shared_service_deep_links_match_across_surfaces(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        $svc = app(ContractorAssignmentService::class);
        $fromJobs = $svc->workItemsFor($users['contractor'])->firstWhere('lead_id', $lead->id);
        $fromLeads = $svc->serializeOpenLeadAssignments($users['contractor'])->firstWhere('lead_id', $lead->id);
        $fromDash = $svc->upcomingSiteVisitsFor($users['contractor'])->firstWhere('lead_id', $lead->id);

        $this->assertSame($fromJobs['url'], $fromLeads['url']);
        $this->assertSame($fromJobs['url'], $fromDash['url']);
        $this->assertSame($lead->contact_name, $fromJobs['customer']['name']);
        $this->assertSame($users['pm']->id, $fromJobs['pm']['id']);
        $this->assertStringContainsString('CT-01 site visit scope', (string) $fromLeads['project_description']);
    }

    public function test_3_message_pm_on_lead_notifies_and_shares_thread(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        $this->actingAs($users['contractor'], 'sanctum');
        $send = $this->postJson('/api/leads/'.$lead->id.'/messages', [
            'content' => 'Where should I park for the visit?',
        ]);
        $send->assertCreated();
        $this->assertSame('contractor_to_pm', $send->json('channel'));
        $this->assertSame($lead->id, $send->json('lead_id'));

        $this->actingAs($users['pm'], 'sanctum');
        $thread = $this->getJson('/api/leads/'.$lead->id.'/messages');
        $thread->assertOk();
        $this->assertCount(1, $thread->json('messages'));
        $this->assertSame('Where should I park for the visit?', $thread->json('messages.0.content'));

        $reply = $this->postJson('/api/leads/'.$lead->id.'/messages', [
            'content' => 'Visitor parking on the street.',
        ]);
        $reply->assertCreated();
        $this->assertSame('pm_to_contractor', $reply->json('channel'));
    }

    public function test_4_thread_carries_forward_on_convert(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        $this->actingAs($users['contractor'], 'sanctum');
        $this->postJson('/api/leads/'.$lead->id.'/messages', ['content' => 'Pre-convert context'])->assertCreated();

        $this->actingAs($users['owner'], 'sanctum');
        $convert = $this->postJson('/api/leads/'.$lead->id.'/convert-to-job');
        $convert->assertCreated();
        $jobId = $convert->json('job_id');
        $this->assertNotNull($jobId);

        $msg = Message::where('lead_id', $lead->id)->first();
        $this->assertSame((int) $jobId, (int) $msg->job_id);

        $this->actingAs($users['contractor'], 'sanctum');
        $jobThread = $this->getJson('/api/jobs/'.$jobId.'/messages');
        $jobThread->assertOk();
        $contents = collect($jobThread->json())->pluck('content');
        $this->assertTrue($contents->contains('Pre-convert context'));
    }

    public function test_5_contractor_cannot_access_other_assignment_messages(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        $this->actingAs($users['other'], 'sanctum');
        $this->getJson('/api/leads/'.$lead->id.'/messages')->assertForbidden();
        $this->postJson('/api/leads/'.$lead->id.'/messages', ['content' => 'sneak'])->assertForbidden();
    }

    public function test_6_contractor_cannot_see_pm_internal_notes_on_job(): void
    {
        $suffix = substr(uniqid(), -6);
        $users = $this->makeUsers($suffix);
        $lead = $this->makeSiteVisitLead($users['pm'], $users['contractor'], $suffix);

        $this->actingAs($users['owner'], 'sanctum');
        $jobId = $this->postJson('/api/leads/'.$lead->id.'/convert-to-job')->json('job_id');

        Message::create([
            'job_id' => $jobId,
            'lead_id' => $lead->id,
            'sender_id' => $users['pm']->id,
            'sender_role' => 'pm',
            'content' => 'OWNER ONLY internal strategy',
            'visibility' => 'internal',
            'channel' => 'pm_internal',
        ]);
        Message::create([
            'job_id' => $jobId,
            'lead_id' => $lead->id,
            'sender_id' => $users['contractor']->id,
            'sender_role' => 'contractor',
            'content' => 'Visible to contractor thread',
            'visibility' => 'internal',
            'channel' => 'contractor_to_pm',
        ]);

        $this->actingAs($users['contractor'], 'sanctum');
        $res = $this->getJson('/api/jobs/'.$jobId.'/messages');
        $res->assertOk();
        $contents = collect($res->json())->pluck('content');
        $this->assertTrue($contents->contains('Visible to contractor thread'));
        $this->assertFalse($contents->contains('OWNER ONLY internal strategy'));
    }
}
