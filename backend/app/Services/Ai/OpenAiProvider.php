<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\AiActionLog;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\LeadIntake\KeywordCategoryClassifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAiProvider implements AiProviderInterface
{
    public function __construct(
        private KeywordCategoryClassifier $keywordClassifier,
        private AiActionAuthorizer $authorizer,
        private MockAiProvider $mockProvider,
    ) {}

    public function classifyLead(array $leadData): array
    {
        if (! $this->authorizer->isAiEnabled()) {
            $fallback = $this->mockProvider->classifyLead($leadData);
            $fallback['provider'] = 'mock_kill_switch';
            $fallback['flags']['ambiguous_service'] = ($fallback['service_category'] ?? null) === null;

            return $fallback;
        }

        $apiKey = config('ai.openai.api_key');
        if (! $apiKey) {
            return $this->fallbackClassify($leadData, 'missing_api_key', 'OPENAI_API_KEY is not configured');
        }

        try {
            $model = config('ai.openai.model', 'gpt-4o-mini');
            $timeout = (int) config('ai.openai.timeout', 20);

            $prompt = $this->buildClassifyPrompt($leadData);

            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You classify home-service leads into exactly one category: drywall_paint or insulation. Respond with JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                return $this->fallbackClassify(
                    $leadData,
                    'http_'.$response->status(),
                    'OpenAI HTTP '.$response->status().': '.$response->body()
                );
            }

            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? '';
            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                return $this->fallbackClassify($leadData, 'invalid_json', 'OpenAI returned non-JSON content');
            }

            $category = $parsed['service_category'] ?? null;
            if (! in_array($category, ['drywall_paint', 'insulation', null], true)) {
                $category = null;
            }

            $usage = $json['usage'] ?? [];
            $usagePayload = $this->buildUsagePayload($usage, $model);
            $this->logUsage('classify_lead', $leadData, $usagePayload, null);

            $confidence = (float) ($parsed['confidence'] ?? ($category ? 0.8 : 0.2));

            return [
                'service_category' => $category,
                'urgency' => in_array($parsed['urgency'] ?? 'normal', ['normal', 'high'], true)
                    ? $parsed['urgency']
                    : 'normal',
                'flags' => [
                    'ambiguous_service' => $category === null,
                    'high_urgency' => ($parsed['urgency'] ?? '') === 'high',
                ],
                'confidence' => [
                    'service_category' => $confidence,
                ],
                'source_label' => $leadData['source_label']
                    ?? $this->keywordClassifier->extractSourceLabel((string) ($leadData['subject'] ?? ''), $leadData),
                'provider' => 'openai',
                'usage' => $usagePayload,
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAI classifyLead failed', ['error' => $e->getMessage()]);

            return $this->fallbackClassify($leadData, 'exception', $e->getMessage());
        }
    }

    public function summarizeLead(array $leadData): string
    {
        if (! $this->authorizer->isAiEnabled() || ! config('ai.openai.api_key')) {
            return $this->mockProvider->summarizeLead($leadData);
        }

        try {
            $model = config('ai.openai.model', 'gpt-4o-mini');
            $timeout = (int) config('ai.openai.timeout', 20);

            $response = Http::withToken(config('ai.openai.api_key'))
                ->timeout($timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Summarize the lead in one short sentence for a project manager. No markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode($leadData, JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                $this->logUsage('summarize_lead', $leadData, null, 'HTTP '.$response->status());

                return $this->mockProvider->summarizeLead($leadData);
            }

            $json = $response->json();
            $summary = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
            $usagePayload = $this->buildUsagePayload($json['usage'] ?? [], $model);
            $this->logUsage('summarize_lead', $leadData, $usagePayload, null);

            return $summary !== '' ? $summary : $this->mockProvider->summarizeLead($leadData);
        } catch (Throwable $e) {
            Log::warning('OpenAI summarizeLead failed', ['error' => $e->getMessage()]);
            $this->logUsage('summarize_lead', $leadData, null, $e->getMessage());

            return $this->mockProvider->summarizeLead($leadData);
        }
    }

    /**
     * @param  array<string, mixed>  $leadData
     * @return array<string, mixed>
     */
    private function fallbackClassify(array $leadData, string $reason, string $error): array
    {
        $fallback = $this->mockProvider->classifyLead($leadData);
        $fallback['provider'] = 'mock_fallback';
        $fallback['flags']['ambiguous_service'] = true;
        $fallback['openai_error'] = $error;
        $fallback['openai_fallback_reason'] = $reason;
        $this->logUsage('classify_lead', $leadData, null, $error);

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $leadData
     */
    private function buildClassifyPrompt(array $leadData): string
    {
        return <<<PROMPT
Classify this home-service lead into exactly one service_category:
- "drywall_paint" if drywall, paint, finishing, popcorn ceiling, or similar
- "insulation" if insulation, spray foam, blown-in, batt, etc.
- null if genuinely ambiguous

Also set urgency to "normal" or "high".

Return JSON:
{"service_category":"drywall_paint"|"insulation"|null,"urgency":"normal"|"high","confidence":0.0-1.0,"reason":"..."}

Lead data:
PROMPT.json_encode([
            'subject' => $leadData['subject'] ?? null,
            'source_label' => $leadData['source_label'] ?? null,
            'source_website' => $leadData['source_website'] ?? null,
            'service_requested' => $leadData['service_requested'] ?? null,
            'project_description' => $leadData['project_description'] ?? null,
            'email_format' => $leadData['email_format'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function buildUsagePayload(array $usage, string $model): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($prompt + $completion));

        $inputRate = (float) config('ai.openai.cost_per_1m_input_tokens', 0.15);
        $outputRate = (float) config('ai.openai.cost_per_1m_output_tokens', 0.60);
        $estimated = ($prompt / 1_000_000 * $inputRate) + ($completion / 1_000_000 * $outputRate);

        return [
            'model' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'estimated_cost_usd' => round($estimated, 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $leadData
     * @param  array<string, mixed>|null  $usage
     */
    private function logUsage(string $action, array $leadData, ?array $usage, ?string $error): void
    {
        try {
            $actor = User::aiSuperAdmin();
            if (! $actor) {
                return;
            }

            AiActionLog::create([
                'trigger_event' => 'openai_api',
                'actor_id' => $actor->id,
                'data_viewed' => [
                    'action' => $action,
                    'lead_contact' => $leadData['contact_name'] ?? null,
                    'subject' => $leadData['subject'] ?? null,
                    'usage' => $usage,
                ],
                'decision' => $error ? 'failed' : 'completed',
                'action_taken' => $action,
                'message_sent' => null,
                'recipient' => null,
                'status_before' => null,
                'status_after' => null,
                'rule_applied' => 'openai_provider',
                'required_human_approval' => false,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write OpenAI usage AiActionLog', ['error' => $e->getMessage()]);
        }
    }
}
