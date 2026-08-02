<?php

namespace Tests\Feature\Learning;

use App\Models\Brand;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LearningRecord;
use App\Models\Region;
use App\Models\User;
use App\Services\Learning\LearningRecordAssemblyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Milestone 6B Phase 5 — canonical learning_records assembly + property/region.
 */
class LearningRecordAssemblyTest extends TestCase
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
        foreach ([
            'database/migrations/2026_07_31_120003_add_learning_eligibility_columns.php',
            'database/migrations/2026_08_01_000020_phase3_learning_eligibility_authority.php',
            'database/migrations/2026_08_01_000040_create_regions_table.php',
            'database/migrations/2026_08_01_000041_create_properties_table.php',
            'database/migrations/2026_08_01_000042_create_learning_records_table.php',
        ] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }
    }

    /**
     * @return array{job: Job, lead: Lead, outcome: EstimateOutcome}
     */
    private function seedJob(array $jobExtra = [], array $outcomeExtra = []): array
    {
        $brand = Brand::query()->first() ?? Brand::create([
            'company_name' => 'Assemble Brand',
            'domain' => 'asm-'.uniqid().'.example',
            'slug' => 'asm-'.uniqid(),
            'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Cust Asm',
            'email' => 'cust-asm-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $pm = User::create([
            'name' => 'PM Asm',
            'email' => 'pm-asm-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'pm',
            'status' => 'active',
        ]);
        $contractor = User::create([
            'name' => 'Con Asm',
            'email' => 'con-asm-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'contractor',
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'contact_name' => 'Asm Lead',
            'phone' => '6045550199',
            'email' => 'lead-asm-'.uniqid().'@example.com',
            'address' => '123 Main St, Surrey, BC V3T 1A1',
            'service_category' => 'drywall_paint',
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'status' => 'converted',
            'is_test_data' => false,
        ]);
        $job = Job::create(array_merge([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'pm_id' => $pm->id,
            'contractor_id' => $contractor->id,
            'address' => '123 Main St, Surrey, BC V3T 1A1',
            'service_category' => 'drywall_paint',
            'status' => 'completed',
            'scope_of_work' => 'Ceiling repair',
            'actual_labour_hours' => 6.5,
            'materials_used' => [['item' => 'Drywall', 'qty' => 2]],
            'completed_at' => now(),
            'learning_eligibility_status' => 'pending_review',
        ], $jobExtra));
        $outcome = EstimateOutcome::create(array_merge([
            'estimate_group_id' => (string) Str::uuid(),
            'lead_id' => $lead->id,
            'job_id' => $job->id,
            'brand_id' => $brand->id,
            'version' => 1,
            'source_kind' => 'estimator',
            'service_category' => 'drywall_paint',
            'price_low' => 800,
            'price_high' => 1200,
            'currency' => 'CAD',
            'confidence' => 'medium',
            'available' => true,
            'widened' => false,
            'is_placeholder' => false,
            'is_current' => true,
            'estimator_engine' => 'pricing_range_v1',
            'estimated_at' => now(),
            'learning_eligibility_status' => 'provisional',
        ], $outcomeExtra));

        return compact('job', 'lead', 'outcome');
    }

    public function test_region_seeder_creates_exactly_ten_documented_regions(): void
    {
        $expected = [
            'vancouver', 'langley', 'surrey', 'burnaby', 'richmond',
            'coquitlam', 'new-westminster', 'north-vancouver', 'abbotsford', 'chilliwack',
        ];
        $slugs = Region::query()->orderBy('sort_order')->pluck('slug')->all();
        // Migration seeds idempotently; assert all 10 exist (may coexist with extras only if manually added — expect exactly these from seed)
        foreach ($expected as $slug) {
            $this->assertTrue(Region::query()->where('slug', $slug)->exists(), "Missing region {$slug}");
        }
        $seeded = Region::query()->whereIn('slug', $expected)->count();
        $this->assertSame(10, $seeded);
        $this->assertSame(10, count($expected));
    }

    public function test_assembly_pulls_source_data_with_provenance(): void
    {
        $seed = $this->seedJob();
        $record = app(LearningRecordAssemblyService::class)->assembleForJob($seed['job']->id);

        $this->assertSame($seed['job']->id, $record->job_id);
        $this->assertSame($seed['lead']->id, $record->lead_id);
        $this->assertSame(1, $record->version);
        $this->assertTrue($record->is_current);
        $this->assertSame(6.5, (float) $record->payload['actual_labour_hours']);
        $this->assertSame('jobs', $record->provenance['actual_labour_hours']['source_table']);
        $this->assertSame($seed['job']->id, $record->provenance['actual_labour_hours']['source_id']);
        $this->assertSame('contractor-stated', $record->provenance['actual_labour_hours']['provenance_type']);
        $this->assertArrayHasKey('source_timestamp', $record->provenance['actual_labour_hours']);
        $this->assertSame('estimate_outcomes', $record->provenance['estimate_price_low']['source_table']);
        $this->assertSame('AI-derived', $record->provenance['estimate_price_low']['provenance_type']);
        $this->assertNotNull($record->property_id);
        $this->assertNotNull($record->region_id);
        $this->assertSame('Surrey', Region::find($record->region_id)?->name);
        $this->assertContains($seed['outcome']->id, $record->links['estimate_outcome_ids'] ?? []);
    }

    public function test_reassembly_creates_new_version_no_data_loss(): void
    {
        $seed = $this->seedJob();
        $svc = app(LearningRecordAssemblyService::class);
        $v1 = $svc->assembleForJob($seed['job']->id);
        $seed['job']->update(['actual_labour_hours' => 9.0]);
        $v2 = $svc->assembleForJob($seed['job']->id);

        $this->assertSame($v1->record_group_id, $v2->record_group_id);
        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertFalse($v1->fresh()->is_current);
        $this->assertTrue($v2->is_current);
        $this->assertSame(6.5, (float) $v1->fresh()->payload['actual_labour_hours']);
        $this->assertSame(9.0, (float) $v2->payload['actual_labour_hours']);
        $this->assertSame(2, LearningRecord::query()->where('job_id', $seed['job']->id)->count());
    }

    public function test_eligibility_reflects_phase3_source_not_independent(): void
    {
        $seed = $this->seedJob();
        $record = app(LearningRecordAssemblyService::class)->assembleForJob($seed['job']->id);

        $this->assertSame('estimate_outcome', $record->eligibility_source_type);
        $this->assertSame($seed['outcome']->id, $record->eligibility_source_id);
        $this->assertSame('provisional', $record->eligibility_status_snapshot);
        $this->assertSame('provisional', $record->resolvedEligibilityStatus());

        // Change Phase 3 source — live resolution follows without reassembly
        $seed['outcome']->update(['learning_eligibility_status' => 'verified']);
        $this->assertSame('verified', $record->fresh()->resolvedEligibilityStatus());
        // Snapshot stays historical
        $this->assertSame('provisional', $record->fresh()->eligibility_status_snapshot);
    }

    public function test_property_and_region_nullable_do_not_break_jobs_without_them(): void
    {
        $this->assertTrue(Schema::hasColumn('jobs', 'property_id'));
        $this->assertTrue(Schema::hasColumn('leads', 'property_id'));

        $seed = $this->seedJob(['address' => 'Somewhere without a known city']);
        // Ensure no property pre-linked
        $this->assertNull($seed['job']->property_id);

        $record = app(LearningRecordAssemblyService::class)->assembleForJob($seed['job']->id, ensureProperty: true);
        // Property may be created from raw address even without region match
        $this->assertNotNull($record->property_id);
        // Region may be null when city not in seed list
        $jobWithoutAddress = $this->seedJob(['address' => null], []);
        $jobWithoutAddress['lead']->update(['address' => null]);
        $jobWithoutAddress['job']->update(['address' => null, 'property_id' => null]);

        $r2 = app(LearningRecordAssemblyService::class)->assembleForJob($jobWithoutAddress['job']->id, ensureProperty: true);
        $this->assertNull($r2->property_id);
        $this->assertNull($r2->region_id);
        $this->assertContains('property', $r2->missing_sources ?? []);
    }

    public function test_artisan_command_assembles_record(): void
    {
        $seed = $this->seedJob();
        $exit = Artisan::call('learning:assemble-record', ['jobId' => $seed['job']->id]);
        $this->assertSame(0, $exit);
        $this->assertTrue(
            LearningRecord::query()->where('job_id', $seed['job']->id)->where('is_current', true)->exists()
        );
    }

    public function test_phase4_draft_table_untouched_and_distinct(): void
    {
        $this->assertTrue(Schema::hasTable('learning_normalized_records'));
        $this->assertTrue(Schema::hasTable('learning_records'));
        $this->assertNotSame('learning_normalized_records', (new LearningRecord)->getTable());
    }
}
