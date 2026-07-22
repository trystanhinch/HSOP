<?php

namespace App\Services\CommandCenter;

use App\Models\AiActionLog;
use App\Models\AiCommandMessage;
use App\Models\AiCommandSession;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CommandCenterService
{
    public function __construct(
        private CommandCenterQueryService $queries,
        private CommandCenterActionService $actions,
        private AiActionAuthorizer $authorizer,
    ) {}

    public function getOrCreateSession(User $owner, ?int $sessionId = null): AiCommandSession
    {
        if ($sessionId) {
            $session = AiCommandSession::where('user_id', $owner->id)->findOrFail($sessionId);

            return $session;
        }

        return AiCommandSession::create([
            'user_id' => $owner->id,
            'title' => 'Command Center',
            'last_message_at' => now(),
        ]);
    }

    /**
     * @return array{session: AiCommandSession, user_message: AiCommandMessage, assistant_message: AiCommandMessage}
     */
    public function ask(User $owner, AiCommandSession $session, string $question): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Message cannot be empty');
        }

        if (! $session->title || $session->title === 'Command Center') {
            $session->update(['title' => Str::limit($question, 60)]);
        }

        $userMsg = AiCommandMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $question,
            'meta' => null,
        ]);

        $allowWrites = $this->authorizer->isAiEnabled();
        $history = $session->messages()->orderBy('id')->get();

        try {
            $result = $this->runWithTools($owner, $history->all(), $allowWrites);
        } catch (Throwable $e) {
            Log::warning('Command Center failed', ['error' => $e->getMessage()]);
            $result = [
                'content' => 'I hit an error answering that. Try again, or rephrase the question. ('.$e->getMessage().')',
                'tools_used' => [],
                'pending_action' => null,
                'usage' => null,
                'provider' => 'error',
            ];
        }

        $assistantMsg = AiCommandMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $result['content'],
            'meta' => [
                'tools_used' => $result['tools_used'],
                'pending_action' => $result['pending_action'],
                'usage' => $result['usage'],
                'provider' => $result['provider'],
                'kill_switch' => ! $allowWrites,
            ],
        ]);

        $session->update(['last_message_at' => now()]);

        AiActionLog::create([
            'trigger_event' => 'admin_command_center',
            'actor_id' => $owner->id,
            'data_viewed' => [
                'session_id' => $session->id,
                'question' => Str::limit($question, 500),
                'tools_used' => $result['tools_used'],
                'usage' => $result['usage'],
            ],
            'decision' => $result['pending_action'] ? 'answered_with_draft' : 'answered',
            'action_taken' => 'command_center_chat',
            'rule_applied' => 'openai_tool_calling + structured_queries',
            'required_human_approval' => (bool) $result['pending_action'],
        ]);

        return [
            'session' => $session->fresh(),
            'user_message' => $userMsg,
            'assistant_message' => $assistantMsg,
        ];
    }

    public function confirmAction(User $owner, AiCommandSession $session, array $pending): array
    {
        $result = $this->actions->confirmPending($pending, $owner);

        $content = ($result['status'] ?? '') === 'executed'
            ? 'Done — message sent to '.($result['pm_name'] ?? 'PM').'.'
            : ('Could not execute: '.($result['reason'] ?? $result['error'] ?? 'unknown'));

        $msg = AiCommandMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'meta' => ['confirm_result' => $result],
        ]);
        $session->update(['last_message_at' => now()]);

        return ['result' => $result, 'message' => $msg];
    }

    /**
     * @param  list<AiCommandMessage>  $history
     * @return array{content: string, tools_used: array, pending_action: ?array, usage: ?array, provider: string}
     */
    private function runWithTools(User $owner, array $history, bool $allowWrites): array
    {
        $apiKey = config('ai.openai.api_key');
        $useOpenAi = config('ai.provider') === 'openai' && $apiKey;

        $tools = array_merge($this->queries->toolDefinitions(), $this->actions->toolDefinitions());

        if (! $useOpenAi) {
            return $this->deterministicFallback($history, $allowWrites, $owner);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are the ServiceOP Owner AI Command Center. '
                    .'CRITICAL: For ANY question about leads, jobs, quotes, payments, payouts, PMs, reviews, errors, or "how are things", '
                    .'you MUST call the appropriate query tool before answering. Never invent counts/names and never reuse prior message numbers — always fetch fresh tool data. '
                    .'For messaging a PM, call draft_message_to_pm (never claim it was sent). '
                    .'Be concise and operational. Kill switch writes allowed='.($allowWrites ? 'yes' : 'no (read-only)').'.',
            ],
        ];

        // Prefer only the latest user turn for tool routing context; still send short history for continuity.
        $recent = array_slice($history, -8);
        foreach ($recent as $m) {
            if (! in_array($m->role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = ['role' => $m->role, 'content' => $m->content];
        }

        $model = config('ai.openai.model', 'gpt-4o-mini');
        $timeout = max(30, (int) config('ai.openai.timeout', 20));
        $toolsUsed = [];
        $pendingAction = null;
        $usageTotal = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'estimated_cost_usd' => 0.0, 'model' => $model];

        $lastUser = collect($history)->last(fn ($m) => $m->role === 'user');
        $forcedTool = $this->inferRequiredQueryTool($lastUser?->content ?? '');

        // Tool loop (max 3 rounds)
        for ($round = 0; $round < 3; $round++) {
            $payload = [
                'model' => $model,
                'temperature' => 0.2,
                'tools' => $tools,
                'tool_choice' => ($round === 0 && $forcedTool)
                    ? ['type' => 'function', 'function' => ['name' => $forcedTool]]
                    : 'auto',
                'messages' => $messages,
            ];

            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('OpenAI HTTP '.$response->status());
            }

            $json = $response->json();
            $this->accumulateUsage($usageTotal, $json['usage'] ?? [], $model);
            $choice = $json['choices'][0]['message'] ?? [];
            $toolCalls = $choice['tool_calls'] ?? [];

            if (! $toolCalls) {
                // Safety: if model skipped tools on a data question, inject structured query ourselves
                if ($round === 0 && $forcedTool) {
                    $toolResult = $this->queries->dispatch($forcedTool, []);
                    $toolsUsed[] = ['name' => $forcedTool, 'args' => [], 'result_summary' => $this->summarizeToolResult($toolResult), 'forced' => true];
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'forced_'.$forcedTool,
                            'type' => 'function',
                            'function' => ['name' => $forcedTool, 'arguments' => '{}'],
                        ]],
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => 'forced_'.$forcedTool,
                        'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES),
                    ];
                    $forcedTool = null;

                    continue;
                }

                $content = trim((string) ($choice['content'] ?? ''));

                return [
                    'content' => $content !== '' ? $content : 'No response generated.',
                    'tools_used' => $toolsUsed,
                    'pending_action' => $pendingAction,
                    'usage' => $usageTotal,
                    'provider' => 'openai',
                ];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $choice['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: [];
                $queryNames = array_map(fn ($t) => $t['function']['name'], $this->queries->toolDefinitions());

                if (in_array($name, $queryNames, true)) {
                    $toolResult = $this->queries->dispatch($name, $args);
                } else {
                    $toolResult = $this->actions->dispatch($name, $args, $owner, $allowWrites);
                    if (($toolResult['pending_action'] ?? null)) {
                        $pendingAction = $toolResult['pending_action'];
                    }
                }

                $toolsUsed[] = ['name' => $name, 'args' => $args, 'result_summary' => $this->summarizeToolResult($toolResult)];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($toolResult, JSON_UNESCAPED_SLASHES),
                ];
            }

            $forcedTool = null;
        }

        // Final answer without tools
        $final = Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0.2,
                'messages' => array_merge($messages, [[
                    'role' => 'system',
                    'content' => 'Provide the final concise answer for the owner now. No more tool calls.',
                ]]),
            ]);

        if (! $final->successful()) {
            throw new \RuntimeException('OpenAI final HTTP '.$final->status());
        }
        $finalJson = $final->json();
        $this->accumulateUsage($usageTotal, $finalJson['usage'] ?? [], $model);
        $content = trim((string) ($finalJson['choices'][0]['message']['content'] ?? ''));

        return [
            'content' => $content !== '' ? $content : 'Done.',
            'tools_used' => $toolsUsed,
            'pending_action' => $pendingAction,
            'usage' => $usageTotal,
            'provider' => 'openai',
        ];
    }

    /**
     * Deterministic answers for tests / mock provider — still uses real query data.
     *
     * @param  list<AiCommandMessage>  $history
     */
    private function deterministicFallback(array $history, bool $allowWrites, User $owner): array
    {
        $last = collect($history)->last(fn ($m) => $m->role === 'user');
        $q = strtolower($last?->content ?? '');

        $toolsUsed = [];
        $pending = null;
        $content = '';

        if (str_contains($q, 'stuck')) {
            $data = $this->queries->stuckLeads();
            $toolsUsed[] = ['name' => 'get_stuck_leads', 'result_summary' => $this->summarizeToolResult($data)];
            $content = "Stuck leads: {$data['count']}.\n".$this->formatStuck($data);
        } elseif (str_contains($q, 'payout')) {
            $data = $this->queries->jobsReadyForPayout();
            $toolsUsed[] = ['name' => 'get_jobs_ready_for_payout', 'result_summary' => $this->summarizeToolResult($data)];
            $content = "Jobs ready for payout: {$data['count']}.\n".$this->formatPayoutJobs($data);
        } elseif ((str_contains($q, 'message') || str_contains($q, 'text') || str_contains($q, 'sms'))
            && (str_contains($q, 'pm') || str_contains($q, 'project manager'))) {
            $pm = User::where('role', 'pm')->first();
            $draft = $this->actions->dispatch('draft_message_to_pm', [
                'pm_id' => $pm?->id,
                'message' => 'Please follow up on the overdue lead today. — ServiceOP Command Center',
            ], $owner, $allowWrites);
            $toolsUsed[] = ['name' => 'draft_message_to_pm', 'result_summary' => $this->summarizeToolResult($draft)];
            $pending = $draft['pending_action'] ?? null;
            if (! $allowWrites) {
                $content = 'Kill switch is on — I can answer questions but cannot stage or execute actions.';
            } else {
                $content = 'Draft ready for '.($pm?->name ?? 'PM').". Confirm to send:\n".($pending['message'] ?? '');
            }
        } elseif (str_contains($q, 'pm') && (str_contains($q, 'follow') || str_contains($q, 'need'))) {
            $data = $this->queries->pmFollowUps();
            $toolsUsed[] = ['name' => 'get_pm_follow_ups', 'result_summary' => $this->summarizeToolResult($data)];
            $content = "PMs needing follow-up: {$data['pm_count']}.";
        } elseif (str_contains($q, 'attention') || str_contains($q, 'needs my')) {
            $data = $this->queries->ownerAttentionItems();
            $toolsUsed[] = ['name' => 'get_owner_attention_items', 'result_summary' => $this->summarizeToolResult($data)];
            $content = "Items needing your attention: {$data['count']}.";
        } else {
            $data = $this->queries->todayOpsSummary();
            $toolsUsed[] = ['name' => 'get_today_ops_summary', 'result_summary' => $this->summarizeToolResult($data)];
            $content = sprintf(
                "Today: %d new leads, %d quotes awaiting customer, %d jobs in progress, %d invoices paid (\$%s), %d overdue next actions, %d AI errors.",
                $data['new_leads_today'],
                $data['quotes_awaiting_customer'],
                $data['jobs_in_progress'],
                $data['invoices_paid_today'],
                number_format($data['revenue_paid_today_subtotal'], 2),
                $data['overdue_next_actions'],
                $data['ai_errors_today']
            );
        }

        return [
            'content' => $content,
            'tools_used' => $toolsUsed,
            'pending_action' => $pending,
            'usage' => null,
            'provider' => 'deterministic',
        ];
    }

    /**
     * Map common owner questions to a required query tool so OpenAI cannot skip tools / invent numbers.
     */
    private function inferRequiredQueryTool(string $question): ?string
    {
        $q = strtolower($question);

        if (str_contains($q, 'stuck')) {
            return 'get_stuck_leads';
        }
        if (str_contains($q, 'payout')) {
            return 'get_jobs_ready_for_payout';
        }
        if ((str_contains($q, 'message') || str_contains($q, 'text') || str_contains($q, 'sms'))
            && (str_contains($q, 'pm') || str_contains($q, 'project manager'))) {
            return null; // action tools use auto
        }
        if (str_contains($q, 'pm') && (str_contains($q, 'follow') || str_contains($q, 'need'))) {
            return 'get_pm_follow_ups';
        }
        if (str_contains($q, 'attention') || str_contains($q, 'needs my')) {
            return 'get_owner_attention_items';
        }
        if (str_contains($q, 'today') || str_contains($q, 'how are things') || str_contains($q, 'going')) {
            return 'get_today_ops_summary';
        }

        return null;
    }

    private function formatStuck(array $data): string
    {
        $lines = [];
        foreach (array_slice($data['items'] ?? [], 0, 8) as $item) {
            $lines[] = sprintf(
                '- Lead #%s %s — %s (%dh overdue) PM: %s',
                $item['lead_id'],
                $item['contact_name'] ?? '?',
                $item['action'] ?? '',
                $item['hours_overdue'] ?? 0,
                $item['pm_name'] ?? '—'
            );
        }

        return implode("\n", $lines);
    }

    private function formatPayoutJobs(array $data): string
    {
        $lines = [];
        foreach (array_slice($data['jobs'] ?? [], 0, 8) as $j) {
            $lines[] = sprintf('- Job #%s %s (PM %s)', $j['job_id'], $j['address'] ?? '', $j['pm'] ?? '—');
        }

        return implode("\n", $lines);
    }

    private function summarizeToolResult(array $result): array
    {
        if (isset($result['count'])) {
            return ['count' => $result['count']];
        }
        if (isset($result['pm_count'])) {
            return ['pm_count' => $result['pm_count']];
        }
        if (isset($result['status'])) {
            return ['status' => $result['status']];
        }
        if (isset($result['new_leads_today'])) {
            return [
                'new_leads_today' => $result['new_leads_today'],
                'jobs_in_progress' => $result['jobs_in_progress'],
                'overdue_next_actions' => $result['overdue_next_actions'],
            ];
        }

        return ['keys' => array_keys($result)];
    }

    private function accumulateUsage(array &$total, array $usage, string $model): void
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $total['prompt_tokens'] += $prompt;
        $total['completion_tokens'] += $completion;
        $total['total_tokens'] += (int) ($usage['total_tokens'] ?? ($prompt + $completion));
        $inputRate = (float) config('ai.openai.cost_per_1m_input_tokens', 0.15);
        $outputRate = (float) config('ai.openai.cost_per_1m_output_tokens', 0.60);
        $total['estimated_cost_usd'] = round(
            ($total['prompt_tokens'] / 1_000_000 * $inputRate)
            + ($total['completion_tokens'] / 1_000_000 * $outputRate),
            6
        );
        $total['model'] = $model;
    }
}
