<?php

namespace Tests\Feature\LearningGateway;

use App\Models\LearningGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Milestone 6B Phase 1 — Learning AI identity isolation + kill switch.
 */
class LearningGatewayIdentityTest extends TestCase
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
        Setting::setBool(config('learning_ai.kill_switch_setting_key'), false);
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), false);
        Setting::setBool('ai_kill_switch', false);
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
            ]
        );
        $user->forceFill(['role' => 'learning_ai', 'status' => 'active'])->save();
        $plain = $user->createToken('learn-'.Str::random(6), $abilities, now()->addDays(30))->plainTextToken;

        return [$user, $plain];
    }

    private function makeRoleUser(string $role, string $emailPrefix): User
    {
        return User::create([
            'name' => $role.' '.Str::random(4),
            'email' => $emailPrefix.'-'.uniqid().'@test.local',
            'password' => Hash::make(Str::random(24)),
            'role' => $role,
            'status' => 'active',
            'sms_enabled' => false,
        ]);
    }

    private function headers(string $plain): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    public function test_learning_ai_token_can_ping(): void
    {
        [, $token] = $this->makeLearningActor();
        $this->getJson('/api/learning-gateway/ping', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('tool', 'ping')
            ->assertJsonPath('ok', true)
            ->assertJsonPath('actor_role', 'learning_ai');
    }

    public function test_issue_token_command_creates_learning_ai_principal(): void
    {
        $this->artisan('learning-ai:issue-token', [
            'name' => 'phase1-'.Str::random(4),
            '--ttl' => 14,
        ])->assertSuccessful();

        $user = User::query()->where('role', 'learning_ai')->where('email', config('learning_ai.actor_email'))->first();
        $this->assertNotNull($user);
    }

    public function test_learning_ai_cannot_access_review_gateway(): void
    {
        [, $token] = $this->makeLearningActor();
        $this->getJson('/api/review-gateway/tools/search', $this->headers($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'review_role_required');
    }

    public function test_external_review_ai_cannot_access_learning_gateway(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_31_000001_add_external_review_ai_role.php',
            '--force' => true,
        ]);
        $user = $this->makeRoleUser('external_review_ai', 'ext-review');
        $plain = $user->createToken(
            'forged',
            array_merge(config('review_gateway.abilities'), config('learning_ai.abilities')),
            now()->addDay()
        )->plainTextToken;

        $this->getJson('/api/learning-gateway/ping', $this->headers($plain))
            ->assertForbidden()
            ->assertJsonPath('code', 'learning_role_required');
    }

    public function test_ai_super_admin_cannot_access_learning_gateway(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'ai-super-admin@serviceop.system'],
            [
                'name' => 'AI Super Admin',
                'password' => Hash::make(Str::random(40)),
                'role' => 'ai_super_admin',
                'status' => 'active',
                'sms_enabled' => false,
            ]
        );
        $user->forceFill(['role' => 'ai_super_admin'])->save();
        $plain = $user->createToken('ops-forged', config('learning_ai.abilities'), now()->addDay())->plainTextToken;

        $this->getJson('/api/learning-gateway/ping', $this->headers($plain))
            ->assertForbidden()
            ->assertJsonPath('code', 'learning_role_required');
    }

    public function test_denied_attempts_are_logged(): void
    {
        $owner = $this->makeRoleUser('owner', 'owner-deny');
        $plain = $owner->createToken('auth_token')->plainTextToken;
        $before = LearningGatewayAccessLog::count();

        $this->getJson('/api/learning-gateway/ping', $this->headers($plain))->assertForbidden();

        $this->assertGreaterThan($before, LearningGatewayAccessLog::count());
        $log = LearningGatewayAccessLog::query()->orderByDesc('id')->first();
        $this->assertSame('denied', $log->outcome);
        $this->assertStringContainsString('wrong_role', (string) $log->denial_reason);
    }

    public function test_learning_kill_switch_independent_of_review_and_ai(): void
    {
        [, $learnToken] = $this->makeLearningActor();

        // Only learning kill on
        Setting::setBool(config('learning_ai.kill_switch_setting_key'), true);
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), false);
        Setting::setBool('ai_kill_switch', false);
        $this->getJson('/api/learning-gateway/ping', $this->headers($learnToken))
            ->assertForbidden()
            ->assertJsonPath('code', 'learning_gateway_kill_switch');

        // Only review kill on — learning still works
        Setting::setBool(config('learning_ai.kill_switch_setting_key'), false);
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), true);
        Setting::setBool('ai_kill_switch', false);
        $this->getJson('/api/learning-gateway/ping', $this->headers($learnToken))->assertOk();

        // Only ops AI kill on — learning still works
        Setting::setBool(config('review_gateway.kill_switch_setting_key'), false);
        Setting::setBool('ai_kill_switch', true);
        $this->getJson('/api/learning-gateway/ping', $this->headers($learnToken))->assertOk();

        Setting::setBool('ai_kill_switch', false);
    }

    public function test_learning_ai_interactive_login_blocked(): void
    {
        $password = 'LearnBlock-'.Str::random(12);
        $email = 'learning-login-'.uniqid().'@test.local';
        User::create([
            'name' => 'Learning Login Test',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'learning_ai',
            'status' => 'active',
            'sms_enabled' => false,
        ]);

        $this->postJson('/api/login', ['email' => $email, 'password' => $password])
            ->assertForbidden();
    }

    public function test_owner_admin_learning_gateway_summary(): void
    {
        $owner = $this->makeRoleUser('owner', 'owner-lg');
        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/learning-gateway/summary')
            ->assertOk()
            ->assertJsonPath('identity.role', 'learning_ai')
            ->assertJsonStructure(['calls', 'denied', 'active_token_count', 'kill_switch']);
    }

    public function test_access_logs_append_only(): void
    {
        $row = LearningGatewayAccessLog::create([
            'http_method' => 'GET',
            'path' => '/api/learning-gateway/ping',
            'outcome' => 'success',
            'trace_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $this->expectException(\LogicException::class);
        $row->update(['outcome' => 'tampered']);
    }
}
