<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Milestone 6A Phase 4 — dedicated external_review_ai identity + token expiry + legacy migration.
 */
class ReviewGatewayIdentityHardeningTest extends TestCase
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
        if (! Schema::hasTable('review_gateway_access_logs')) {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/2026_07_30_120001_create_review_gateway_access_logs_table.php',
                '--force' => true,
            ]);
        }
        $this->ensureExternalReviewRoleMigrated();
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), false);
        config(['review_gateway_code_scope.repository_root' => dirname(base_path())]);
    }

    public function test_external_review_ai_can_access_all_phase1_and_phase2_tools(): void
    {
        [, $token] = $this->makeExternalReviewActor();
        $h = $this->reviewAuthHeaders($token);

        $this->getJson('/api/review-gateway/tools/search', $h)
            ->assertOk()
            ->assertJsonPath('tool', 'search');

        $this->getJson('/api/review-gateway/tools/ai-conversation-log/1', $h);
        $this->assertNotSame(403, $this->getJson('/api/review-gateway/tools/ai-conversation-log/1', $h)->status());

        $rel = 'backend/app/Http/Middleware/EnsureReviewAiAbility.php';
        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode($rel), $h)
            ->assertOk()
            ->assertJsonPath('tool', 'source_file');
        $this->getJson('/api/review-gateway/tools/source-search?query=EnsureReviewAiAbility', $h)
            ->assertOk()
            ->assertJsonPath('tool', 'source_search');
    }

    public function test_ai_super_admin_with_review_abilities_cannot_access_review_gateway(): void
    {
        $ai = $this->makeAiSuperAdminUser();
        $plain = $ai->createToken(
            'forged-review-'.Str::random(4),
            config('review_gateway.abilities'),
            now()->addDays(30)
        )->plainTextToken;

        $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_role_required');

        $this->getJson(
            '/api/review-gateway/tools/source-file?path='.urlencode('backend/config/review_gateway.php'),
            $this->reviewAuthHeaders($plain)
        )->assertForbidden()
            ->assertJsonPath('code', 'review_role_required');
    }

    public function test_external_review_ai_cannot_access_command_center_or_ai_action_gate(): void
    {
        [, $token] = $this->makeExternalReviewActor();
        $h = $this->reviewAuthHeaders($token);

        $this->postJson('/api/command-center/ask', ['message' => 'Any leads stuck?'], $h)
            ->assertForbidden();
        $this->getJson('/api/command-center/sessions', $h)->assertForbidden();
        $this->postJson('/api/ai/actions/evaluate', ['action_key' => 'noop'], $h)->assertForbidden();
        $this->postJson('/api/ai/actions/run', ['action_key' => 'noop'], $h)->assertForbidden();
    }

    public function test_expired_review_token_is_rejected(): void
    {
        [, $plain] = $this->makeExternalReviewActor(null, now()->subMinute());

        $res = $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain));
        $this->assertTrue(
            in_array($res->status(), [401, 403], true),
            'Expected expired token to be rejected, got '.$res->status()
        );
    }

    public function test_issue_token_command_attaches_to_external_review_ai_with_expiry(): void
    {
        $this->artisan('review-ai:issue-token', [
            'name' => 'phase4-test-'.Str::random(4),
            '--ttl' => 7,
        ])->assertSuccessful();

        $user = User::query()
            ->where('role', 'external_review_ai')
            ->where('email', config('review_gateway.actor_email'))
            ->first();
        $this->assertNotNull($user);

        $token = PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($token);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(5)));
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addDays(8)));
    }

    public function test_migrate_legacy_tokens_lists_without_revoke_flag(): void
    {
        $ai = $this->makeAiSuperAdminUser();
        $legacy = $ai->createToken('legacy-'.Str::random(4), ['review:read'], now()->addDay());
        $legacyId = $legacy->accessToken->id;

        $this->artisan('review-ai:migrate-legacy-tokens')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run only');

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $legacyId]);
    }

    public function test_migrate_legacy_tokens_revokes_with_flag(): void
    {
        $ai = $this->makeAiSuperAdminUser();
        $legacy = $ai->createToken('legacy-rev-'.Str::random(4), ['review:read'], now()->addDay());
        $legacyId = $legacy->accessToken->id;

        // Keep a real external_review_ai token so revoke only hits legacy.
        $this->makeExternalReviewActor(['review:read']);

        $this->artisan('review-ai:migrate-legacy-tokens', ['--revoke' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $legacyId]);
    }

    public function test_external_review_ai_interactive_login_blocked(): void
    {
        $password = 'ReviewLoginBlock-'.Str::random(12);
        $email = 'external-review-login-'.uniqid().'@test.local';
        User::create([
            'name' => 'External Review AI Login Test',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'external_review_ai',
            'status' => 'active',
            'sms_enabled' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $email,
            'password' => $password,
        ])->assertForbidden()
            ->assertJsonPath('message', 'This account cannot be used for interactive login.');
    }

    public function test_summary_active_tokens_exclude_ai_super_admin_legacy(): void
    {
        $owner = User::create([
            'name' => 'Owner Phase4',
            'email' => 'owner-p4-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);

        $ai = $this->makeAiSuperAdminUser();
        $ai->createToken('should-not-count', ['review:read'], now()->addDay());
        $this->makeExternalReviewActor(['review:read']);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/admin/review-gateway/summary')->assertOk();
        $this->assertSame(1, $res->json('active_token_count'));
        $this->assertGreaterThanOrEqual(1, $res->json('legacy_token_count'));
        $this->assertSame('external_review_ai', $res->json('identity.role'));
    }
}
