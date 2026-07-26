<?php

namespace App\Services\Ai;

use App\Models\AiActionLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Speech-to-text via OpenAI Whisper — record-a-note, not live voice AI.
 */
class OpenAiTranscriptionService
{
    /**
     * @return array{text: string, provider: string, model: string}
     */
    public function transcribe(UploadedFile $audio, ?int $brandId = null): array
    {
        $apiKey = config('ai.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('Transcription is unavailable (OPENAI_API_KEY missing).');
        }

        $model = (string) config('ai.openai.whisper_model', 'whisper-1');
        $timeout = (int) config('ai.openai.timeout', 60);

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->attach(
                    'file',
                    file_get_contents($audio->getRealPath()),
                    $audio->getClientOriginalName() ?: 'voice-note.webm'
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);
        } catch (Throwable $e) {
            Log::warning('Whisper transcription request failed', [
                'message' => $e->getMessage(),
                'brand_id' => $brandId,
            ]);
            throw new RuntimeException('Could not reach transcription service. Please type instead.');
        }

        if (! $response->successful()) {
            Log::warning('Whisper transcription HTTP error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
                'brand_id' => $brandId,
            ]);
            throw new RuntimeException('Transcription failed. Please type your message instead.');
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('No speech was detected. Please try again or type instead.');
        }

        try {
            $actor = \App\Models\User::aiSuperAdmin();
            AiActionLog::create([
                'trigger_event' => 'openai_whisper',
                'actor_id' => $actor?->id,
                'data_viewed' => [
                    'action' => 'voice_note_transcription',
                    'brand_id' => $brandId,
                    'bytes' => $audio->getSize(),
                    'mime' => $audio->getMimeType(),
                ],
                'decision' => 'completed',
                'action_taken' => 'voice_note_transcription',
                'message_sent' => mb_substr($text, 0, 240),
                'recipient' => null,
                'status_before' => null,
                'status_after' => null,
                'rule_applied' => 'openai_whisper',
                'required_human_approval' => false,
                'error' => null,
            ]);
        } catch (Throwable) {
            // Logging must not block the visitor path.
        }

        return [
            'text' => $text,
            'provider' => 'openai',
            'model' => $model,
        ];
    }
}
