<?php

namespace Tests\Feature;

use Database\Seeders\Milestone4Seeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicIntakeTranscribeTest extends TestCase
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
        RateLimiter::clear('public-intake-transcribe');
    }

    public function test_transcribe_returns_whisper_text(): void
    {
        config([
            'ai.openai.api_key' => 'test-key',
            'ai.openai.whisper_model' => 'whisper-1',
        ]);

        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Ceiling water stain about two feet wide',
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('note.webm', 20, 'audio/webm');

        $this->withHeaders([
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Host' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])
            ->post('/api/public/intake/transcribe', ['audio' => $file])
            ->assertOk()
            ->assertJsonPath('text', 'Ceiling water stain about two feet wide')
            ->assertJsonPath('provider', 'openai');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
    }

    public function test_transcribe_fails_gracefully_without_api_key(): void
    {
        config(['ai.openai.api_key' => null]);

        $file = UploadedFile::fake()->create('note.webm', 20, 'audio/webm');

        $this->withHeaders([
            'X-Brand-Domain' => 'acuteradrywall.ca',
            'Host' => 'acuteradrywall.ca',
            'Accept' => 'application/json',
        ])
            ->post('/api/public/intake/transcribe', ['audio' => $file])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Transcription is unavailable (OPENAI_API_KEY missing).']);
    }
}
