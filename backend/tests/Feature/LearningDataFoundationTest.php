<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\EstimateOutcome;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\PricingOverrideLog;
use App\Models\PricingRule;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\RevisionRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Learning\EstimateOutcomeRecorder;
use App\Services\Learning\JobEstimateSnapshotService;
use App\Services\Pricing\PricingRangeEstimator;
use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Milestone 5 — Learning Centre data foundation (capture/assembly only).
 */
class LearningDataFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('ai.conversational_provider', 'mock');

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
    }

    public function test_estimator_stores_materials_and_labour_assumptions(): void
    {
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $estimate = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 200,
            'project_description' => 'Bedroom drywall',
            'complexity' => 'standard',
        ]);

        $this->assertArrayHasKey('materials_assumptions', $estimate);
        $this->assertArrayHasKey('labour_assumptions', $estimate);
        $this->assertNotEmpty($estimate['materials_assumptions']);
        $this->assertArrayHasKey('estimated_hours', $estimate['labour_assumptions']);
    }

    public function test_snapshot_assembles_full_picture_without_duplicating_sources(): void
    {
        $owner = User::where('role', 'owner')->first() ?: User::factory()->create(['role' => 'owner']);
        $pm = User::create([
            'name' => 'Learn PM', 'email' => 'learn-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractor = User::create([
            'name' => 'Learn Con', 'email' => 'learn-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Learn Cust', 'email' => 'learn-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active', 'phone' => '6045550111',
        ]);

        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $source = CompanySource::create([
            'company_name' => 'Learn Co '.uniqid(),
            'status' => 'active',
        ]);

        $estimate = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 180,
            'project_description' => 'Ceiling patch',
            'complexity' => 'standard',
        ]);

        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => '10 Learning Ave',
            'service_category' => 'drywall_paint',
            'status' => 'converted',
            'brand_id' => $brand->id,
            'company_source_id' => $source->id,
            'project_description' => 'Ceiling patch ~180 sqft',
            'parse_metadata' => ['collected_fields' => ['size_sqft' => 180]],
            'customer_portal_token' => Str::random(64),
            'customer_id' => $customer->id,
            'assigned_pm_id' => $pm->id,
        ]);

        app(EstimateOutcomeRecorder::class)->record($lead, $estimate, [
            'source_kind' => 'estimator',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
        ]);
        $lead->refresh();

        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '10 Learning Ave',
            'service_category' => 'drywall_paint',
            'status' => 'completed',
            'scope_of_work' => 'Repair ceiling drywall and paint',
            'actual_labour_hours' => 7.5,
            'materials_used' => [
                ['item' => 'Drywall sheet 1/2"', 'qty' => 4, 'unit' => 'sheets'],
                ['item' => 'Joint compound', 'qty' => 1, 'unit' => 'pail'],
            ],
            'completed_at' => now(),
            'customer_accepted_completion_at' => now(),
            'contractor_submitted_price' => 900,
            'split_contractor_pct' => 80,
            'split_pm_pct' => 10,
            'split_company_pct' => 10,
        ]);

        $quote = Quote::create([
            'job_id' => $job->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'status' => 'approved',
            'scope_of_work' => $job->scope_of_work,
            'contractor_base_price' => 900,
            'customer_price_before_gst' => 1125,
            'gst_rate' => 5,
            'gst' => 56.25,
            'customer_total' => 1181.25,
            'contractor_pct' => 80,
            'pm_pct' => 10,
            'company_pct' => 10,
            'pm_amount' => 112.5,
            'company_amount' => 112.5,
            'sent_at' => now(),
        ]);

        $invoice = Invoice::create([
            'job_id' => $job->id,
            'quote_id' => $quote->id,
            'customer_id' => $customer->id,
            'company_source_id' => $source->id,
            'invoice_number' => 'INV-LEARN-'.uniqid(),
            'scope_of_work' => $job->scope_of_work,
            'subtotal' => 1125,
            'gst_rate' => 5,
            'gst' => 56.25,
            'amount' => 1181.25,
            'balance' => 0,
            'amount_paid' => 1181.25,
            'status' => 'paid',
            'payment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
        ]);

        RevisionRequest::create([
            'job_id' => $job->id,
            'requested_by' => $customer->id,
            'description' => 'Touch up corner',
            'status' => 'resolved',
        ]);

        ReviewFeedback::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'star_rating' => 5,
            'comment' => 'Great finish',
            'google_review_shown' => true,
            'submitted_at' => now(),
        ]);

        PricingOverrideLog::create([
            'actor_id' => $owner->id,
            'subject_type' => 'lead_estimate',
            'subject_id' => $lead->id,
            'brand_id' => $brand->id,
            'lead_id' => $lead->id,
            'job_id' => $job->id,
            'override_kind' => 'estimate_manual_adjust',
            'before_json' => ['price_estimate_low' => 1000, 'price_estimate_high' => 1400],
            'after_json' => ['price_estimate_low' => $estimate['low'], 'price_estimate_high' => $estimate['high']],
            'reason' => 'PM adjusted after site photos',
        ]);

        $snapshot = app(JobEstimateSnapshotService::class)->forJob($job->fresh());

        $this->assertFalse($snapshot['ai_learning']);
        $this->assertSame('learning_centre_foundation_v1', $snapshot['purpose']);

        // Referenced (not duplicated into a mega-table)
        $this->assertSame($lead->id, $snapshot['intake']['lead_id']);
        $this->assertSame('Repair ceiling drywall and paint', $snapshot['intake']['scope_of_work']);
        $this->assertNotNull($snapshot['estimate']['materials_assumptions']);
        $this->assertNotNull($snapshot['estimate']['labour_assumptions']);
        $this->assertSame($quote->id, $snapshot['quote_final_price']['quote_id']);
        $this->assertEquals(1181.25, (float) $snapshot['quote_final_price']['customer_total']);
        $this->assertSame($contractor->id, $snapshot['contractor']['contractor_id']);
        $this->assertSame($invoice->id, $snapshot['actuals_costs_profit']['invoice']['invoice_id']);
        $this->assertSame('paid', $snapshot['actuals_costs_profit']['invoice']['status']);
        $this->assertSame(5, (int) $snapshot['customer_feedback']['rating']);
        $this->assertNotEmpty($snapshot['revisions']);

        // NEW physical fields
        $this->assertSame(7.5, $snapshot['actual_labour_hours']);
        $this->assertCount(2, $snapshot['materials_used_actual']);
        $this->assertNotEmpty($snapshot['owner_overrides']);
        $this->assertSame('estimate_manual_adjust', $snapshot['owner_overrides'][0]['override_kind']);

        // Addendum: versioned outcomes + model/confidence/category/embedding reserved
        $this->assertNotEmpty($snapshot['estimate']['versions']);
        $this->assertSame('drywall_paint', $snapshot['estimate']['service_category']);
        $this->assertNotNull($snapshot['estimate']['confidence']);
        $this->assertSame('openai', $snapshot['estimate']['ai_provider']);
        $this->assertSame('gpt-4o-mini', $snapshot['estimate']['ai_model']);
        $this->assertNull($snapshot['estimate']['embedding_vector']);
    }

    public function test_estimate_versions_are_appended_not_overwritten(): void
    {
        $pm = User::create([
            'name' => 'Ver PM', 'email' => 'ver-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();
        $lead = Lead::create([
            'contact_name' => 'Version Lead',
            'email' => 'ver-'.uniqid().'@test.local',
            'phone' => '6045550333',
            'address' => '33 Version Rd',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'brand_id' => $brand->id,
            'project_description' => 'Versioned estimate test',
            'assigned_pm_id' => $pm->id,
            'parse_metadata' => ['collected_fields' => ['size_sqft' => 100]],
        ]);

        $estimate = app(PricingRangeEstimator::class)->estimate($brand, [
            'service_category' => 'drywall_paint',
            'size_sqft' => 100,
            'project_description' => 'Versioned estimate test',
            'complexity' => 'standard',
        ]);

        $v1 = app(EstimateOutcomeRecorder::class)->record($lead, $estimate, [
            'source_kind' => 'estimator',
            'ai_provider' => 'mock',
            'ai_model' => 'mock-v1',
        ]);

        $this->actingAs($pm, 'sanctum');
        $override = $this->postJson("/api/leads/{$lead->id}/price-estimate-override", [
            'price_estimate_low' => 900,
            'price_estimate_high' => 1300,
            'reason' => 'Owner override',
        ]);
        $override->assertOk();

        $recalc = $this->postJson("/api/leads/{$lead->id}/price-estimate-recalculate", [
            'reason' => 'Customer provided more sqft',
            'size_sqft' => 160,
        ]);
        $recalc->assertOk();

        $rows = EstimateOutcome::where('lead_id', $lead->id)->orderBy('version')->get();
        $this->assertCount(3, $rows);
        $this->assertSame($v1->estimate_group_id, $rows[1]->estimate_group_id);
        $this->assertSame($v1->estimate_group_id, $rows[2]->estimate_group_id);
        $this->assertSame([1, 2, 3], $rows->pluck('version')->all());
        $this->assertFalse((bool) $rows[0]->fresh()->is_current);
        $this->assertFalse((bool) $rows[1]->fresh()->is_current);
        $this->assertTrue((bool) $rows[2]->fresh()->is_current);
        $this->assertSame('manual_override', $rows[1]->source_kind);
        $this->assertSame('recalculate', $rows[2]->source_kind);
        $this->assertSame('drywall_paint', $rows[0]->service_category);
        $this->assertSame('drywall_paint', $rows[1]->service_category);
        $this->assertSame('drywall_paint', $rows[2]->service_category);
        $this->assertNotNull($rows[0]->confidence);
        $this->assertSame('mock', $rows[0]->ai_provider);
        $this->assertSame('mock-v1', $rows[0]->ai_model);
        $this->assertNull($rows[1]->ai_provider); // manual override
        $this->assertNotNull($rows[2]->ai_provider);
        $this->assertNull($rows[0]->embedding_vector);
        $this->assertNull($rows[1]->embedding_vector);
        $this->assertNull($rows[2]->embedding_vector);

        // Prior version values preserved (not overwritten)
        $this->assertEquals($v1->price_low, $rows[0]->fresh()->price_low);
        $this->assertEquals(900.0, (float) $rows[1]->price_low);
    }

    public function test_completion_and_override_endpoints_capture_new_fields(): void
    {
        $pm = User::create([
            'name' => 'Cap PM', 'email' => 'cap-pm-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractor = User::create([
            'name' => 'Cap Con', 'email' => 'cap-con-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Cap Cust', 'email' => 'cap-cust-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role' => 'customer', 'status' => 'active',
        ]);
        $brand = Brand::where('domain', 'acuteradrywall.ca')->firstOrFail();

        $lead = Lead::create([
            'contact_name' => $customer->name,
            'email' => $customer->email,
            'phone' => '6045550222',
            'address' => '22 Capture St',
            'service_category' => 'drywall_paint',
            'status' => 'converted',
            'brand_id' => $brand->id,
            'assigned_pm_id' => $pm->id,
            'customer_id' => $customer->id,
        ]);

        app(EstimateOutcomeRecorder::class)->record($lead, [
            'available' => true,
            'low' => 1200,
            'high' => 1800,
            'currency' => 'CAD',
            'confidence' => 'medium',
            'inputs_used' => ['service_category' => 'drywall_paint'],
            'service_category' => 'drywall_paint',
        ], ['source_kind' => 'estimator']);

        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'contractor_id' => $contractor->id,
            'pm_id' => $pm->id,
            'address' => '22 Capture St',
            'service_category' => 'drywall_paint',
            'status' => 'in_progress',
            'scope_of_work' => 'Capture flow job',
        ]);

        $this->actingAs($contractor, 'sanctum');
        $complete = $this->postJson("/api/jobs/{$job->id}/contractor-complete", [
            'actual_labour_hours' => 6.25,
            'materials_used' => [
                ['item' => 'Primer', 'qty' => 1, 'unit' => 'gallon'],
            ],
        ]);
        $complete->assertOk();
        $job->refresh();
        $this->assertSame(6.25, (float) $job->actual_labour_hours);
        $this->assertSame('Primer', $job->materials_used[0]['item']);

        $this->actingAs($pm, 'sanctum');
        $override = $this->postJson("/api/leads/{$lead->id}/price-estimate-override", [
            'price_estimate_low' => 1100,
            'price_estimate_high' => 1600,
            'reason' => 'Site visit reduced scope',
        ]);
        $override->assertOk();
        $this->assertTrue(
            PricingOverrideLog::where('lead_id', $lead->id)
                ->where('override_kind', 'estimate_manual_adjust')
                ->whereNotNull('estimate_outcome_id')
                ->exists()
        );
        $this->assertSame(2, EstimateOutcome::where('lead_id', $lead->id)->count());

        $snap = $this->getJson("/api/jobs/{$job->id}/learning-snapshot");
        $snap->assertOk();
        $this->assertFalse($snap->json('snapshot.ai_learning'));
        $this->assertSame(6.25, $snap->json('snapshot.actual_labour_hours'));
        $this->assertNotEmpty($snap->json('snapshot.owner_overrides'));
        $this->assertCount(2, $snap->json('snapshot.estimate.versions'));
    }

    public function test_pricing_rule_edit_logs_override(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $rule = PricingRule::query()->where('status', 'active')->firstOrFail();

        $this->actingAs($owner, 'sanctum');
        $res = $this->putJson("/api/pricing-rules/{$rule->id}", [
            'base_rate' => (float) $rule->base_rate + 1,
            'override_reason' => 'Trystan rate review',
        ]);
        $res->assertOk();

        $this->assertTrue(
            PricingOverrideLog::where('subject_type', 'pricing_rule')
                ->where('subject_id', $rule->id)
                ->where('override_kind', 'rule_edit')
                ->exists()
        );
    }
}
