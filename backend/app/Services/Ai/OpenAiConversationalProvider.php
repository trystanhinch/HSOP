<?php

namespace App\Services\Ai;

use App\Contracts\ConversationalAiProviderInterface;
use App\Models\AiActionLog;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\Brands\BrandPromptTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real OpenAI multi-turn intake chat with tool-based field extraction + streaming.
 * Sibling to OpenAiProvider (one-shot classify) — independently swappable.
 */
class OpenAiConversationalProvider implements ConversationalAiProviderInterface
{
    use TracksOpenAiUsage;

    private const MAX_TOOL_ROUNDS = 3;

    public function __construct(
        private AiActionAuthorizer $authorizer,
        private MockConversationalAiProvider $mockFallback,
    ) {}

    public function respond(array $history, array $collected = [], array $context = []): array
    {
        $final = null;
        foreach ($this->streamRespond($history, $collected, $context) as $event) {
            if (($event['event'] ?? '') === 'done' || ($event['event'] ?? '') === 'error') {
                $final = $event;
            }
        }

        if (! is_array($final)) {
            return [
                'reply' => 'Something went wrong. You can still submit whatever details you have.',
                'collected' => $collected,
                'ready_to_submit' => $this->ready($collected),
                'provider' => 'openai_empty',
                'usage' => null,
                'error' => 'empty_stream',
                'needs_manual_review' => true,
            ];
        }

        return [
            'reply' => (string) ($final['reply'] ?? $final['message'] ?? ''),
            'collected' => is_array($final['collected'] ?? null) ? $final['collected'] : $collected,
            'ready_to_submit' => (bool) ($final['ready_to_submit'] ?? $this->ready($collected)),
            'provider' => (string) ($final['provider'] ?? 'openai'),
            'usage' => $final['usage'] ?? null,
            'error' => $final['message'] ?? null,
            'needs_manual_review' => (bool) ($final['needs_manual_review'] ?? false),
        ];
    }

    public function streamRespond(array $history, array $collected = [], array $context = []): \Generator
    {
        $mode = $this->authorizer->getModuleMode('public_intake');
        $needsReview = $mode === 'suggestion';

        if (! $this->authorizer->isAiEnabled()) {
            yield from $this->yieldMockFallback(
                $history,
                $collected,
                $context,
                'mock_kill_switch',
                true
            );

            return;
        }

        $apiKey = config('ai.openai.api_key');
        if (! $apiKey) {
            yield from $this->yieldMockFallback(
                $history,
                $collected,
                $context,
                'mock_missing_api_key',
                true
            );

            return;
        }

        $company = (string) ($context['company_name'] ?? 'our team');
        $services = is_array($context['service_categories'] ?? null) ? $context['service_categories'] : [];
        $allowedKeys = array_values(array_filter(array_map(
            static fn ($c) => is_array($c) ? (string) ($c['key'] ?? '') : '',
            $services
        )));

        $systemPrompt = (string) ($context['system_prompt'] ?? '');
        if ($systemPrompt === '' && ! empty($context['prompt_vars'])) {
            $systemPrompt = BrandPromptTemplate::render(
                (string) config('public.conversational_system_prompt'),
                $context['prompt_vars']
            );
        }
        $systemPrompt .= "\n\n".$this->extractionInstructions($services, $collected);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        foreach ($history as $msg) {
            $role = ($msg['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($msg['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        $workingCollected = $collected;
        $usageTotal = null;
        $model = (string) config('ai.openai.model', 'gpt-4o-mini');
        $timeout = (int) config('ai.openai.timeout', 60);
        $reply = '';
        $provider = 'openai';

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $streamText = true; // stream assistant text whenever present
                $assistantMessage = null;
                $roundUsage = null;

                foreach ($this->streamChatCompletion(
                    $apiKey,
                    $model,
                    $timeout,
                    $messages,
                    $this->toolDefinitions($allowedKeys),
                ) as $event) {
                    if (($event['event'] ?? '') === 'delta') {
                        if ($streamText) {
                            yield $event;
                        }

                        continue;
                    }
                    if (($event['event'] ?? '') === '_complete') {
                        $assistantMessage = $event['message'];
                        $roundUsage = $event['usage'] ?? null;
                    }
                }

                $usageTotal = $this->mergeUsage($usageTotal, $roundUsage);
                if (! is_array($assistantMessage)) {
                    throw new \RuntimeException('OpenAI stream ended without a message');
                }

                $messages[] = $assistantMessage;

                $toolCalls = $assistantMessage['tool_calls'] ?? [];
                if ($toolCalls === []) {
                    $reply = trim((string) ($assistantMessage['content'] ?? ''));
                    break;
                }

                foreach ($toolCalls as $call) {
                    $name = $call['function']['name'] ?? '';
                    $args = json_decode($call['function']['arguments'] ?? '{}', true);
                    if (! is_array($args)) {
                        $args = [];
                    }

                    if ($name === 'update_intake_fields') {
                        $workingCollected = $this->applyFieldUpdates($workingCollected, $args, $allowedKeys, $services);
                        yield [
                            'event' => 'collected',
                            'collected' => $workingCollected,
                        ];
                        $toolResult = [
                            'ok' => true,
                            'collected' => $workingCollected,
                            'missing' => $this->missingFields($workingCollected),
                            'ready_to_submit' => $this->ready($workingCollected),
                        ];
                    } else {
                        $toolResult = ['ok' => false, 'error' => 'unknown_tool'];
                    }

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'] ?? ('call_'.$round),
                        'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES),
                    ];
                }
            }

            if ($reply === '') {
                foreach ($this->streamChatCompletion(
                    $apiKey,
                    $model,
                    $timeout,
                    $messages,
                    tools: [],
                ) as $event) {
                    if (($event['event'] ?? '') === 'delta') {
                        yield $event;

                        continue;
                    }
                    if (($event['event'] ?? '') === '_complete') {
                        $usageTotal = $this->mergeUsage($usageTotal, $event['usage'] ?? null);
                        $reply = trim((string) (($event['message']['content'] ?? '') ?: ''));
                    }
                }
            }

            if ($reply === '') {
                $reply = $this->ready($workingCollected)
                    ? "Thanks — I have what I need for {$company}. You can submit your request whenever you're ready."
                    : "Thanks — could you share a bit more so we can create your request?";
            }

            $this->logTurn($history, $workingCollected, $reply, $provider, $context, $systemPrompt, $usageTotal, null);

            yield [
                'event' => 'done',
                'reply' => $reply,
                'collected' => $workingCollected,
                'ready_to_submit' => $this->ready($workingCollected),
                'provider' => $provider,
                'usage' => $usageTotal,
                'needs_manual_review' => $needsReview || ! $this->ready($workingCollected),
            ];
        } catch (Throwable $e) {
            Log::warning('OpenAI conversational intake failed', [
                'error' => $e->getMessage(),
                'brand_id' => $context['brand_id'] ?? null,
            ]);

            $this->logTurn($history, $workingCollected, null, 'openai_error', $context, $systemPrompt, $usageTotal, $e->getMessage());

            // Graceful fallback — keep chat usable with mock extraction
            yield from $this->yieldMockFallback(
                $history,
                $workingCollected,
                $context,
                'mock_openai_fallback',
                true,
                'The assistant hit a temporary issue. You can keep going or submit what you have — a team member will follow up.'
            );
        }
    }

