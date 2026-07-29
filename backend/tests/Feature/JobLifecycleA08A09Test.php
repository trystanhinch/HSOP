<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\JobUpdate;
use App\Models\Lead;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Workflow\JobLifecycleService;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A-08 / A-09 — Job lifecycle vs activity + dashboard metric consistency.
 */
class JobLifecycleA08A09Test extends TestCase
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
        $this->seed(Milestone4Seeder::class);
    }

    private function owner(): User
    {
        return User::where('role', 'owner')->firstOrFail();
    }

    private function contractor(): User
    {
        return User::where('role', 'contractor')->firstOrFail();
    }

    private function makeJob(array $attrs = []): Job
    {
        $customer = User::where('role', 'customer')->first()
            ?: User::factory()->create(['role' => 'customer']);
        $contractor = $this->contractor();
        $pm = User::where('role', 'pm')->first();

        return Job::create(array_merge([
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm?->id,
            'address' => '100 A08 Test Lane',
            'service_category' => 'drywall_paint',
            'status' => 'scheduled',
            'is_test_data' => false,
        ], $attrs));
    }

    public function test_progress_update_does_not_set_activity_status(): void
    {
        $job = $this->makeJob(['status' => 'in_progress']);
        $contractor = $this->contractor();

        $this->actingAs($contractor, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/updates", [
                'update_text' => 'Day 1 complete — hung drywall.',
                'visibility' => 'customer_visible',
            ])
            ->assertCreated();

        $job->refresh();
        $this->assertSame('in_progress', $job->status);
        $this->assertDatabaseHas('job_updates', [
            'job_id' => $job->id,
            'update_text' => 'Day 1 complete — hung drywall.',
        ]);
        $this->assertFalse(
            in_array($job->status, JobLifecycleService::ACTIVITY_STATUSES, true),
            'Activity statuses must never be written to jobs.status'
        );
    }

    public function test_progress_update_advances_scheduled_to_in_progress_only(): void
    {
        $job = $this->makeJob(['status' => 'scheduled']);
        $contractor = $this->contractor();

        $this->actingAs($contractor, 'sanctum')
            ->postJson("/api/jobs/{$job->id}/updates", [
                'update_text' => 'Arrived on site.',
                'visibility' => 'customer_visible',
            ])
            ->assertCreated();

        $this->assertSame('in_progress', $job->fresh()->status);
    }

    public function test_invalid_transition_completed_to_scheduled_is_blocked_and_logged(): void
    {
        Log::spy();

        $job = $this->makeJob(['status' => 'completed']);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['status' => 'scheduled'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid job status transition: completed → scheduled.']);

        $this->assertSame('completed', $job->fresh()->status);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                return str_contains((string) $message, 'A-08 blocked invalid job status transition')
                    && ($context['from'] ?? null) === 'completed'
                    && ($context['to'] ?? null) === 'scheduled';
            })
            ->atLeast()
            ->once();
    }

    public function test_activity_status_write_is_rejected(): void
    {
        $job = $this->makeJob(['status' => 'in_progress']);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/jobs/{$job->id}", ['status' => 'progress_updated'])
            ->assertStatus(422);

        $this->assertSame('in_progress', $job->fresh()->status);
    }

    public function test_needs_review_dashboard_pipeline_and_nav_badge_match(): void
    {
        Lead::productionOnly()->where('needs_manual_review', true)->update(['needs_manual_review' => false]);

        Lead::create([
            'contact_name' => 'Review Match A',
            'email' => 'review-a-'.uniqid().'@test.local',
            'phone' => '6045550001',
            'address' => '1 Review St',
            'service_category' => 'drywall',
            'status' => 'new',
            'needs_manual_review' => true,
            'is_test_data' => false,
        ]);
        Lead::create([
            'contact_name' => 'Review Match B',
            'email' => 'review-b-'.uniqid().'@test.local',
            'phone' => '6045550002',
            'address' => '2 Review St',
            'service_category' => 'drywall',
            'status' => 'contacted',
            'needs_manual_review' => true,
            'is_test_data' => false,
        ]);
        // Test-data lead must NOT count
        Lead::create([
            'contact_name' => 'Review Test Data',
            'email' => 'review-t-'.uniqid().'@test.local',
            'phone' => '6045550003',
            'address' => '3 Review St',
            'service_category' => 'drywall',
            'status' => 'new',
            'needs_manual_review' => true,
            'is_test_data' => true,
        ]);

        $metrics = app(DashboardMetricsService::class);
        $expected = $metrics->countLeadsNeedingReview();
        $this->assertSame(2, $expected);

        $owner = $this->owner();
        $kpis = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/dashboard/admin/kpis')
            ->assertOk()
            ->json();

        $badge = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/leads/review-count')
            ->assertOk()
            ->json();

        $this->assertSame($expected, $kpis['leads_needing_review']);
        $this->assertSame($expected, $badge['count']);
        $this->assertSame($kpis['new_leads'], $kpis['pipeline']['new']);
    }

    public function test_dashboard_card_href_filters_match_counts(): void
    {
        $owner = $this->owner();
        $kpis = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/dashboard/admin/kpis')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('metric_definitions', $kpis);
        $this->assertArrayHasKey('refreshed_at', $kpis);
        $this->assertArrayHasKey('filters', $kpis);

        $inProgressCount = $kpis['jobs_in_progress'];
        $list = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/jobs?status=in_progress')
            ->assertOk()
            ->json();

        $total = $list['meta']['total'] ?? count($list['data'] ?? $list);
        $this->assertSame($inProgressCount, (int) $total);

        $awaiting = $kpis['jobs_awaiting_price'];
        $priceList = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/jobs?contractor_price_status=pending')
            ->assertOk()
            ->json();
        $priceTotal = $priceList['meta']['total'] ?? count($priceList['data'] ?? $priceList);
        $this->assertSame($awaiting, (int) $priceTotal);
    }
}
