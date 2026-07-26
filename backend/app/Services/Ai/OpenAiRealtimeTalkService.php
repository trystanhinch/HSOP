<?php

namespace App\Services\Ai;

use App\Models\AiActionLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Mint ephemeral OpenAI Realtime transcription credentials for browser clients.
 * Transcription-only — no spoken AI output (Milestone 6 stays out of scope).
 */
class OpenAiRealtimeTalkService
{
    /**
     * @return array{
     *   client_secret: string,
     *   expires_at: int|null,
     *   provider: string,
     *   model: string,
     *   webrtc_url: string,
     *   session_type: string
     * }
     */
    public function createEphemeralSession(?int $brandId = null): array
    {
        $apiKey = config('ai.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('Talk is unavailable (OPENAI_API_KEY missing).');
        }

        $model = (string) config('ai.openai.realtime_transcription_model', 'gpt-4o-mini-transcribe');
        $timeout = max(20, (int) config('ai.openai.timeout', 20));

        $payload = [
            'expires_after' => [
                'anchor' => 'created_at',
                'seconds' => 60,
            ],
            'session' => [
                'type' => 'transcription',
                'audio' => [
                    'input' => [
                        'transcription' => [
                            'model' => $model,
                            'language' => 'en',
                        ],
                        // Interim captions after brief pauses while listening;
                        // the UI only commits a chat turn on explicit Stop.
                        'turn_detection' => [
                            'type' => 'server_vad',
                            'threshold' => 0.5,
                            'prefix_padding_ms' => 300,
                            'silence_duration_ms' => 700,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post('https://api.openai.com/v1/realtime/client_secrets', $payload);
        } catch (Throwable $e) {
            Log::warning('Realtime talk session mint failed', [
                'message' => $e->getMessage(),
                'brand_id' => $brandId,
            ]);
            throw new RuntimeException('Could not start live transcription. Please type instead.');
        }

        if (! $response->successful()) {
            Log::warning('Realtime talk session mint HTTP error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 600),
                'brand_id' => $brandId,
            ]);
            throw new RuntimeException('Live transcription is temporarily unavailable. Please type instead.');
        }

        $secret = (string) ($response->json('value')
            ?? $response->json('client_secret.value')
            ?? '');
        $expiresAt = $response->json('expires_at')
            ?? $response->json('client_secret.expires_at');

        if ($secret === '') {
            throw new RuntimeException('Live transcription session was incomplete. Please type instead.');
        }

        try {
            $actor = \App\Models\User::aiSuperAdmin();
            AiActionLog::create([
                'trigger_event' => 'openai_realtime_talk_session',
                'actor_id' => $actor?->id,
                'data_viewed' => [
                    'action' => 'mint_ephemeral_transcription_session',
                    'brand_id' => $brandId,
                    'model' => $model,
                ],
                'decision' => 'completed',
                'action_taken' => 'mint_ephemeral_transcription_session',
                'message_sent' => null,
                'recipient' => null,
                'status_before' => null,
                'status_after' => null,
                'rule_applied' => 'openai_realtime_transcription',
                'required_human_approval' => false,
                'error' => null,
            ]);
        } catch (Throwable) {
            // Logging must not block the visitor path.
        }

        return [
            'client_secret' => $secret,
            'expires_at' => is_numeric($expiresAt) ? (int) $expiresAt : null,
            'provider' => 'openai',
            'model' => $model,
            'webrtc_url' => 'https://api.openai.com/v1/realtime/calls',
            'session_type' => 'transcription',
        ];
    }
}