    /**
     * @param  list<array{key: string, label: string, keywords?: list<string>}>  $services
     * @param  array<string, mixed>  $collected
     */
    private function extractionInstructions(array $services, array $collected): string
    {
        $labels = [];
        foreach ($services as $s) {
            $labels[] = ($s['key'] ?? '').' = '.($s['label'] ?? '');
        }
        $serviceLine = $labels !== [] ? implode('; ', $labels) : 'use brand service keys only';

        return "Use the update_intake_fields tool whenever the visitor provides or corrects "
            ."contact_name, phone, email, address, project_description, service_category, or urgency. "
            ."service_category must be one of: {$serviceLine}. "
            ."Do not invent contact details. Ask one clear question at a time. "
            ."Already collected JSON: ".json_encode($collected, JSON_UNESCAPED_SLASHES).".";
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return list<array<string, mixed>>
     */
    private function toolDefinitions(array $allowedKeys): array
    {
        $categoryEnum = $allowedKeys !== [] ? $allowedKeys : ['general'];

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_intake_fields',
                    'description' => 'Save structured intake fields extracted from the visitor message. Only include fields you are confident about.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'contact_name' => ['type' => 'string'],
                            'phone' => ['type' => 'string'],
                            'email' => ['type' => 'string'],
                            'address' => ['type' => 'string', 'description' => 'Job address, city, or service area'],
                            'project_description' => ['type' => 'string'],
                            'service_category' => [
                                'type' => 'string',
                                'enum' => $categoryEnum,
                            ],
                            'urgency' => [
                                'type' => 'string',
                                'enum' => ['normal', 'high'],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Stream one OpenAI chat completion. Yields delta events, then `_complete`.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamChatCompletion(
        string $apiKey,
        string $model,
        int $timeout,
        array $messages,
        array $tools,
    ): \Generator {
        $payload = [
            'model' => $model,
            'temperature' => 0.4,
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($apiKey)
            ->withOptions(['stream' => true])
            ->timeout($timeout)
            ->acceptJson()
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if (! $response->successful()) {
            // body may be a stream — read a small error payload
            $errBody = '';
            try {
                $errBody = $response->body();
            } catch (Throwable) {
                $errBody = 'stream_error';
            }
            throw new \RuntimeException('OpenAI HTTP '.$response->status().': '.$errBody);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';
        $toolCalls = [];
        $usageRaw = null;

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    continue;
                }
                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }
                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usageRaw = $json['usage'];
                }
                $choice = $json['choices'][0] ?? null;
                if (! is_array($choice)) {
                    continue;
                }
                $delta = $choice['delta'] ?? [];
                if (! is_array($delta)) {
                    continue;
                }

                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    $content .= $delta['content'];
                    yield ['event' => 'delta', 'text' => $delta['content']];
                }

                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = (int) ($tc['index'] ?? 0);
                        if (! isset($toolCalls[$idx])) {
                            $toolCalls[$idx] = [
                                'id' => $tc['id'] ?? ('call_'.$idx),
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (! empty($tc['id'])) {
                            $toolCalls[$idx]['id'] = $tc['id'];
                        }
                        if (! empty($tc['function']['name'])) {
                            $toolCalls[$idx]['function']['name'] = $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }
        }

        $message = ['role' => 'assistant', 'content' => $content !== '' ? $content : null];
        if ($toolCalls !== []) {
            ksort($toolCalls);
            $message['tool_calls'] = array_values($toolCalls);
            if ($message['content'] === '') {
                $message['content'] = null;
            }
        }

        yield [
            'event' => '_complete',
            'message' => $message,
            'usage' => $usageRaw ? $this->buildUsagePayload($usageRaw, $model) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $args
     * @param  list<string>  $allowedKeys
     * @param  list<array{key: string, label: string, keywords?: list<string>}>  $services
     * @return array<string, mixed>
     */
    private function applyFieldUpdates(array $collected, array $args, array $allowedKeys, array $services): array
    {
        $out = $collected;
        foreach (['contact_name', 'phone', 'email', 'address', 'project_description', 'urgency'] as $key) {
            if (! array_key_exists($key, $args)) {
                continue;
            }
            $val = is_string($args[$key]) ? trim($args[$key]) : $args[$key];
            if ($val === null || $val === '') {
                continue;
            }
            $out[$key] = $val;
        }

        if (! empty($args['service_category'])) {
            $key = (string) $args['service_category'];
            if ($allowedKeys === [] || in_array($key, $allowedKeys, true)) {
                $out['service_category'] = $key;
            } else {
                // Map label → key
                foreach ($services as $s) {
                    if (strcasecmp((string) ($s['label'] ?? ''), $key) === 0) {
                        $out['service_category'] = $s['key'];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @return list<string>
     */
    private function missingFields(array $collected): array
    {
        $required = ['service_category', 'contact_name', 'phone', 'project_description'];
        $missing = [];
        foreach ($required as $key) {
            if (empty($collected[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $collected
     */
    private function ready(array $collected): bool
    {
        return $this->missingFields($collected) === [];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $context
     * @return \Generator<int, array<string, mixed>>
     */
    private function yieldMockFallback(
        array $history,
        array $collected,
        array $context,
        string $providerTag,
        bool $needsReview,
        ?string $prefixMessage = null,
    ): \Generator {
        $result = $this->mockFallback->respond($history, $collected, $context);
        $reply = $prefixMessage
            ? $prefixMessage.' '.(string) $result['reply']
            : (string) $result['reply'];

        if ($reply !== '') {
            yield ['event' => 'delta', 'text' => $reply];
        }
        yield [
            'event' => 'collected',
            'collected' => $result['collected'],
        ];
        yield [
            'event' => 'done',
            'reply' => $reply,
            'collected' => $result['collected'],
            'ready_to_submit' => $result['ready_to_submit'],
            'provider' => $providerTag,
            'usage' => null,
            'needs_manual_review' => $needsReview,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $usage
     */
    private function logTurn(
        array $history,
        array $collected,
        ?string $reply,
        string $provider,
        array $context,
        string $systemPrompt,
        ?array $usage,
        ?string $error,
    ): void {
        try {
            AiActionLog::create([
                'trigger_event' => 'public_intake_chat',
                'actor_id' => User::aiSuperAdmin()?->id,
                'data_viewed' => [
                    'history_turns' => count($history),
                    'collected_keys' => array_keys($collected),
                    'provider' => $provider,
                    'brand_id' => $context['brand_id'] ?? null,
                    'company_name' => $context['company_name'] ?? null,
                    'system_prompt_preview' => $systemPrompt !== '' ? mb_strimwidth($systemPrompt, 0, 180, '…') : null,
                    'usage' => $usage,
                    'module_mode' => $this->authorizer->getModuleMode('public_intake'),
                ],
                'decision' => $error ? 'failed' : 'completed',
                'action_taken' => 'conversational_respond',
                'message_sent' => $reply ? mb_strimwidth($reply, 0, 200, '…') : null,
                'recipient' => null,
                'status_before' => null,
                'status_after' => null,
                'rule_applied' => 'public_intake_openai',
                'required_human_approval' => false,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write public intake AiActionLog', ['error' => $e->getMessage()]);
        }
    }
}
