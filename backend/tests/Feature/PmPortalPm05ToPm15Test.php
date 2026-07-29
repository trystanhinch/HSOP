<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\User;
use App\Services\Workflow\JobLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PM portal P1/P2 — PM-05, PM-07, PM-08, PM-09, PM-10/15, PM-11, PM-13, PM-14.
 */
class PmPortalPm05ToPm15Test extends TestCase
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

    private function makePm(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'PM Portal '.$this->suffix(),
            'email' => 'pm-portal-'.$this->suffix().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'pm',
            'status' => 'active',
            'is_test_data' => false,
        ], $attrs));
    }

    private function makeCustomerUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Cust '.$this->suffix(),
            'email' => 'cust-portal-'.$this->suffix().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '+1604555'.substr($this->suffix(), -4),
            'is_test_data' => false,
        ], $attrs));
    }

    private function suffix(): string
    {
        return substr(str_replace('.', '', uniqid('', true)), -8);
    }

    /** TC1 — Stripe status: no raw account ID; mode present. */
    public function test_1_pm_stripe_status_masks_account_id(): void
    {
        $pm = $this->makePm([
            'stripe_account_id' => 'acct_1FullExposeNever',
            'stripe_onboarding_status' => 'complete',
            'stripe_payout_ready' => true,
        ]);
        Sanctum::actingAs($pm);

        $res = $this->getJson('/api/stripe/connect/status');
        $res->assertOk();
        $res->assertJsonPath('payout_ready', true);
        $res->assertJsonPath('has_stripe_account', true);
        $res->assertJsonPath('stripe_account_ref', '…ever');
        $this->assertNull($res->json('stripe_account_id'));
        $this->assertContains($res->json('mode'), ['LIVE', 'TEST']);
        $body = $res->getContent();
        $this->assertStringNotContainsString('acct_1FullExposeNever', $body);
        $this->assertNull($res->json('payout_status_note'));
    }

    /** TC2 — is_test_data excluded from PM leads/jobs/dashboard. */
    public function test_2_pm_feeds_exclude_is_test_data(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser();

        $liveLead = Lead::create([
            'contact_name' => 'Live Lead '.$this->suffix(),
            'phone' => '6045551001',
            'email' => 'live-'.$this->suffix().'@example.com',
            'status' => 'new',
            'source' => 'website',
            'assigned_pm_id' => $pm->id,
            'is_test_data' => false,
        ]);
        $testLead = Lead::withTestData()->create([
            'contact_name' => 'Rotation Probe '.$this->suffix(),
            'phone' => '6045551002',
            'email' => 'probe-'.$this->suffix().'@example.com',
            'status' => 'new',
            'source' => 'internal_test',
            'assigned_pm_id' => $pm->id,
            'project_description' => 'ignore — verification',
            'is_test_data' => true,
        ]);

        $liveJob = Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '10 Live St',
            'service_category' => 'drywall_paint',
            'status' => 'in_progress',
            'is_test_data' => false,
        ]);
        $testJob = Job::withTestData()->create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '99 Probe Lane',
            'service_category' => 'drywall_paint',
            'status' => 'in_progress',
            'is_test_data' => true,
        ]);

        Sanctum::actingAs($pm);

        $leads = $this->getJson('/api/leads');
        $leads->assertOk();
        $leadIds = collect($leads->json('data') ?? [])->pluck('id');
        $this->assertTrue($leadIds->contains($liveLead->id));
        $this->assertFalse($leadIds->contains($testLead->id));

        $jobs = $this->getJson('/api/jobs');
        $jobs->assertOk();
        $jobIds = collect($jobs->json('data') ?? $jobs->json())->pluck('id');
        $this->assertTrue($jobIds->contains($liveJob->id));
        $this->assertFalse($jobIds->contains($testJob->id));

        $kpis = $this->getJson('/api/dashboard/pm/kpis');
        $kpis->assertOk();
        $this->assertNull($kpis->json('payout_status_note'));
        $recentJobIds = collect($kpis->json('my_jobs_list') ?? [])->pluck('id');
        $this->assertFalse($recentJobIds->contains($testJob->id));
        $recentLeadIds = collect($kpis->json('my_leads_list') ?? [])->pluck('id');
        $this->assertFalse($recentLeadIds->contains($testLead->id));
        $this->assertStringNotContainsString('mocked', strtolower((string) $kpis->getContent()));
    }

    /** TC3 — dashboard active_jobs card count equals /jobs?status=active total. */
    public function test_3_pm_dashboard_active_jobs_matches_list(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser();

        Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '1 Active Ave',
            'service_category' => 'drywall_paint',
            'status' => 'in_progress',
            'is_test_data' => false,
        ]);
        Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '2 Active Ave',
            'service_category' => 'drywall_paint',
            'status' => 'scheduled',
            'is_test_data' => false,
        ]);
        // Completed — not in active set
        Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '3 Done Ave',
            'service_category' => 'drywall_paint',
            'status' => 'completed',
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($pm);
        $kpis = $this->getJson('/api/dashboard/pm/kpis')->assertOk();
        $cardCount = (int) $kpis->json('active_jobs');

        $list = $this->getJson('/api/jobs?status=active')->assertOk();
        $listTotal = (int) ($list->json('total') ?? count($list->json('data') ?? []));

        $this->assertSame($cardCount, $listTotal);
        $this->assertGreaterThanOrEqual(2, $cardCount);
        $this->assertArrayHasKey('quotes_waiting_on_customer', $kpis->json('metric_definitions'));
        $this->assertSame(
            '/quotes?status=waiting_on_customer',
            $kpis->json('metric_definitions.quotes_waiting_on_customer.href')
        );
    }

    /** TC4 — job list status is lifecycle only (A-08 applied to PM). */
    public function test_4_pm_job_list_lifecycle_status_only(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser();
        $job = Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => 'Lifecycle Lane',
            'service_category' => 'drywall_paint',
            'status' => 'in_progress',
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($pm);
        $res = $this->getJson('/api/jobs?status=active')->assertOk();
        $row = collect($res->json('data') ?? [])->firstWhere('id', $job->id);
        $this->assertNotNull($row);
        $this->assertSame('in_progress', $row['status']);
        $this->assertContains($row['status'], JobLifecycleService::ACTIVE_JOB_STATUSES);
        // Payment aliases must not be the primary status field
        $this->assertArrayNotHasKey('payment_status_label_mixed', $row);
    }

    /** TC5 — empty leads response for PM with zero assigned (structure OK; copy is UI). */
    public function test_5_pm_leads_empty_when_none_assigned(): void
    {
        $pm = $this->makePm();
        Sanctum::actingAs($pm);

        $res = $this->getJson('/api/leads')->assertOk();
        $this->assertSame(0, (int) ($res->json('total') ?? count($res->json('data') ?? [])));
        $this->assertIsArray($res->json('data'));
    }

    /** TC6 — PM customers exclude quarantined / quality-flagged / test data. */
    public function test_6_pm_customers_exclude_quarantined_and_test(): void
    {
        $pm = $this->makePm();
        $goodUser = $this->makeCustomerUser(['name' => 'Good Cust '.$this->suffix()]);
        $flaggedUser = $this->makeCustomerUser(['name' => 'Unknown caller '.$this->suffix()]);
        $testUser = User::withTestData()->create([
            'name' => 'Test Cust '.$this->suffix(),
            'email' => 'test-cust-'.$this->suffix().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
            'phone' => '+16045559999',
            'is_test_data' => true,
        ]);

        $good = Customer::create([
            'user_id' => $goodUser->id,
            'name' => $goodUser->name,
            'phone' => $goodUser->phone,
            'email' => $goodUser->email,
            'is_test_data' => false,
            'data_quality_flags' => null,
        ]);
        $flagged = Customer::create([
            'user_id' => $flaggedUser->id,
            'name' => $flaggedUser->name,
            'phone' => 'not-a-phone',
            'email' => $flaggedUser->email,
            'is_test_data' => false,
            'data_quality_flags' => ['invalid_phone' => true, 'invalid_name' => true],
        ]);
        $testCust = Customer::withTestData()->create([
            'user_id' => $testUser->id,
            'name' => $testUser->name,
            'phone' => $testUser->phone,
            'email' => $testUser->email,
            'is_test_data' => true,
            'data_quality_flags' => null,
        ]);

        // Tie all to PM via jobs so scopeCustomersForPm would otherwise include them
        foreach ([$goodUser, $flaggedUser, $testUser] as $u) {
            $maker = ((bool) $u->is_test_data) ? Job::withTestData() : Job::query();
            $maker->create([
                'customer_id' => $u->id,
                'pm_id' => $pm->id,
                'address' => 'Cust Scope St',
                'service_category' => 'drywall_paint',
                'status' => 'scheduled',
                'is_test_data' => (bool) $u->is_test_data,
            ]);
        }

        Sanctum::actingAs($pm);
        $res = $this->getJson('/api/customers')->assertOk();
        $ids = collect($res->json('data') ?? [])->pluck('id');

        $this->assertTrue($ids->contains($good->id), 'reviewed customer should appear');
        $this->assertFalse($ids->contains($flagged->id), 'quality-flagged customer must be excluded');
        $this->assertFalse($ids->contains($testCust->id), 'is_test_data customer must be excluded');
    }

    /** TC7 — PM schedule includes agenda-ready event fields. */
    public function test_7_pm_schedule_agenda_event_details(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser(['name' => 'Agenda Cust']);
        $month = now()->format('Y-m');

        Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => '55 Agenda Rd',
            'service_category' => 'drywall_paint',
            'status' => 'scheduled',
            'scheduled_start_date' => now()->toDateString(),
            'scheduled_start_time' => '10:00',
            'job_title' => 'Agenda Job',
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($pm);
        $res = $this->getJson('/api/schedule?month='.$month.'&view=agenda')->assertOk();
        $events = collect($res->json('all') ?? $res->json('events') ?? []);
        $this->assertNotEmpty($events);
        $jobEvent = $events->firstWhere('type', 'job');
        $this->assertNotNull($jobEvent);
        $this->assertArrayHasKey('customer_name', $jobEvent);
        $this->assertArrayHasKey('address', $jobEvent);
        $this->assertArrayHasKey('status', $jobEvent);
        $this->assertArrayHasKey('directions_url', $jobEvent);
        $this->assertSame('agenda', $res->json('view'));
    }

    /** TC8 — PM invoices include context; mark-paid / void-style routes stay owner-only. */
    public function test_8_pm_invoices_context_and_no_void_refund(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser();
        $job = Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => 'Invoice Context St',
            'service_category' => 'drywall_paint',
            'status' => 'completed',
            'is_test_data' => false,
        ]);
        $invoice = Invoice::create([
            'job_id' => $job->id,
            'customer_id' => $cust->id,
            'invoice_number' => 'INV-PM-'.$this->suffix(),
            'amount' => 100,
            'balance' => 100,
            'status' => 'invoice_sent',
            'due_date' => now()->addDays(7)->toDateString(),
            'sent_at' => now(),
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($pm);
        $list = $this->getJson('/api/invoices')->assertOk();
        $row = collect($list->json('data') ?? [])->firstWhere('id', $invoice->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('payment_state', $row);
        $this->assertArrayHasKey('issued_at', $row);
        $this->assertFalse((bool) ($row['can_void'] ?? false));
        $this->assertFalse((bool) ($row['can_refund'] ?? false));

        $link = $this->postJson('/api/invoices/'.$invoice->id.'/payment-link')->assertOk();
        $this->assertNotEmpty($link->json('payment_link'));

        $this->postJson('/api/invoices/'.$invoice->id.'/record-contact', [
            'channel' => 'call',
            'note' => 'Left voicemail',
        ])->assertOk();

        // Owner-only mark-paid must 403 for PM
        $this->postJson('/api/invoices/'.$invoice->id.'/mark-paid', [
            'amount' => 100,
            'payment_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    /** TC9 smoke — waiting_on_customer quote filter expands correctly for PM card link. */
    public function test_9_quotes_waiting_on_customer_filter_matches_kpi(): void
    {
        $pm = $this->makePm();
        $cust = $this->makeCustomerUser();
        $job = Job::create([
            'customer_id' => $cust->id,
            'pm_id' => $pm->id,
            'address' => 'Quote Wait St',
            'service_category' => 'drywall_paint',
            'status' => 'quote_sent',
            'is_test_data' => false,
        ]);
        Quote::createWithUniqueQuoteNumber([
            'job_id' => $job->id,
            'customer_id' => $cust->id,
            'status' => 'sent',
            'customer_total' => 500,
            'subtotal' => 476.19,
            'gst' => 23.81,
            'is_test_data' => false,
        ]);

        Sanctum::actingAs($pm);
        $kpis = $this->getJson('/api/dashboard/pm/kpis')->assertOk();
        $card = (int) $kpis->json('awaiting_approval');

        $list = $this->getJson('/api/quotes?status=waiting_on_customer')->assertOk();
        $payload = $list->json();
        if (isset($payload['meta']['total'])) {
            $total = (int) $payload['meta']['total'];
        } elseif (isset($payload['total'])) {
            $total = (int) $payload['total'];
        } elseif (isset($payload['data']) && is_array($payload['data'])) {
            $total = (int) ($payload['meta']['total'] ?? count($payload['data']));
        } elseif (is_array($payload)) {
            $total = count($payload);
        } else {
            $total = 0;
        }
        $this->assertSame($card, $total);
        $this->assertGreaterThanOrEqual(1, $card);
    }
}
