<?php

namespace Tests\Feature\LearningGateway;

use App\Models\Brand;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LearningEvidenceEntry;
use App\Models\LearningGatewayAccessLog;
use App\Models\LearningNormalizedRecord;
use App\Models\Setting;
use App\Models\User;
use App\Services\Learning\LearningEligibilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Milestone 6B Phase 4 — Learning AI write tools + structural authority enforcement.
 */
class LearningAiWriteToolsTest extends TestCase
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
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_31_120001_add_learning_ai_role.php',
            '--force' => true,
        ]);
        if (! Schema::hasTable('learning_gateway_access_logs')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_31_120002_create_learning_gateway_access_logs_table.php',
                '--force' => true,
            ]);
        }
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_31_120003_add_learning_eligibility_columns.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_01_000020_phase3_learning_eligibility_authority.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_01_000030_create_learning_normalized_records_and_evidence.php',
            '--force' => true,
        ]);
        Setting::setBool(config('learning_ai.kill_switch_setting_key'), false);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function makeLearningActor(?array $abilities = null): array
    {
        $abilities ??= config('learning_ai.abilities');
        $email = config('learning_ai.actor_email');
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Learning AI',
                'password' => Hash::make(Str::random(40)),
                'role' => 'learning_ai',
                'status' => 'active',
                'sms_enabled' => false,
                'can_finalize_learning_eligibility' => false,
            ]
        );
        $user->forceFill([
            'role' => 'learning_ai',
            'status' => 'active',
            'can_finalize_learning_eligibility' => false,
        ])->save();
        $plain = $user->createToken('learn-'.Str::random(6), $abilities, now()->addDays(30))->plainTextToken;

        return [$user, $plain];
    }

    private function headers(string $plain): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    /**
     * @return array{lead: Lead, job: Job, outcome: EstimateOutcome}
     */
    private function seedSubjects(): array
    {
        $brand = Brand::query()->first() ?? Brand::create([
            'company_name' => 'Learn Brand',
            'domain' => 'learn-'.uniqid().'.example',
            'slug' => 'learn-'.uniqid(),
            'status' => 'active',
        ]);
        $customer = User::create([
            'name' => 'Cust',
            'email' => 'cust-learn-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'contact_name' => 'Learn Lead',
            'phone' => '6045550100',
            'email' => 'lead-learn-'.uniqid().'@example.com',
            'address' => '9 Learn St',
            'service_category' => 'drywall_paint',
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'status' => 'new',
            'is_test_data' => false,
        ]);
        $job = Job::create([
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'address' => '9 Learn St',
            'service_category' => 'drywall_paint',
            'status' => 'completed',
            'actual_labour_hours' => 7.5,
            'materials_used' => [['item' => 'Drywall', 'qty' => 2]],
            'learning_eligibility_status' => 'pending_review',
        ]);
        $outcome = EstimateOutcome::create([
            'estimate_group_id' => (string) Str::uuid(),
            'lead_id' => $lead->id,
            'job_id' => $job->id,
            'brand_id' => $brand->id,
            'version' => 1,
            'source_kind' => 'estimator',
            'service_category' => 'drywall_paint',
            'price_low' => 100,
            'price_high' => 200,
            'currency' => 'CAD',
            'confidence' => 'medium',
            'available' => true,
            'widened' => false,
            'is_placeholder' => false,
            'is_current' => true,
            'estimator_engine' => 'pricing_range_v1',
            'estimated_at' => now(),
            'learning_eligibility_status' => 'pending_review',
        ]);

        return compact('lead', 'job', 'outcome');
    }

    public function test_learning_ai_can_create_record_evidence_and_recommend_never_verified(): void
    {
        [$actor, $token] = $this->makeLearningActor();
        $subjects = $this->seedSubjects();
        $h = $this->headers($token);

        $create = $this->postJson('/api/learning-gateway/tools/normalized-record', [
            'subject_type' => 'job',
            'subject_id' => $subjects['job']->id,
            'learning_eligibility_status' => 'verified', // must be coerced away
            'extracted_fields' => ['labour_hours_estimate' => 8],
            'confidence' => 0.71,
            'warnings' => ['hours inferred'],
            'missing_data_flags' => ['invoice_pdf'],
            'actual_labour_hours' => 99, // must be stripped / ignored
        ], $h)->assertCreated();

        $this->assertSame('pending_review', $create->json('record.learning_eligibility_status'));
        $this->assertNotEquals('verified', $create->json('record.learning_eligibility_status'));
        $this->assertContains('actual_labour_hours', $create->json('stripped_fields'));
        $recordId = $create->json('record.id');

        $this->postJson('/api/learning-gateway/tools/normalized-record', [
            'subject_type' => 'job',
            'subject_id' => $subjects['job']->id,
            'learning_eligibility_status' => 'provisional',
            'extracted_fields' => ['note' => 'provisional draft'],
        ], $h)->assertCreated()
            ->assertJsonPath('record.learning_eligibility_status', 'provisional');

        $this->postJson('/api/learning-gateway/tools/evidence', [
            'learning_normalized_record_id' => $recordId,
            'confidence' => 0.8,
            'source_references' => [['type' => 'job_photo', 'id' => 1]],
            'warnings' => ['blurry'],
            'missing_data_flags' => ['materials'],
            'actual_labour_hours' => 123,
        ], $h)->assertCreated();

        $this->assertSame(1, LearningEvidenceEntry::where('learning_normalized_record_id', $recordId)->count());

        // Second evidence appends, does not replace
        $this->postJson('/api/learning-gateway/tools/evidence', [
            'learning_normalized_record_id' => $recordId,
            'notes' => 'second entry',
        ], $h)->assertCreated();
        $this->assertSame(2, LearningEvidenceEntry::where('learning_normalized_record_id', $recordId)->count());

        $rec = $this->postJson('/api/learning-gateway/tools/recommendation', [
            'estimate_outcome_id' => $subjects['outcome']->id,
            'status' => 'verified',
            'reason' => 'Learning AI recommends verified based on photos',
            'missing_actuals' => 'Invoice not attached',
        ], $h)->assertOk();

        $this->assertSame('pending_review', $rec->json('estimate_outcome.learning_eligibility_status'));
        $this->assertSame('verified', $rec->json('estimate_outcome.learning_recommended_status'));
        $this->assertSame('pending_approval', $rec->json('recommendation_state'));
        $this->assertFalse(
            EstimateOutcome::productionLearningSet()->where('id', $subjects['outcome']->id)->exists()
        );
        $this->assertSame($actor->id, $subjects['outcome']->fresh()->learning_recommended_by);
    }

    public function test_service_layer_blocks_learning_ai_approve_even_with_finalize_flag(): void
    {
        [$actor] = $this->makeLearningActor();
        // Simulate catastrophic misconfiguration: finalize flag + all abilities
        $actor->forceFill(['can_finalize_learning_eligibility' => true])->save();
        $this->assertTrue($actor->fresh()->canFinalizeLearningEligibility());

        $subjects = $this->seedSubjects();
        $svc = app(LearningEligibilityService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Learning AI cannot finalize');
        $svc->approveEstimateOutcome(
            $subjects['outcome'],
            'verified',
            'AI trying to self-approve',
            $actor->fresh(),
        );
    }

    public function test_service_layer_blocks_learning_ai_approve_job(): void
    {
        [$actor] = $this->makeLearningActor();
        $actor->forceFill(['can_finalize_learning_eligibility' => true])->save();
        $subjects = $this->seedSubjects();

        $this->expectException(\RuntimeException::class);
        app(LearningEligibilityService::class)->approveJob(
            $subjects['job'],
            'excluded',
            'AI self-exclude',
            $actor->fresh(),
        );
    }

    public function test_crafted_payload_cannot_overwrite_job_actual_labour_hours(): void
    {
        [, $token] = $this->makeLearningActor();
        $subjects = $this->seedSubjects();
        $before = (float) $subjects['job']->fresh()->actual_labour_hours;
        $this->assertSame(7.5, $before);

        $this->postJson('/api/learning-gateway/tools/normalized-record', [
            'subject_type' => 'job',
            'subject_id' => $subjects['job']->id,
            'learning_eligibility_status' => 'provisional',
            'actual_labour_hours' => 99.9,
            'job' => [
                'actual_labour_hours' => 88.8,
                'materials_used' => [['item' => 'Invented']],
                'status' => 'cancelled',
            ],
            'extracted_fields' => ['commentary' => 'ok'],
        ], $this->headers($token))->assertCreated();

        $job = $subjects['job']->fresh();
        $this->assertSame(7.5, (float) $job->actual_labour_hours);
        $this->assertSame('Drywall', $job->materials_used[0]['item'] ?? null);
        $this->assertSame('completed', $job->status);

        $recordId = LearningNormalizedRecord::query()->latest('id')->value('id');
        $this->postJson('/api/learning-gateway/tools/evidence', [
            'learning_normalized_record_id' => $recordId,
            'actual_labour_hours' => 55,
            'job' => ['actual_labour_hours' => 44],
            'source_references' => [['type' => 'note']],
        ], $this->headers($token))->assertCreated();

        $this->assertSame(7.5, (float) $subjects['job']->fresh()->actual_labour_hours);
    }

    public function test_learning_ai_has_zero_access_to_pricing_rules(): void
    {
        [, $token] = $this->makeLearningActor();
        $h = $this->headers($token);

        $this->getJson('/api/pricing-rules', $h)->assertForbidden();
        $this->postJson('/api/pricing-rules', [
            'service_category' => 'drywall_paint',
            'name' => 'hack',
        ], $h)->assertForbidden();
        $this->postJson('/api/pricing-rules/preview', [
            'service_category' => 'drywall_paint',
        ], $h)->assertForbidden();
    }

    public function test_write_tools_appear_in_access_logs(): void
    {
        [, $token] = $this->makeLearningActor();
        $subjects = $this->seedSubjects();
        $h = $this->headers($token);
        $before = LearningGatewayAccessLog::count();

        $this->postJson('/api/learning-gateway/tools/normalized-record', [
            'subject_type' => 'lead',
            'subject_id' => $subjects['lead']->id,
            'learning_eligibility_status' => 'pending_review',
        ], $h)->assertCreated();

        $this->postJson('/api/learning-gateway/tools/evidence', [
            'learning_normalized_record_id' => LearningNormalizedRecord::query()->latest('id')->value('id'),
            'notes' => 'log me',
        ], $h)->assertCreated();

        $this->postJson('/api/learning-gateway/tools/recommendation', [
            'job_id' => $subjects['job']->id,
            'status' => 'excluded',
            'reason' => 'log recommendation',
        ], $h)->assertOk();

        $this->assertGreaterThanOrEqual($before + 3, LearningGatewayAccessLog::count());
        $tools = LearningGatewayAccessLog::query()
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('tool')
            ->all();
        $this->assertContains('normalized-record', $tools);
        $this->assertContains('evidence', $tools);
        $this->assertContains('recommendation', $tools);
    }

    public function test_kill_switch_blocks_all_three_write_tools(): void
    {
        [, $token] = $this->makeLearningActor();
        $subjects = $this->seedSubjects();
        Setting::setBool(config('learning_ai.kill_switch_setting_key'), true);
        $h = $this->headers($token);

        foreach ([
            ['POST', '/api/learning-gateway/tools/normalized-record', [
                'subject_type' => 'lead',
                'subject_id' => $subjects['lead']->id,
            ]],
            ['POST', '/api/learning-gateway/tools/evidence', [
                'learning_normalized_record_id' => 1,
            ]],
            ['POST', '/api/learning-gateway/tools/recommendation', [
                'job_id' => $subjects['job']->id,
                'status' => 'verified',
                'reason' => 'blocked',
            ]],
        ] as [$method, $url, $body]) {
            $this->json($method, $url, $body, $h)
                ->assertForbidden()
                ->assertJsonPath('code', 'learning_gateway_kill_switch');
        }
    }

    public function test_no_approve_route_under_learning_gateway(): void
    {
        [, $token] = $this->makeLearningActor();
        $subjects = $this->seedSubjects();
        $h = $this->headers($token);

        $this->postJson('/api/learning-gateway/tools/approve', [
            'estimate_outcome_id' => $subjects['outcome']->id,
            'status' => 'verified',
            'reason' => 'should not exist',
        ], $h)->assertNotFound();

        $this->patchJson('/api/admin/learning-eligibility/'.$subjects['outcome']->id.'/approve', [
            'status' => 'verified',
            'reason' => 'learning_ai via human route',
        ], $h)->assertForbidden();
    }

    public function test_evidence_write_ability_required_for_normalized_record(): void
    {
        [, $token] = $this->makeLearningActor(['learning:read']);
        $subjects = $this->seedSubjects();

        $this->postJson('/api/learning-gateway/tools/normalized-record', [
            'subject_type' => 'lead',
            'subject_id' => $subjects['lead']->id,
        ], $this->headers($token))
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'learning:evidence-write');
    }
}
