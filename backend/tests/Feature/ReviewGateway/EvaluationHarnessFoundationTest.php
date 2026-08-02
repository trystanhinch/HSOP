<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\AiConversationLog;
use App\Models\AiEvaluationFinding;
use App\Models\AiEvaluationRun;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReviewGateway\PlaceholderEvaluationScorer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Milestone 6A.3 / Phase 5 — evaluation harness foundation.
 */
class EvaluationHarnessFoundationTest extends TestCase
{
    use CreatesExternalReviewAiActor;
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
        $this->ensureExternalReviewRoleMigrated();
        foreach ([
            'database/migrations/2026_07_31_180001_create_ai_evaluation_runs_table.php',
            'database/migrations/2026_07_31_180002_create_ai_evaluation_findings_table.php',
        ] as $path) {
            $table = str_contains($path, 'findings') ? 'ai_evaluation_findings' : 'ai_evaluation_runs';
            if (! Schema::hasTable($table)) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true]);
            }
        }
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), false);
    }

    private function seedConversation(): AiConversationLog
    {
        $lead = Lead::create([
            'contact_name' => 'Eval Lead',
            'phone' => '6045550199',
            'email' => 'eval-lead-'.uniqid().'@example.com',
            'address' => '200 Eval St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'is_test_data' => true,
        ]);
        $brand = Brand::query()->first() ?? Brand::create([
            'company_name' => 'Eval Brand',
            'domain' => 'eval-'.uniqid().'.example',
            'slug' => 'eval-'.uniqid(),
            'status' => 'active',
        ]);
        $session = IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => Str::random(40),
            'conversation_state' => [],
            'expires_at' => now()->addDay(),
        ]);

        return AiConversationLog::create([
            'intake_session_id' => $session->id,
            'lead_id' => $lead->id,
            'turn_number' => 1,
            'role' => 'assistant',
            'content' => 'We can schedule a site visit after confirming the drywall scope and photos.',
            'content_preview' => 'We can schedule',
            'trace_id' => (string) Str::uuid(),
            'tool_calls' => [['name' => 'noop']],
            'tool_results' => [['ok' => true]],
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'created_at' => now(),
        ]);
    }

    private function runPayload(array $over = []): array
    {
        return array_merge([
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'model_version' => '2026-07',
            'prompt_version' => 'eval-prompt-1.0.0',
            'evaluation_version' => '1.0.0',
            'benchmark_set_version' => 'smoke-local-v1',
            'run_type' => 'manual',
            'status' => 'running',
            'total_cost' => 0,
        ], $over);
    }

    public function test_1_evidence_write_required_for_evaluation_routes(): void
    {
        $log = $this->seedConversation();

        [, $readOnly] = $this->makeExternalReviewActor(['review:read']);
        $h = $this->reviewAuthHeaders($readOnly);

        $this->postJson('/api/review-gateway/tools/evaluation-run', $this->runPayload(), $h)
            ->assertForbidden()
            ->assertJsonPath('code', 'review_ability_required')
            ->assertJsonPath('required_ability', 'review:evidence-write');

        $this->postJson('/api/review-gateway/tools/evaluation-finding', [
            'evaluation_run_id' => 1,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => $log->id,
            'dimension' => 'scope_completeness',
            'score' => 3,
            'statement_kind' => 'observed_fact',
        ], $h)
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'review:evidence-write');
    }

    public function test_2_can_create_run_and_finding_with_evidence_write(): void
    {
        $log = $this->seedConversation();
        [, $token] = $this->makeExternalReviewActor(['review:evidence-write']);
        $h = $this->reviewAuthHeaders($token);

        $runResp = $this->postJson('/api/review-gateway/tools/evaluation-run', $this->runPayload([
            'status' => 'completed',
            'total_cost' => 0.0123,
        ]), $h)
            ->assertCreated()
            ->assertJsonPath('tool', 'evaluation_run')
            ->assertJsonPath('run.provider', 'openai')
            ->assertJsonPath('run.model', 'gpt-4.1-mini')
            ->assertJsonPath('run.prompt_version', 'eval-prompt-1.0.0')
            ->assertJsonPath('run.evaluation_version', '1.0.0')
            ->assertJsonPath('run.status', 'completed');

        $runId = $runResp->json('run.id');
        $this->assertNotNull($runId);

        $this->postJson('/api/review-gateway/tools/evaluation-finding', [
            'evaluation_run_id' => $runId,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => $log->id,
            'dimension' => 'factual_grounding',
            'score' => 4,
            'max_score' => 5,
            'critique' => 'Provider/model present on turn.',
            'statement_kind' => 'observed_fact',
        ], $h)
            ->assertCreated()
            ->assertJsonPath('tool', 'evaluation_finding')
            ->assertJsonPath('finding.dimension', 'factual_grounding')
            ->assertJsonPath('finding.statement_kind', 'observed_fact')
            ->assertJsonPath('finding.evaluation_run_id', $runId);
    }

    public function test_3_cannot_write_finding_to_missing_or_foreign_run(): void
    {
        $log = $this->seedConversation();
        [$userA, $tokenA] = $this->makeExternalReviewActor(['review:evidence-write']);
        $hA = $this->reviewAuthHeaders($tokenA);

        $runId = $this->postJson('/api/review-gateway/tools/evaluation-run', $this->runPayload(), $hA)
            ->assertCreated()
            ->json('run.id');

        $this->postJson('/api/review-gateway/tools/evaluation-finding', [
            'evaluation_run_id' => 999999999,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => $log->id,
            'dimension' => 'authorization',
            'score' => 1,
            'statement_kind' => 'inference',
        ], $hA)->assertNotFound();

        // Second actor: same role email in makeExternalReviewActor is firstOrCreate —
        // create a distinct user with evidence-write for foreign ownership.
        $other = User::create([
            'name' => 'Other Review AI',
            'email' => 'other-review-ai-'.uniqid().'@test.local',
            'password' => Hash::make(Str::random(32)),
            'role' => config('review_gateway.actor_role', 'external_review_ai'),
            'status' => 'active',
            'sms_enabled' => false,
        ]);
        $plainB = $other->createToken('other-eval', ['review:evidence-write'], now()->addDay())->plainTextToken;
        $hB = $this->reviewAuthHeaders($plainB);

        $this->postJson('/api/review-gateway/tools/evaluation-finding', [
            'evaluation_run_id' => $runId,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => $log->id,
            'dimension' => 'authorization',
            'score' => 1,
            'statement_kind' => 'inference',
        ], $hB)->assertForbidden();

        $this->assertSame(0, AiEvaluationFinding::query()->where('evaluation_run_id', $runId)->count());
        $this->assertSame($userA->id, (int) AiEvaluationRun::query()->find($runId)->actor_user_id);
    }

    public function test_4_tables_are_append_only(): void
    {
        $run = AiEvaluationRun::create([
            'provider' => 'openai',
            'model' => 'placeholder',
            'model_version' => '1',
            'prompt_version' => 'p1',
            'evaluation_version' => '1.0.0',
            'run_type' => 'smoke',
            'initiated_by_type' => 'user',
            'initiated_by_id' => 1,
            'actor_user_id' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'total_cost' => 0,
            'status' => 'completed',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $finding = AiEvaluationFinding::create([
            'evaluation_run_id' => $run->id,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => 1,
            'dimension' => 'consistency',
            'score' => 3,
            'max_score' => 5,
            'critique' => 'n/a',
            'statement_kind' => 'inference',
            'evidence_reference' => 'ai_conversation_log:1',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $run->status = 'failed';
        $run->save();
    }

    public function test_4b_findings_reject_delete(): void
    {
        $run = AiEvaluationRun::create([
            'provider' => 'openai',
            'model' => 'placeholder',
            'prompt_version' => 'p1',
            'evaluation_version' => '1.0.0',
            'run_type' => 'smoke',
            'initiated_by_type' => 'user',
            'started_at' => now(),
            'total_cost' => 0,
            'status' => 'completed',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $finding = AiEvaluationFinding::create([
            'evaluation_run_id' => $run->id,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => 1,
            'dimension' => 'consistency',
            'score' => 3,
            'max_score' => 5,
            'statement_kind' => 'recommendation',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $finding->delete();
    }

    public function test_5_owner_can_view_runs_and_findings_non_owners_forbidden(): void
    {
        $log = $this->seedConversation();
        [, $token] = $this->makeExternalReviewActor(['review:evidence-write']);
        $h = $this->reviewAuthHeaders($token);

        $runId = $this->postJson('/api/review-gateway/tools/evaluation-run', $this->runPayload([
            'status' => 'completed',
        ]), $h)->assertCreated()->json('run.id');

        $this->postJson('/api/review-gateway/tools/evaluation-finding', [
            'evaluation_run_id' => $runId,
            'subject_type' => 'ai_conversation_log',
            'subject_id' => $log->id,
            'dimension' => 'scope_completeness',
            'score' => 4,
            'statement_kind' => 'inference',
        ], $h)->assertCreated();

        $owner = User::create([
            'name' => 'Owner Eval',
            'email' => 'owner-eval-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/review-gateway/evaluation-runs')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);

        $this->getJson('/api/admin/review-gateway/evaluation-runs/'.$runId.'/findings')
            ->assertOk()
            ->assertJsonPath('run.id', $runId)
            ->assertJsonPath('data.0.dimension', 'scope_completeness');

        foreach (['pm', 'contractor', 'customer'] as $role) {
            $user = User::create([
                'name' => ucfirst($role),
                'email' => $role.'-eval-'.uniqid().'@test.local',
                'password' => Hash::make('password'),
                'role' => $role,
                'status' => 'active',
            ]);
            Sanctum::actingAs($user);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            $this->getJson('/api/admin/review-gateway/evaluation-runs')->assertForbidden();
            $this->getJson('/api/admin/review-gateway/evaluation-runs/'.$runId.'/findings')->assertForbidden();
        }
    }

    public function test_6_smoke_command_persists_placeholder_findings(): void
    {
        $this->seedConversation();
        $this->seedConversation();

        $beforeRuns = AiEvaluationRun::count();
        $beforeFindings = AiEvaluationFinding::count();

        $this->artisan('review-ai:smoke-evaluation', ['--limit' => 2])
            ->assertSuccessful();

        $this->assertGreaterThan($beforeRuns, AiEvaluationRun::count());
        $this->assertGreaterThan($beforeFindings, AiEvaluationFinding::count());

        $run = AiEvaluationRun::query()->orderByDesc('id')->first();
        $this->assertSame('smoke', $run->run_type);
        $this->assertSame('completed', $run->status);
        $this->assertSame('openai', $run->provider);
        $this->assertGreaterThanOrEqual(3, $run->findings()->count());

        $dims = $run->findings()->pluck('dimension')->unique()->sort()->values()->all();
        foreach (PlaceholderEvaluationScorer::SMOKE_DIMENSIONS as $d) {
            $this->assertContains($d, $dims);
        }
    }
}
