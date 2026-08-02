<?php

namespace Tests\Feature\LearningEligibility;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\EstimateOutcome;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Milestone 6B Phase 3 — recommend vs finalize authority.
 */
class LearningEligibilityTest extends TestCase
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
            '--path' => 'database/migrations/2026_07_31_120003_add_learning_eligibility_columns.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_08_01_000020_phase3_learning_eligibility_authority.php',
            '--force' => true,
        ]);
    }

    private function makeUser(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => $role.'-elig-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => 'active',
            'brand_id' => $role === 'content_editor' ? 1 : null,
            'can_finalize_learning_eligibility' => false,
        ], $extra));
    }

    private function makeOutcome(array $extra = []): EstimateOutcome
    {
        $brand = Brand::query()->first() ?? Brand::create([
            'company_name' => 'Elig Brand',
            'domain' => 'elig-'.uniqid().'.example',
            'slug' => 'elig-'.uniqid(),
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'contact_name' => 'Elig Lead',
            'phone' => '6045550199',
            'email' => 'elig-'.uniqid().'@example.com',
            'address' => '1 Test',
            'service_category' => 'drywall_paint',
            'brand_id' => $brand->id,
            'status' => 'new',
            'is_test_data' => false,
        ]);

        return EstimateOutcome::create(array_merge([
            'estimate_group_id' => (string) Str::uuid(),
            'lead_id' => $lead->id,
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
            'is_placeholder' => true,
            'is_current' => true,
            'estimator_engine' => 'pricing_range_v1',
            'estimated_at' => now(),
            'learning_eligibility_status' => 'pending_review',
        ], $extra));
    }

    public function test_pm_can_recommend_but_cannot_finalize(): void
    {
        $pm = $this->makeUser('pm');
        $outcome = $this->makeOutcome(['is_placeholder' => false]);
        Sanctum::actingAs($pm);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'Photos and invoice look complete.',
            'missing_actuals' => 'Labour hours not entered',
        ])->assertOk()
            ->assertJsonPath('estimate_outcome.learning_recommended_status', 'verified')
            ->assertJsonPath('estimate_outcome.learning_eligibility_status', 'pending_review')
            ->assertJsonPath('recommendation_state', 'pending_approval');

        $this->assertSame('pending_review', $outcome->fresh()->learning_eligibility_status);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'verified',
            'reason' => 'PM should not finalize',
        ])->assertForbidden();

        $this->assertSame('pending_review', $outcome->fresh()->learning_eligibility_status);
    }

    public function test_owner_can_recommend_and_finalize(): void
    {
        $owner = $this->makeUser('owner');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($owner);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'excluded',
            'reason' => 'Owner notes test-only job',
        ])->assertOk();

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'excluded',
            'reason' => 'Owner finalizes exclusion',
        ])->assertOk()
            ->assertJsonPath('estimate_outcome.learning_eligibility_status', 'excluded')
            ->assertJsonPath('override', false);

        $this->assertTrue(
            AuditLog::query()
                ->where('action_type', 'learning_eligibility_finalized')
                ->where('object_id', $outcome->id)
                ->exists()
        );
    }

    public function test_pm_with_finalize_flag_can_approve(): void
    {
        $pm = $this->makeUser('pm', ['can_finalize_learning_eligibility' => true]);
        $outcome = $this->makeOutcome(['is_placeholder' => false]);
        Sanctum::actingAs($pm);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'Delegated PM recommends verified',
        ])->assertOk();

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'verified',
            'reason' => 'Delegated PM finalizes',
        ])->assertOk()
            ->assertJsonPath('estimate_outcome.learning_eligibility_status', 'verified');

        $this->assertTrue($pm->canFinalizeLearningEligibility());
    }

    public function test_recommendation_without_reason_rejected(): void
    {
        $pm = $this->makeUser('pm');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($pm);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
        ])->assertStatus(422);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => '',
        ])->assertStatus(422);
    }

    public function test_approval_without_reason_rejected(): void
    {
        $owner = $this->makeUser('owner');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($owner);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'verified',
        ])->assertStatus(422);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'verified',
            'reason' => '',
        ])->assertStatus(422);
    }

    public function test_override_of_pm_recommendation_is_logged(): void
    {
        $pm = $this->makeUser('pm');
        $owner = $this->makeUser('owner');
        $outcome = $this->makeOutcome(['is_placeholder' => false]);

        Sanctum::actingAs($pm);
        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'PM wants verified',
        ])->assertOk();

        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'excluded',
            'reason' => 'Owner overrides — test data',
        ])->assertOk()
            ->assertJsonPath('override', true)
            ->assertJsonPath('estimate_outcome.learning_eligibility_status', 'excluded');

        $log = AuditLog::query()
            ->where('action_type', 'learning_eligibility_finalized')
            ->where('object_id', $outcome->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertTrue($log->new_value['override'] ?? false);
        $this->assertFalse($log->new_value['matched_recommendation'] ?? true);
        $this->assertSame('verified', $log->previous_value['learning_recommended_status'] ?? null);
        $this->assertSame('excluded', $log->new_value['learning_eligibility_status'] ?? null);
    }

    public function test_recommendation_alone_not_in_production_learning_set(): void
    {
        $pm = $this->makeUser('pm');
        $outcome = $this->makeOutcome(['is_placeholder' => false]);
        Sanctum::actingAs($pm);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'Recommend only — not finalized',
        ])->assertOk();

        $this->assertSame('pending_review', $outcome->fresh()->learning_eligibility_status);
        $this->assertFalse(
            EstimateOutcome::productionLearningSet()->where('id', $outcome->id)->exists(),
            'Merely-recommended records must not appear in production learning set'
        );

        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
            'status' => 'verified',
            'reason' => 'Owner accepts recommendation',
        ])->assertOk();

        $this->assertTrue(
            EstimateOutcome::productionLearningSet()->where('id', $outcome->id)->exists()
        );
    }

    public function test_old_direct_patch_route_removed(): void
    {
        $owner = $this->makeUser('owner');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($owner);

        // Phase 1 bypass must not exist — Laravel may 404 or MethodNotAllowed
        $res = $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id, [
            'status' => 'verified',
            'reason' => 'Old route should be gone',
        ]);
        $this->assertTrue(
            in_array($res->status(), [404, 405], true),
            'Expected 404/405 for removed direct PATCH, got '.$res->status()
        );
        $this->assertSame('pending_review', $outcome->fresh()->learning_eligibility_status);
    }

    public function test_recommendation_supersedes_prior_and_audits(): void
    {
        $pm = $this->makeUser('pm');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($pm);

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'First recommendation',
        ])->assertOk();

        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'excluded',
            'reason' => 'Second recommendation supersedes',
            'missing_actuals' => ['invoice' => 'missing'],
        ])->assertOk()
            ->assertJsonPath('estimate_outcome.learning_recommended_status', 'excluded');

        $this->assertSame(2, AuditLog::query()
            ->where('action_type', 'learning_eligibility_recommended')
            ->where('object_id', $outcome->id)
            ->count());

        $latest = AuditLog::query()
            ->where('action_type', 'learning_eligibility_recommended')
            ->where('object_id', $outcome->id)
            ->latest('id')
            ->first();
        $this->assertTrue($latest->new_value['superseded_prior'] ?? false);
    }

    public function test_list_includes_recommendation_fields(): void
    {
        $owner = $this->makeUser('owner');
        $pm = $this->makeUser('pm');
        $outcome = $this->makeOutcome();
        Sanctum::actingAs($pm);
        $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
            'status' => 'verified',
            'reason' => 'List visibility',
        ])->assertOk();

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/admin/learning-eligibility?status=pending_review')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $outcome->id);
        $this->assertNotNull($row);
        $this->assertSame('verified', $row['learning_recommended_status']);
        $this->assertSame('pending_approval', $row['recommendation_state']);
        $this->assertTrue($res->json('viewer.can_finalize'));
        $this->assertFalse($row['flags']['in_production_learning_set']);
    }

    public function test_contractor_forbidden_on_recommend_and_approve(): void
    {
        $outcome = $this->makeOutcome();
        foreach (['contractor', 'customer', 'content_editor'] as $role) {
            $user = $this->makeUser($role);
            Sanctum::actingAs($user);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/recommend', [
                'status' => 'excluded',
                'reason' => 'Should fail',
            ])->assertForbidden();

            $this->patchJson('/api/admin/learning-eligibility/'.$outcome->id.'/approve', [
                'status' => 'excluded',
                'reason' => 'Should fail',
            ])->assertForbidden();
        }
    }

    public function test_columns_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumn('estimate_outcomes', 'learning_recommended_status'));
        $this->assertTrue(Schema::hasColumn('estimate_outcomes', 'learning_approved_by'));
        $this->assertTrue(Schema::hasColumn('jobs', 'learning_recommended_status'));
        $this->assertTrue(Schema::hasColumn('users', 'can_finalize_learning_eligibility'));
    }
}
