<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\AiConversationLog;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\ReviewGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReviewGateway\SensitiveDataGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Milestone 6A Phase 1 — External Review AI gateway foundation.
 */
class ReviewGatewayFoundationTest extends TestCase
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

    /**
     * @return array{0: User, 1: string}
     */
    private function makeReviewActor(?array $abilities = null): array
    {
        [$user, $plain] = $this->makeExternalReviewActor($abilities);

        return [$user, $plain];
    }

    private function authHeaders(string $plain): array
    {
        return $this->reviewAuthHeaders($plain);
    }

    public function test_1_valid_review_token_can_call_all_three_tools(): void
    {
        [, $token] = $this->makeReviewActor();
        $lead = Lead::create([
            'contact_name' => 'Review Lead',
            'phone' => '6045550100',
            'email' => 'review-lead-'.uniqid().'@example.com',
            'address' => '100 Vancouver St',
            'service_category' => 'drywall_paint',
            'status' => 'new',
            'is_test_data' => false,
        ]);
        $brand = Brand::query()->first() ?? Brand::create([
            'company_name' => 'Review Test Brand',
            'domain' => 'review-test-'.uniqid().'.example',
            'slug' => 'review-test-'.uniqid(),
            'status' => 'active',
        ]);
        $session = IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => Str::random(40),
            'conversation_state' => [],
            'expires_at' => now()->addDay(),
        ]);
        $conv = AiConversationLog::create([
            'intake_session_id' => $session->id,
            'lead_id' => $lead->id,
            'turn_number' => 1,
            'role' => 'assistant',
            'content' => 'Hello from intake',
            'content_preview' => 'Hello',
            'trace_id' => (string) Str::uuid(),
            'tool_calls' => [['name' => 'noop']],
            'tool_results' => [['ok' => true]],
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'created_at' => now(),
        ]);

        $h = $this->authHeaders($token);

        $this->getJson('/api/review-gateway/tools/lead-journey/'.$lead->id, $h)
            ->assertOk()
            ->assertJsonPath('tool', 'lead_journey')
            ->assertJsonPath('tool_version', config('review_gateway.tool_versions.lead_journey'))
            ->assertJsonPath('lead.id', $lead->id);

        $this->getJson('/api/review-gateway/tools/search?service_type=drywall_paint&region=Vancouver', $h)
            ->assertOk()
            ->assertJsonPath('tool', 'search')
            ->assertJsonStructure(['tool_version', 'data', 'meta']);

        $this->getJson('/api/review-gateway/tools/ai-conversation-log/'.$conv->id, $h)
            ->assertOk()
            ->assertJsonPath('tool', 'ai_conversation_log')
            ->assertJsonPath('turns.0.ai_model', 'gpt-4o-mini')
            ->assertJsonPath('turns.0.tool_calls.0.name', 'noop');
    }

    public function test_2_token_without_review_abilities_is_forbidden(): void
    {
        [$user] = $this->makeReviewActor();
        // Authenticated owner-style token with no review abilities — blocked on role first
        $owner = User::create([
            'name' => 'Owner No Review',
            'email' => 'owner-noreview-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plain = $owner->createToken('auth_token')->plainTextToken;

        $before = ReviewGatewayAccessLog::count();
        $this->getJson('/api/review-gateway/tools/search', $this->authHeaders($plain))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_role_required');
        $this->assertGreaterThan($before, ReviewGatewayAccessLog::count());
        $this->assertSame('denied', ReviewGatewayAccessLog::orderByDesc('id')->value('outcome'));

        // external_review_ai with empty abilities → ability denial
        $plain2 = $user->createToken('no-abilities', [])->plainTextToken;
        $this->getJson('/api/review-gateway/tools/search', $this->authHeaders($plain2))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_ability_required');
    }

    public function test_3_review_gateway_write_routes_are_narrow_evidence_exception(): void
    {
        // Deliberate Phase 5 exception: only these two POSTs under api/review-gateway.
        $allowedPost = [
            'api/review-gateway/tools/evaluation-run',
            'api/review-gateway/tools/evaluation-finding',
        ];

        $bad = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/review-gateway')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'GET'], true)) {
                    continue;
                }
                if ($method === 'POST' && in_array($uri, $allowedPost, true)) {
                    continue;
                }
                $bad[] = strtoupper($method).' '.$uri;
            }
        }
        $this->assertSame([], $bad, 'Unexpected non-GET methods under api/review-gateway: '.implode(', ', $bad));

        // Ensure both allowed POST paths are actually registered.
        $registeredPosts = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (in_array($uri, $allowedPost, true) && in_array('POST', $route->methods(), true)) {
                $registeredPosts[] = $uri;
            }
        }
        sort($registeredPosts);
        $expected = $allowedPost;
        sort($expected);
        $this->assertSame($expected, $registeredPosts);

        [, $token] = $this->makeReviewActor();
        $h = $this->authHeaders($token);
        // Existing GET tools must still reject writes
        $this->postJson('/api/review-gateway/tools/search', [], $h)->assertStatus(405);
        $this->putJson('/api/review-gateway/tools/search', [], $h)->assertStatus(405);
        $this->patchJson('/api/review-gateway/tools/search', [], $h)->assertStatus(405);
        $this->deleteJson('/api/review-gateway/tools/search', $h)->assertStatus(405);
    }

    public function test_4_denied_attempts_are_recorded_in_access_logs(): void
    {
        $owner = User::create([
            'name' => 'Deny Logger',
            'email' => 'deny-log-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'owner',
            'status' => 'active',
        ]);
        $plain = $owner->createToken('auth_token')->plainTextToken;

        $this->getJson('/api/review-gateway/tools/lead-journey/1', $this->authHeaders($plain))
            ->assertForbidden();

        $log = ReviewGatewayAccessLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('denied', $log->outcome);
        $this->assertSame(403, $log->http_status);
        $this->assertNotEmpty($log->trace_id);
        $this->assertStringContainsString('wrong_role', (string) $log->denial_reason);
    }

    public function test_5_sensitive_field_denylist_never_appears_in_responses(): void
    {
        [, $token] = $this->makeReviewActor();
        $lead = Lead::create([
            'contact_name' => 'Secret Lead',
            'phone' => '6045550199',
            'email' => 'secret-'.uniqid().'@example.com',
            'address' => '1 Test Ave',
            'service_category' => 'insulation',
            'status' => 'new',
            'customer_portal_token' => Str::random(64),
            'is_test_data' => false,
        ]);

        $res = $this->getJson('/api/review-gateway/tools/lead-journey/'.$lead->id, $this->authHeaders($token))
            ->assertOk();
        $json = $res->json();
        $guard = app(SensitiveDataGuard::class);
        $hits = $guard->findDeniedKeys($json);
        $this->assertSame([], $hits, 'Denied keys leaked: '.implode(', ', $hits));

        // Inject a denylist key into a scrubbed payload and confirm guard removes it.
        $scrubbed = $guard->scrub([
            'ok' => true,
            'password' => 'hash',
            'nested' => ['api_key' => 'sk-proj-SHOULDNOTLEAK', 'safe' => 1],
            'note' => 'contains sk_live_ABCDEFG1234567890extra',
        ]);
        $this->assertArrayNotHasKey('password', $scrubbed);
        $this->assertArrayNotHasKey('api_key', $scrubbed['nested']);
        $this->assertSame(1, $scrubbed['nested']['safe']);
        $this->assertStringContainsString('[REDACTED]', $scrubbed['note']);
        $this->assertSame([], $guard->findDeniedKeys($scrubbed));
    }

    public function test_6_kill_switch_blocks_even_with_valid_token(): void
    {
        [, $token] = $this->makeReviewActor();
        $key = config('review_gateway.kill_switch_setting_key');
        Setting::setBool($key, true);

        $before = ReviewGatewayAccessLog::count();
        $this->getJson('/api/review-gateway/tools/search', $this->authHeaders($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_gateway_kill_switch');
        $this->assertGreaterThan($before, ReviewGatewayAccessLog::count());
        $this->assertSame('denied', ReviewGatewayAccessLog::orderByDesc('id')->value('outcome'));
        $this->assertSame('review_gateway_kill_switch', ReviewGatewayAccessLog::orderByDesc('id')->value('denial_reason'));

        Setting::setBool($key, false);
        $this->getJson('/api/review-gateway/tools/search', $this->authHeaders($token))->assertOk();
    }

    public function test_7_access_logs_are_append_only(): void
    {
        $row = ReviewGatewayAccessLog::create([
            'actor_user_id' => null,
            'personal_access_token_id' => null,
            'token_name' => 't',
            'ability' => 'review:read',
            'tool' => 'search',
            'http_method' => 'GET',
            'path' => '/api/review-gateway/tools/search',
            'parameters' => [],
            'response_record_count' => 0,
            'outcome' => 'success',
            'http_status' => 200,
            'ip' => '127.0.0.1',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $row->update(['outcome' => 'tampered']);
    }

    public function test_8_access_logs_cannot_be_deleted(): void
    {
        $row = ReviewGatewayAccessLog::create([
            'http_method' => 'GET',
            'path' => '/api/review-gateway/tools/search',
            'outcome' => 'success',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $row->delete();
    }
}
