<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\ReviewGatewayAccessLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Milestone 6A Phase 2 — read-only source-code tools (review:code-read).
 */
class ReviewGatewaySourceCodeTest extends TestCase
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
        // Repo root = parent of backend (monorepo)
        config(['review_gateway_code_scope.repository_root' => dirname(base_path())]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function tokenWithAbilities(array $abilities): array
    {
        [$user, $plain] = $this->makeExternalReviewActor($abilities);

        return [$user, $plain];
    }

    private function headers(string $plain): array
    {
        return $this->reviewAuthHeaders($plain);
    }

    public function test_1_code_read_token_can_read_allowlisted_file(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $rel = 'backend/app/Http/Middleware/EnsureReviewAiAbility.php';
        $abs = dirname(base_path()).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $this->assertFileExists($abs);
        $expectedSha = hash('sha256', file_get_contents($abs));

        $res = $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode($rel), $this->headers($token))
            ->assertOk()
            ->assertJsonPath('tool', 'source_file')
            ->assertJsonPath('tool_version', '1.0.0')
            ->assertJsonPath('path', $rel);

        $this->assertSame($expectedSha, $res->json('content_sha256'));
        $this->assertStringContainsString('EnsureReviewAiAbility', (string) $res->json('content'));
        $this->assertSame($expectedSha, hash('sha256', (string) $res->json('content')));
    }

    public function test_2_hard_excluded_env_and_secret_patterns_forbidden(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $h = $this->headers($token);

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/.env'), $h)
            ->assertForbidden()
            ->assertJsonPath('code', 'path_hard_excluded');

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/.env.example'), $h)
            ->assertForbidden();

        // Seeders (demo passwords) — not allowlisted + hard-excluded
        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/database/seeders/DemoSeeder.php'), $h)
            ->assertForbidden();

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/vendor/autoload.php'), $h)
            ->assertForbidden();
    }

    public function test_3_data_read_only_token_cannot_access_source_tools(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:read']);
        $h = $this->headers($token);

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/routes/api.php'), $h)
            ->assertForbidden()
            ->assertJsonPath('code', 'review_ability_required')
            ->assertJsonPath('required_ability', 'review:code-read');

        $this->getJson('/api/review-gateway/tools/source-search?query=ReviewGateway', $h)
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'review:code-read');

        // Inverse: code-read alone cannot call data tools
        [, $codeOnly] = $this->tokenWithAbilities(['review:code-read']);
        $this->getJson('/api/review-gateway/tools/search', $this->headers($codeOnly))
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'review:read');
    }

    public function test_4_path_traversal_rejected(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $h = $this->headers($token);

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('../../.env'), $h)
            ->assertForbidden();

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/app/../../.env'), $h)
            ->assertForbidden();

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/app/../.env'), $h)
            ->assertForbidden();
    }

    public function test_5_denied_code_reads_logged(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $before = ReviewGatewayAccessLog::count();

        $this->getJson('/api/review-gateway/tools/source-file?path='.urlencode('backend/.env'), $this->headers($token))
            ->assertForbidden();

        $this->assertGreaterThan($before, ReviewGatewayAccessLog::count());
        $log = ReviewGatewayAccessLog::query()->latest('id')->first();
        $this->assertSame('denied', $log->outcome);
        $this->assertSame('source_file', $log->tool);
        $this->assertSame('review:code-read', $log->ability);
        $this->assertNotEmpty($log->denial_reason);
    }

    public function test_6_source_search_only_allowlisted_paths(): void
    {
        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $res = $this->getJson(
            '/api/review-gateway/tools/source-search?query='.urlencode('EnsureReviewAiAbility'),
            $this->headers($token)
        )->assertOk()
            ->assertJsonPath('tool', 'source_search')
            ->assertJsonPath('tool_version', '1.0.0');

        $matches = $res->json('matches') ?? [];
        $this->assertNotEmpty($matches);
        foreach ($matches as $m) {
            $path = $m['path'] ?? '';
            $this->assertTrue(
                str_starts_with($path, 'backend/app/')
                || str_starts_with($path, 'backend/config/')
                || str_starts_with($path, 'backend/routes/')
                || str_starts_with($path, 'backend/tests/')
                || str_starts_with($path, 'backend/database/migrations/')
                || str_starts_with($path, 'docs/'),
                "Match path outside allowlist: {$path}"
            );
            $this->assertStringNotContainsString('vendor/', $path);
            $this->assertStringNotContainsString('seeders/', $path);
            $this->assertStringNotContainsString('.env', $path);
        }
        $this->assertNotEmpty($res->json('content_sha256'));
    }

    public function test_7_source_routes_are_get_only(): void
    {
        $bad = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, 'review-gateway/tools/source-file')
                && ! str_contains($uri, 'review-gateway/tools/source-search')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'GET'], true)) {
                    continue;
                }
                $bad[] = strtoupper($method).' '.$uri;
            }
        }
        $this->assertSame([], $bad, 'Write verbs under source tools: '.implode(', ', $bad));

        [, $token] = $this->tokenWithAbilities(['review:code-read']);
        $h = $this->headers($token);
        $this->postJson('/api/review-gateway/tools/source-file', ['path' => 'backend/routes/api.php'], $h)->assertStatus(405);
        $this->putJson('/api/review-gateway/tools/source-search', [], $h)->assertStatus(405);
        $this->deleteJson('/api/review-gateway/tools/source-file', $h)->assertStatus(405);
    }
}
