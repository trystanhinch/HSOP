<?php

namespace Tests\Feature;

use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicIntakeTalkSessionTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'hsop_job_command');
        $app['config']->set('ai.provider', 'mock');
        $app['config']->set('ai.conversational_provider', 'mock');
        $app['config']->set('public.local_default_brand_domain', 'acuteradrywall.ca');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Milestone4Seeder::class);
        RateLimiter::clear('public-intake');
        RateLimiter::clear('public-intake-talk-session');
    }

    public function test_talk_session_returns_ephemeral_secret(): void
    {
        config([
            'ai.openai.api_key' => 'test-key',
            'ai.openai.realtime_transcription_model' => 'gpt-4o-mini-transcribe',
        ]);

        Http::fake([
            'api.openai.com/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_test_secret',
                'expires_at' => 1785099000,
                'session' => ['type' => 'transcription'],
            ], 200),
        ]);

        $this->withHeaders([
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Host' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])
            ->post('/api/public/intake/talk-session')
            ->assertOk()
            ->assertJsonPath('client_secret', 'ek_test_secret')
            ->assertJsonPath('provider', 'openai')
            ->assertJsonPath('session_type', 'transcription')
            ->assertJsonPath('webrtc_url', 'https://api.openai.com/v1/realtime/calls');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'realtime/client_secrets'));
    }

    public function test_talk_session_fails_without_api_key(): void
    {
        config(['ai.openai.api_key' => null]);

        $this->withHeaders([
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Host' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])
            ->post('/api/public/intake/talk-session')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Talk is unavailable (OPENAI_API_KEY missing).']);
    }
}
