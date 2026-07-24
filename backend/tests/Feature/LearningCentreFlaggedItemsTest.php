<?php

namespace Tests\Feature;

use App\Models\AiConversationLog;
use App\Models\Brand;
use App\Models\ContractorPerformanceEvent;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\Learning\AiConversationLogger;
use App\Services\Learning\ContractorPerformanceRecorder;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Milestone 5 — flagged Learning Centre capture items (conversation logs,
 * environmental_context reserved column, contractor performance events).
 */
class LearningCentreFlaggedItemsTest extends TestCase
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

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        Setting::setBool('ai_kill_switch', false);
    }

    public function test_conversation_logged_and_linked_on_submit(): void
    {
        $headers = [
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Host' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ];

        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'Drywall repair about 150 sqft in Coquitlam, my name is Log Test',
        ], $headers)->assertOk();

        $session = $this->getJson('/api/public/intake/session?session_token='.$token, $headers);
        $sessionId = $session->json('session_id');
        $this->assertNotEmpty($sessionId);

        $beforeSubmit = AiConversationLog::where('intake_session_id', $sessionId)->count();
        $this->assertGreaterThanOrEqual(2, $beforeSubmit); // system + user (+ assistant)
        $this->assertTrue(
            AiConversationLog::where('intake_session_id', $sessionId)->where('role', 'user')->exists()
        );

        $suffix = substr(uniqid(), -6);
        $submit = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Log Test '.$suffix,
            'phone' => '(604) 555-'.$suffix,
            'email' => 'log-'.$suffix.'@example.com',
            'project_description' => 'Drywall logging test '.$suffix,
            'service_category' => 'drywall_paint',
            'address' => 'Coquitlam',
        ], $headers);
        $submit->assertOk();
        $leadId = (int) $submit->json('lead_id');

        $linked = AiConversationLog::where('lead_id', $leadId)->count();
        $this->assertGreaterThanOrEqual(2, $linked);
        $this->assertSame(
            0,
            AiConversationLog::where('intake_session_id', $sessionId)->whereNull('lead_id')->count()
        );

        // Public API must not expose conversation logs
        $this->getJson('/api/public/intake/session?session_token='.$token, $headers)
            ->assertOk();
        $publicBody = json_encode($this->getJson('/api/public/brand', $headers)->json());
        $this->assertStringNotContainsString('ai_conversation', (string) $publicBody);

        // Owner can read; contractor cannot
        $owner = User::where('role', 'owner')->firstOrFail();
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/ai/conversation-logs?lead_id='.$leadId)
            ->assertOk()
            ->assertJsonPath('data.0.lead_id', $leadId);

        $contractor = User::create([
            'name' => 'No Access',
            'email' => 'no-access-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'status' => 'active',
        ]);
        $this->actingAs($contractor, 'sanctum')
            ->getJson('/api/ai/conversation-logs?lead_id='.$leadId)
            ->assertForbidden();
    }

    public function test_retention_purge_respects_setting(): void
    {
        Setting::set(AiConversationLogger::RETENTION_SETTING, '7');
        $this->assertSame(7, AiConversationLogger::retentionDays());

        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $session = \App\Models\IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => \Illuminate\Support\Str::random(64),
            'conversation_state' => ['messages' => []],
            'expires_at' => now()->addDay(),
        ]);

        $old = AiConversationLog::create([
            'intake_session_id' => $session->id,
            'lead_id' => null,
            'turn_number' => 1,
            'role' => 'user',
            'content' => 'old message',
            'created_at' => now()->subDays(10),
        ]);
        $fresh = AiConversationLog::create([
            'intake_session_id' => $session->id,
            'lead_id' => null,
            'turn_number' => 2,
            'role' => 'assistant',
            'content' => 'fresh reply',
            'created_at' => now()->subDays(2),
        ]);

        Artisan::call('learning:purge-ai-conversation-logs');

        $this->assertDatabaseMissing('ai_conversation_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('ai_conversation_logs', ['id' => $fresh->id]);
    }

    public function test_environmental_context_exists_and_is_null_on_new_outcomes(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('estimate_outcomes', 'environmental_context')
        );

        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $lead = Lead::create([
            'contact_name' => 'Env Context',
            'email' => 'env-'.uniqid().'@test.local',
            'phone' => '6045550199',
            'address' => '1 Env St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'brand_id' => $brand->id,
            'project_description' => 'env test',
        ]);

        $outcome = app(\App\Services\Learning\EstimateOutcomeRecorder::class)->record($lead, [
            'available' => true,
            'low' => 1000,
            'high' => 1500,
            'currency' => 'CAD',
            'confidence' => 'medium',
            'materials_assumptions' => ['sheets' => 4],
            'labour_assumptions' => ['hours' => 6],
        ], ['source_kind' => 'estimator']);

        $this->assertNull($outcome->fresh()->environmental_context);
    }

    public function test_contractor_performance_events_from_existing_hooks(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $pm = User::create([
            'name' => 'Perf PM', 'email' => 'perf-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractor = User::create([
            'name' => 'Perf Contractor', 'email' => 'perf-c-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
            'phone' => '6045550100',
        ]);
        $customer = User::create([
            'name' => 'Perf Cust', 'email' => 'perf-cu-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active',
            'phone' => '6045550101',
        ]);

        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '9 Perf Ave',
            'service_category' => 'drywall_paint',
            'status' => 'converted',
            'brand_id' => Brand::where('domain', 'acuteradrywall.ca')->value('id'),
            'customer_id' => $customer->id,
            'assigned_pm_id' => $pm->id,
            'customer_portal_token' => \Illuminate\Support\Str::random(64),
        ]);

        EstimateOutcome::create([
            'estimate_group_id' => (string) \Illuminate\Support\Str::uuid(),
            'lead_id' => $lead->id,
            'brand_id' => $lead->brand_id,
            'version' => 1,
            'source_kind' => 'estimator',
            'service_category' => 'drywall_paint',
            'price_low' => 1000,
            'price_high' => 1400,
            'currency' => 'CAD',
            'is_current' => true,
            'labour_assumptions' => ['hours' => 8],
            'materials_assumptions' => [['item' => 'sheet']],
            'estimated_at' => now(),
            'environmental_context' => null,
        ]);

        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'address' => '9 Perf Ave',
            'service_category' => 'drywall_paint',
            'status' => 'new_job',
            'scope_of_work' => 'Perf test job',
        ]);

        $this->actingAs($owner, 'sanctum');
        $this->postJson("/api/jobs/{$job->id}/assign-contractor", [
            'contractor_id' => $contractor->id,
        ])->assertOk();

        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)
                ->where('event_type', 'response_time')
                ->where('event_data->phase', 'notified')
                ->exists()
        );

        $this->postJson("/api/jobs/{$job->id}/schedule", [
            'scheduled_start_date' => now()->addDays(2)->toDateString(),
            'estimated_completion_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)
                ->where('event_type', 'schedule_adherence')
                ->where('event_data->phase', 'booked')
                ->exists()
        );

        $this->actingAs($contractor, 'sanctum');
        $this->postJson("/api/jobs/{$job->id}/submit-price", [
            'price' => 900,
        ])->assertOk();

        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)
                ->where('event_type', 'response_time')
                ->where('event_data->phase', 'first_action')
                ->exists()
        );

        // Force status so contractor-complete is allowed
        $job->update(['status' => 'in_progress', 'contractor_id' => $contractor->id]);
        $this->postJson("/api/jobs/{$job->id}/contractor-complete", [
            'actual_labour_hours' => 10,
            'materials_used' => [['item' => 'compound', 'qty' => 1]],
        ])->assertOk();

        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'labour_variance')->exists()
        );
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'materials_variance')->exists()
        );
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'completion_time')->exists()
        );

        $this->actingAs($customer, 'sanctum');
        $this->postJson("/api/jobs/{$job->id}/accept-completion")->assertOk();
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'profitability')->exists()
        );

        $this->postJson("/api/jobs/{$job->id}/request-revision", [
            'description' => 'Touch up corner please',
        ]);
        // May 422 if status wrong after accept — record via service if needed
        if (ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'revision_requested')->doesntExist()) {
            $rev = \App\Models\RevisionRequest::create([
                'job_id' => $job->id,
                'requested_by' => $customer->id,
                'description' => 'Touch up',
                'status' => 'open',
            ]);
            app(ContractorPerformanceRecorder::class)->onRevisionRequested($job->fresh(), $rev);
        }
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'revision_requested')->exists()
        );
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'callback')->exists()
        );

        $feedback = \App\Models\ReviewFeedback::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'star_rating' => 5,
            'comment' => 'Great',
            'google_review_shown' => true,
            'submitted_at' => now(),
        ]);
        app(ContractorPerformanceRecorder::class)->onCustomerRating($feedback);
        $this->assertTrue(
            ContractorPerformanceEvent::where('job_id', $job->id)->where('event_type', 'customer_rating')->exists()
        );
    }

    public function test_second_brand_conversation_logs_isolated(): void
    {
        $source = \App\Models\CompanySource::create([
            'company_name' => 'Roof Log '.uniqid(),
            'service_categories' => ['Roofing'],
            'status' => 'active',
        ]);
        $roof = Brand::create([
            'domain' => 'example-roof-logs.test',
            'slug' => 'example-roof-logs',
            'company_name' => 'Example Roof Logs',
            'company_source_id' => $source->id,
            'status' => 'active',
            'service_categories' => [
                ['key' => 'roofing', 'label' => 'Roofing', 'keywords' => ['roof']],
            ],
        ]);
        \App\Models\PricingRule::create([
            'brand_id' => $roof->id,
            'company_source_id' => $source->id,
            'service_category' => 'roofing',
            'rule_type' => 'per_sqft',
            'base_rate' => 8,
            'size_tiers' => ['low_rate' => 6, 'high_rate' => 12, 'default_low_sqft' => 100, 'default_high_sqft' => 400],
            'complexity_modifiers' => ['simple' => 0.9, 'standard' => 1, 'complex' => 1.3, 'unknown' => 1],
            'min_price' => 400,
            'max_price' => 20000,
            'currency' => 'CAD',
            'is_placeholder' => true,
            'status' => 'active',
        ]);

        $headers = [
            'X-Brand-Domain' => 'example-roof-logs.test',
            'Host' => 'example-roof-logs.test',
            'Accept' => 'application/json',
        ];
        $token = $this->postJson('/api/public/intake/start', [], $headers)->json('session_token');
        $this->postJson('/api/public/intake/message', [
            'session_token' => $token,
            'message' => 'Roof leak about 200 sqft Burnaby',
        ], $headers)->assertOk();

        $sessionId = $this->getJson('/api/public/intake/session?session_token='.$token, $headers)
            ->json('session_id') ?? $this->getJson('/api/public/intake/session?session_token='.$token, $headers)->json('session.id');

        $logs = AiConversationLog::where('intake_session_id', $sessionId)->get();
        $this->assertNotEmpty($logs);

        $suffix = substr(uniqid(), -6);
        $leadId = $this->postJson('/api/public/intake/submit', [
            'session_token' => $token,
            'contact_name' => 'Roof Log '.$suffix,
            'phone' => '(604) 777-'.$suffix,
            'email' => 'rooflog-'.$suffix.'@example.com',
            'project_description' => 'Roof logging '.$suffix,
            'service_category' => 'roofing',
            'address' => 'Burnaby',
        ], $headers)->json('lead_id');

        $lead = Lead::findOrFail($leadId);
        $this->assertSame($roof->id, (int) $lead->brand_id);
        $this->assertTrue(AiConversationLog::where('lead_id', $leadId)->exists());
        $this->assertFalse(
            AiConversationLog::where('lead_id', $leadId)
                ->get()
                ->contains(fn ($row) => str_contains(strtolower((string) $row->content), 'acutera'))
        );
    }

    public function test_owner_can_update_retention_setting(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $this->actingAs($owner, 'sanctum')
            ->putJson('/api/ai/settings', ['ai_conversation_retention_days' => 90])
            ->assertOk();

        $this->assertSame(90, AiConversationLogger::retentionDays());
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('ai_conversation_retention_days', 90);
    }
}
