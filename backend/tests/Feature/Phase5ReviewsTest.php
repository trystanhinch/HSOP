<?php

namespace Tests\Feature;

use App\Models\AiActionLog;
use App\Models\AiOpsReport;
use App\Models\CompanySource;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\InvoiceService;
use App\Services\PayoutEligibilityService;
use App\Services\Reporting\AiOpsReportService;
use App\Services\Reporting\SourcePerformanceService;
use App\Services\Reviews\ReviewRequestService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase5ReviewsTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('payment.provider', 'mock');
        $app['config']->set('ai.provider', 'mock');

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
        Setting::set('invoice_number_format', 'INV-{XXXX}');
        Setting::set('invoice_number_next', (string) random_int(200, 900));
        Setting::set('payout_schedule_business_days', '2');
        Setting::set('ai_kill_switch', 'false');
    }

    private function makePaidJobContext(array $sourceOverrides = []): array
    {
        $owner = User::where('role', 'owner')->first() ?: User::factory()->create(['role' => 'owner']);
        $pm = User::create([
            'name' => 'Phase5 PM', 'email' => 'phase5-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active', 'phone' => '6045550101',
        ]);
        $contractor = User::create([
            'name' => 'Phase5 Contractor', 'email' => 'phase5-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Phase5 Customer', 'email' => 'phase5-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active', 'phone' => '6045550199',
        ]);

        $source = CompanySource::create(array_merge([
            'company_name' => 'Phase5 Test Co '.uniqid(),
            'google_review_url' => null,
            'status' => 'active',
        ], $sourceOverrides));

        $token = Str::random(64);
        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '200 Phase5 Review Ave',
            'service_category' => 'drywall_paint',
            'status' => 'converted',
            'company_source_id' => $source->id,
            'company_listing' => $source->company_name,
            'assigned_pm_id' => $pm->id,
            'customer_portal_token' => $token,
            'customer_id' => $customer->id,
        ]);

        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '200 Phase5 Review Ave',
            'service_category' => 'drywall_paint',
            'status' => 'payment_pending',
            'scope_of_work' => 'Phase5 review flow',
            'contractor_submitted_price' => 800,
            'split_contractor_pct' => 80,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
            'customer_accepted_completion_at' => now(),
        ]);

        Quote::create([
            'job_id' => $job->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'scope_of_work' => 'Phase5 review flow',
            'contractor_base_price' => 800,
            'customer_price_before_gst' => 1000,
            'gst_rate' => 5,
            'gst' => 50,
            'customer_total' => 1050,
            'contractor_pct' => 80,
            'pm_pct' => 10,
            'company_pct' => 10,
            'pm_amount' => 100,
            'company_amount' => 100,
            'sent_at' => now(),
        ]);

        $invoice = app(InvoiceService::class)->createFromJob($job->fresh(['quote', 'lead.companySource']));

        return compact('owner', 'pm', 'contractor', 'customer', 'source', 'lead', 'job', 'invoice', 'token');
    }

    public function test_five_star_path_sets_google_shown_without_broken_url(): void
    {
        $ctx = $this->makePaidJobContext();
        $job = $ctx['job'];
        $token = $ctx['token'];

        // Mark invoice paid via eligibility path
        $ctx['invoice']->update([
            'status' => 'paid',
            'amount_paid' => $ctx['invoice']->amount,
            'balance' => 0,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'mock',
        ]);

        $result = app(PayoutEligibilityService::class)->evaluateForJob($job->fresh([
            'invoice', 'quote', 'revisionRequests', 'contractor', 'pm', 'lead', 'customer',
        ]));
        $this->assertTrue($result['eligible']);

        $job->refresh();
        $this->assertNotNull($job->review_request_sent_at);
        $this->assertTrue(
            AiActionLog::where('trigger_event', 'review_request_sent')->where('action_taken', 'review_request_notification')->exists()
        );

        $show = $this->getJson("/api/portal/{$token}/review");
        $show->assertOk()->assertJsonPath('can_submit', true);

        $submit = $this->postJson("/api/portal/{$token}/review", [
            'star_rating' => 5,
            'comment' => 'Excellent work',
        ]);
        $submit->assertCreated();
        $submit->assertJsonPath('review.already_submitted', true);
        $submit->assertJsonPath('review.show_google_button', false);
        $submit->assertJsonPath('review.google_review_url', null);

        $feedback = ReviewFeedback::where('job_id', $job->id)->first();
        $this->assertNotNull($feedback);
        $this->assertSame(5, (int) $feedback->star_rating);
        $this->assertTrue($feedback->google_review_shown);
        $this->assertNull($feedback->follow_up_status);
        $this->assertSame(0, NextAction::where('subject_type', $feedback->getMorphClass())->where('subject_id', $feedback->id)->count());
    }

    public function test_under_five_star_creates_pm_follow_up_and_dashboard_item(): void
    {
        $ctx = $this->makePaidJobContext();
        $job = $ctx['job'];
        $token = $ctx['token'];
        $pm = $ctx['pm'];

        $job->update(['review_request_sent_at' => now()]);

        $submit = $this->postJson("/api/portal/{$token}/review", [
            'star_rating' => 3,
            'issue_category' => 'quality',
            'comment' => 'Dust left behind',
        ]);
        $submit->assertCreated();

        $feedback = ReviewFeedback::where('job_id', $job->id)->first();
        $this->assertSame(3, (int) $feedback->star_rating);
        $this->assertSame('quality', $feedback->issue_category);
        $this->assertSame('pm_notified', $feedback->follow_up_status);
        $this->assertFalse($feedback->google_review_shown);

        $this->assertTrue(
            NextAction::where('subject_id', $feedback->id)
                ->where('responsible_role', 'pm')
                ->where('responsible_user_id', $pm->id)
                ->where('status', 'pending')
                ->exists()
        );
        $this->assertTrue(
            AiActionLog::where('trigger_event', 'review_feedback_submitted')->where('decision', 'needs_follow_up')->exists()
        );

        $this->actingAs($pm, 'sanctum');
        $dash = $this->getJson('/api/dashboard/pm/kpis');
        $dash->assertOk();
        $ids = collect($dash->json('customer_feedback_follow_up'))->pluck('id')->all();
        $this->assertContains($feedback->id, $ids);
    }

    public function test_source_performance_and_ops_report_and_role_visibility(): void
    {
        $ctx = $this->makePaidJobContext();
        $job = $ctx['job'];
        $job->update(['review_request_sent_at' => now()]);
        app(ReviewRequestService::class)->submit($job, [
            'star_rating' => 4,
            'issue_category' => 'communication',
            'comment' => 'Slow replies',
        ]);

        $summary = app(SourcePerformanceService::class)->summary();
        $row = collect($summary['sources'])->firstWhere('company_source_id', $ctx['source']->id);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, $row['leads']);
        $this->assertSame(4.0, (float) $row['avg_review_rating']);

        // Force fallback summary (no OpenAI in test)
        config(['ai.provider' => 'mock']);
        $report = app(AiOpsReportService::class)->generate('daily');
        $this->assertInstanceOf(AiOpsReport::class, $report);
        $this->assertNotEmpty($report->summary_text);
        $this->assertIsArray($report->raw_metrics);
        $this->assertArrayHasKey('reviews_submitted', $report->raw_metrics);

        $owner = $ctx['owner'];
        $pm = $ctx['pm'];
        $contractor = $ctx['contractor'];

        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/accounting/source-performance')->assertOk();
        $this->getJson('/api/ops-reports')->assertOk();
        $this->postJson('/api/ops-reports/generate', ['period' => 'daily'])->assertCreated();
        $this->getJson('/api/reviews?needs_follow_up=true')->assertOk();

        $this->actingAs($pm, 'sanctum');
        $this->getJson('/api/reviews')->assertOk();
        $this->getJson('/api/accounting/source-performance')->assertForbidden();
        $this->getJson('/api/ops-reports')->assertStatus(403);

        $this->actingAs($contractor, 'sanctum');
        $this->getJson('/api/reviews')->assertStatus(403);
        $this->getJson('/api/accounting/dashboard')->assertStatus(403);
    }

    public function test_five_star_shows_google_button_when_url_present(): void
    {
        $ctx = $this->makePaidJobContext([
            'google_review_url' => 'https://g.page/r/phase5-test-review',
        ]);
        $job = $ctx['job'];
        $token = $ctx['token'];
        $job->update(['review_request_sent_at' => now()]);

        $submit = $this->postJson("/api/portal/{$token}/review", ['star_rating' => 5]);
        $submit->assertCreated();
        $submit->assertJsonPath('review.show_google_button', true);
        $submit->assertJsonPath('review.google_review_url', 'https://g.page/r/phase5-test-review');
        $this->assertTrue(ReviewFeedback::where('job_id', $job->id)->value('google_review_shown'));
    }
}
