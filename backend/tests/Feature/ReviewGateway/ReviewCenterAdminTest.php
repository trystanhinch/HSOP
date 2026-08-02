<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\AuditLog;
use App\Models\ReviewGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Milestone 6A Phase 3 — Owner Review Center admin APIs.
 */
class ReviewCenterAdminTest extends TestCase
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
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => $role.'-rc-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => 'active',
            'brand_id' => $role === 'content_editor' ? 1 : null,
        ]);
    }

    public function test_1_owner_can_access_all_review_center_endpoints(): void
    {
        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);

        $this->getJson('/api/admin/review-gateway/summary')
            ->assertOk()
            ->assertJsonStructure([
                'calls' => ['24h', '7d', '30d'],
                'denied',
                'active_token_count',
                'kill_switch',
                'identity' => ['role', 'email'],
                'tokens_nearing_expiration',
            ])
            ->assertJsonPath('identity.role', 'external_review_ai');

        $this->getJson('/api/admin/review-gateway/access-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);

        $this->getJson('/api/admin/review-gateway/tokens')
            ->assertOk()
            ->assertJsonStructure(['data', 'identity']);
    }

    public function test_2_non_owners_forbidden(): void
    {
        foreach (['pm', 'contractor', 'customer', 'content_editor'] as $role) {
            $user = $this->makeUser($role);
            Sanctum::actingAs($user);
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            $this->getJson('/api/admin/review-gateway/summary')->assertForbidden();
            $this->getJson('/api/admin/review-gateway/access-logs')->assertForbidden();
            $this->getJson('/api/admin/review-gateway/tokens')->assertForbidden();
            $this->postJson('/api/admin/review-gateway/tokens/1/revoke')->assertForbidden();
            $this->patchJson('/api/admin/review-gateway/kill-switch', ['enabled' => true])->assertForbidden();
        }
    }

    public function test_3_revoke_invalidates_review_token(): void
    {
        $owner = $this->makeUser('owner');
        [, $plain, $accessToken] = $this->makeExternalReviewActor();
        $tokenId = $accessToken->id;

        // Token works before revoke
        $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain))
            ->assertOk();

        Sanctum::actingAs($owner);
        $this->postJson('/api/admin/review-gateway/tokens/'.$tokenId.'/revoke')
            ->assertOk()
            ->assertJsonPath('id', $tokenId);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertTrue(
            AuditLog::query()->where('action_type', 'review_gateway_token_revoked')
                ->where('object_id', $tokenId)
                ->exists()
        );

        // Subsequent gateway call fails auth
        $res = $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain));
        $this->assertTrue(in_array($res->status(), [401, 403], true), 'Expected 401/403 after revoke, got '.$res->status());
    }

    public function test_4_kill_switch_via_admin_endpoint_blocks_gateway(): void
    {
        $owner = $this->makeUser('owner');
        [, $plain] = $this->makeExternalReviewActor();

        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/review-gateway/kill-switch', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('kill_switch', true);

        $this->assertTrue(Setting::getBool(config('review_gateway.kill_switch_setting_key'), false));
        $this->assertTrue(
            AuditLog::query()->where('action_type', 'review_gateway_kill_switch_changed')->exists()
        );

        $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_gateway_kill_switch');

        Sanctum::actingAs($owner);
        $this->patchJson('/api/admin/review-gateway/kill-switch', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('kill_switch', false);

        $this->getJson('/api/review-gateway/tools/search', $this->reviewAuthHeaders($plain))
            ->assertOk();
    }

    public function test_5_tokens_list_never_returns_secret(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeExternalReviewActor(['review:read']);

        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/admin/review-gateway/tokens')->assertOk();
        $body = $res->getContent();
        $this->assertStringNotContainsString('"token":', $body);
        $this->assertStringNotContainsString('plainTextToken', $body);
        foreach ($res->json('data') as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('abilities', $row);
            $this->assertArrayHasKey('created_at', $row);
            $this->assertArrayHasKey('last_used_at', $row);
            $this->assertArrayHasKey('expires_at', $row);
            $this->assertSame('external_review_ai', $row['actor_role']);
            $this->assertArrayNotHasKey('token', $row);
        }
    }

    public function test_6_access_logs_filter_by_outcome(): void
    {
        ReviewGatewayAccessLog::create([
            'http_method' => 'GET',
            'path' => '/api/review-gateway/tools/search',
            'tool' => 'search',
            'outcome' => 'denied',
            'token_name' => 'filter-test',
            'ability' => 'review:read',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);
        $res = $this->getJson('/api/admin/review-gateway/access-logs?outcome=denied&token_name=filter-test')
            ->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        foreach ($res->json('data') as $row) {
            $this->assertSame('denied', $row['outcome']);
        }
    }
}
