<?php

namespace App\Services\Ai;

use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\AiActionRegistry;
use App\Services\Authorization\PmAuthorizationService;
use Illuminate\Support\Str;

/**
 * A-17 — Central per-action AI operating-mode gate.
 * Hard risk floors cannot be overridden by Autopilot.
 */
class AiActionGate
{
    public const OUTCOMES_TERMINAL = ['executed', 'success', 'deduplicated', 'simulated', 'blocked', 'approval_required'];

    public function __construct(
        private AiActionAuthorizer $authorizer,
        private AiActionRegistry $registry,
        private PmAuthorizationService $pmAuth,
    ) {}

    public function isSimulationMode(): bool
    {
        return Setting::getBool('ai_simulation_mode', false);
    }

    public function modeDefinition(string $mode): array
    {
        return config("ai_actions.mode_definitions.{$mode}")
            ?? ['label' => $mode, 'summary' => ''];
    }

    /**
     * @param  array{
     *   idempotency_key?: string|null,
     *   brand_id?: int|null,
     *   lead?: Lead|null,
     *   job?: Job|null,
     *   lead_id?: int|null,
     *   job_id?: int|null,
     *   simulate?: bool|null,
     *   confirmed?: bool,
     *   cost_usd?: float|null,
     * }  $context
     * @return array{
     *   allowed: bool,
     *   status: string,
     *   reason: string|null,
     *   requires_approval: bool,
     *   is_simulation: bool,
     *   mode: string,
     *   risk_level: string,
     *   action: array|null,
     *   deduplicated: bool,
     *   existing_log: AiActionLog|null,
     * }
     */
    public function evaluate(string $actionKey, ?User $actor, array $context = []): array
    {
        $action = $this->registry->find($actionKey);
        if (! $action) {
            return $this->decision(false, 'unknown_action', 'Action is not registered.', false, false, 'suggestion', 'high', null);
        }

        $module = $action['module'] ?? 'command_center';
        $mode = $this->authorizer->getModuleMode($module);
        $risk = $action['risk_level'] ?? 'medium';
        $simulate = array_key_exists('simulate', $context)
            ? (bool) $context['simulate']
            : $this->isSimulationMode();

        if (! $this->authorizer->isAiEnabled()) {
            return $this->decision(false, 'kill_switch', 'AI kill switch is on.', false, $simulate, $mode, $risk, $action);
        }

        if ($actor && ! $this->authorizer->canPerform($actor, $actionKey) && ! $this->actorMayUseCommandCenter($actor, $action)) {
            return $this->decision(false, 'forbidden_role', 'Actor role cannot perform this AI action.', false, $simulate, $mode, $risk, $action);
        }

        $scopeBlock = $this->assertScope($actor, $context);
        if ($scopeBlock !== null) {
            return $this->decision(false, 'brand_scope', $scopeBlock, false, $simulate, $mode, $risk, $action);
        }

        $modes = $action['modes_available'] ?? [];
        if (! in_array($mode, $modes, true)) {
            return $this->decision(false, 'mode_blocked', "Action not available in {$mode} mode.", false, $simulate, $mode, $risk, $action);
        }

        $idempotencyKey = $context['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = AiActionLog::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('outcome', ['executed', 'success', 'deduplicated', 'simulated'])
                ->orderBy('id')
                ->first();
            if ($existing) {
                return array_merge(
                    $this->decision(true, 'deduplicated', 'Retry already completed — no duplicate execution.', false, (bool) $existing->is_simulation, $mode, $risk, $action),
                    ['deduplicated' => true, 'existing_log' => $existing]
                );
            }
        }

        $limitBlock = $this->checkLimits($actor, $context['cost_usd'] ?? null);
        if ($limitBlock !== null) {
            return $this->decision(false, 'limit_exceeded', $limitBlock, false, $simulate, $mode, $risk, $action);
        }

        // Simulation short-circuits live execution (still produces a proposed-action log).
        if ($simulate) {
            return $this->decision(true, 'simulate', 'Simulation mode — proposed action only, no live changes.', false, true, $mode, $risk, $action);
        }

        $hardFloor = (bool) ($action['hard_approval_floor'] ?? false);
        $registryApproval = (bool) ($action['requires_human_approval'] ?? false);
        $requiresApproval = $hardFloor || $registryApproval;

        // Suggestion mode never auto-executes writes.
        if ($mode === 'suggestion' && $risk !== 'low') {
            $requiresApproval = true;
        }

        if ($requiresApproval && empty($context['confirmed'])) {
            return $this->decision(
                true,
                'approval_required',
                $hardFloor
                    ? 'High-risk action requires human approval even in Auto mode.'
                    : 'Action requires human approval before execution.',
                true,
                false,
                $mode,
                $risk,
                $action
            );
        }

        return $this->decision(true, 'allowed', null, false, false, $mode, $risk, $action);
    }

    /**
     * Execute or stage an AI action with idempotency + trace logging (A-17/A-18).
     *
     * @param  callable(): array  $executor  Returns result payload; not called when blocked/simulated/deduped/approval-needed
     * @return array<string, mixed>
     */
    public function run(string $actionKey, ?User $actor, array $context, callable $executor): array
    {
        $started = microtime(true);
        $decision = $this->evaluate($actionKey, $actor, $context);

        if (! empty($decision['deduplicated']) && $decision['existing_log']) {
            /** @var AiActionLog $root */
            $root = $decision['existing_log'];
            $child = $this->record([
                'action_key' => $actionKey,
                'actor' => $actor,
                'decision' => $decision,
                'context' => $context,
                'outcome' => 'deduplicated',
                'parent' => $root,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'data_viewed' => ['dedupe_of' => $root->id],
                'action_taken' => $actionKey,
            ]);
            $root->increment('retry_count');

            return [
                'status' => 'deduplicated',
                'deduplicated' => true,
                'log' => $child,
                'root_log' => $root->fresh(),
                'decision' => $decision,
            ];
        }

        if ($decision['status'] === 'approval_required') {
            $log = $this->record([
                'action_key' => $actionKey,
                'actor' => $actor,
                'decision' => $decision,
                'context' => $context,
                'outcome' => 'approval_required',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'data_viewed' => $context['preview'] ?? $context,
                'action_taken' => $actionKey,
                'required_human_approval' => true,
                'message_sent' => $context['message'] ?? null,
            ]);

            return [
                'status' => 'approval_required',
                'requires_approval' => true,
                'log' => $log,
                'decision' => $decision,
                'preview' => $context['preview'] ?? [
                    'action_key' => $actionKey,
                    'risk_level' => $decision['risk_level'],
                    'consequences' => $this->consequencesFor($actionKey, $context),
                ],
            ];
        }

        if (! $decision['allowed']) {
            $log = $this->record([
                'action_key' => $actionKey,
                'actor' => $actor,
                'decision' => $decision,
                'context' => $context,
                'outcome' => 'blocked',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'data_viewed' => $context,
                'action_taken' => $actionKey,
                'error' => $decision['reason'],
            ]);

            return [
                'status' => 'blocked',
                'reason' => $decision['reason'],
                'log' => $log,
                'decision' => $decision,
            ];
        }

        if ($decision['is_simulation']) {
            $log = $this->record([
                'action_key' => $actionKey,
                'actor' => $actor,
                'decision' => $decision,
                'context' => $context,
                'outcome' => 'simulated',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'data_viewed' => array_merge($context, ['proposed' => true]),
                'action_taken' => 'proposed_'.$actionKey,
                'is_simulation' => true,
            ]);

            return [
                'status' => 'simulated',
                'log' => $log,
                'decision' => $decision,
                'proposed' => $context['preview'] ?? $context,
            ];
        }

        $result = $executor();
        $log = $this->record([
            'action_key' => $actionKey,
            'actor' => $actor,
            'decision' => $decision,
            'context' => $context,
            'outcome' => 'executed',
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'data_viewed' => array_merge($context, ['result' => $result]),
            'action_taken' => $actionKey,
            'message_sent' => $context['message'] ?? null,
        ]);

        return [
            'status' => 'executed',
            'result' => $result,
            'log' => $log,
            'decision' => $decision,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(array $payload): AiActionLog
    {
        /** @var array $decision */
        $decision = $payload['decision'] ?? [];
        $context = $payload['context'] ?? [];
        $parent = $payload['parent'] ?? null;
        $traceId = $parent?->trace_id
            ?? ($context['trace_id'] ?? (string) Str::uuid());

        $idempotencyKey = $context['idempotency_key'] ?? null;
        // Only bind idempotency on terminal/confirmable attempts — not draft/approval staging.
        // Child/dedupe rows must not reuse the unique key (A-21 style: one successful key).
        if (in_array($payload['outcome'] ?? '', ['approval_required', 'deduplicated'], true) || $parent) {
            $idempotencyKey = null;
        }

        return AiActionLog::create([
            'trace_id' => $traceId,
            'parent_log_id' => $parent?->id,
            'trigger_event' => $context['trigger_event'] ?? ($payload['action_key'] ?? 'ai_action'),
            'actor_id' => $payload['actor']?->id ?? User::aiSuperAdmin()?->id ?? User::query()->where('role', 'owner')->value('id'),
            'data_viewed' => $this->redactDataViewed($payload['data_viewed'] ?? []),
            'decision' => $decision['reason'] ?? $decision['status'] ?? ($payload['outcome'] ?? null),
            'action_taken' => $payload['action_taken'] ?? $payload['action_key'] ?? null,
            'action_key' => $payload['action_key'] ?? null,
            'module' => $decision['action']['module'] ?? $context['module'] ?? null,
            'mode' => $decision['mode'] ?? null,
            'risk_level' => $decision['risk_level'] ?? null,
            'ai_model' => $context['ai_model'] ?? config('ai.openai.model'),
            'prompt_version' => $context['prompt_version'] ?? config('ai_actions.prompt_version'),
            'tokens_prompt' => $context['tokens_prompt'] ?? null,
            'tokens_completion' => $context['tokens_completion'] ?? null,
            'cost_usd' => $context['cost_usd'] ?? null,
            'latency_ms' => $payload['latency_ms'] ?? null,
            'retry_count' => $parent ? 0 : (int) ($context['retry_count'] ?? 0),
            'idempotency_key' => $idempotencyKey,
            'linked_type' => $context['linked_type'] ?? null,
            'linked_id' => $context['linked_id'] ?? null,
            'brand_id' => $context['brand_id'] ?? null,
            'outcome' => $payload['outcome'] ?? null,
            'is_simulation' => (bool) ($payload['is_simulation'] ?? $decision['is_simulation'] ?? false),
            'approval_log_id' => $context['approval_log_id'] ?? null,
            'message_sent' => $payload['message_sent'] ?? null,
            'recipient' => $context['recipient'] ?? null,
            'rule_applied' => sprintf(
                '%s|mode=%s|risk=%s|floor=%s',
                $payload['action_key'] ?? 'unknown',
                $decision['mode'] ?? '?',
                $decision['risk_level'] ?? '?',
                ($decision['action']['hard_approval_floor'] ?? false) ? 'yes' : 'no'
            ),
            'required_human_approval' => (bool) ($payload['required_human_approval'] ?? $decision['requires_approval'] ?? false),
            'error' => $payload['error'] ?? null,
        ]);
    }

    public function consequencesFor(string $actionKey, array $context): string
    {
        return match ($actionKey) {
            'send_customer_message', 'command_center_draft_pm_message' => 'An SMS/message will be delivered to the recipient. This cannot be unsent.',
            'initiate_payout' => 'Funds may be transferred. This is a financial action.',
            'update_lead_status', 'update_job_status' => 'Workflow status will change and may trigger notifications.',
            'archive_record' => 'The record will be archived and hidden from default lists.',
            'create_lead' => 'A new lead record will be created in the CRM.',
            default => 'This will modify live operational data.',
        };
    }

    private function actorMayUseCommandCenter(User $actor, array $action): bool
    {
        $module = $action['module'] ?? '';
        if ($module !== 'command_center') {
            return false;
        }
        if ($actor->role === 'owner') {
            return true;
        }
        // PMs may run read/query and scoped low-risk CC actions; financial stays owner-only.
        if ($actor->role === 'pm') {
            $risk = $action['risk_level'] ?? 'medium';

            return in_array($risk, ['low', 'medium'], true)
                || ($action['action_key'] ?? '') === 'command_center_query';
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertScope(?User $actor, array $context): ?string
    {
        if (! $actor || $actor->role === 'owner') {
            return null;
        }

        try {
            if (! empty($context['lead']) && $context['lead'] instanceof Lead) {
                $this->pmAuth->assertLeadAccess($actor, $context['lead']);
            } elseif (! empty($context['lead_id'])) {
                $lead = Lead::find((int) $context['lead_id']);
                if ($lead) {
                    $this->pmAuth->assertLeadAccess($actor, $lead);
                }
            }

            if (! empty($context['job']) && $context['job'] instanceof Job) {
                $this->pmAuth->assertJobAccess($actor, $context['job']);
            } elseif (! empty($context['job_id'])) {
                $job = Job::find((int) $context['job_id']);
                if ($job) {
                    $this->pmAuth->assertJobAccess($actor, $job);
                }
            }

            if (isset($context['brand_id'])) {
                $this->pmAuth->assertBrandAccess($actor, (int) $context['brand_id'], 'ai_action');
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getMessage() ?: 'Unauthorized brand/record scope.';
        }

        return null;
    }

    private function checkLimits(?User $actor, ?float $extraCost): ?string
    {
        $dailyLimit = (int) Setting::get('ai_daily_action_limit', config('ai_actions.limits.daily_action_limit', 200));
        $costLimit = (float) Setting::get('ai_daily_cost_usd_limit', config('ai_actions.limits.daily_cost_usd_limit', 25));

        $q = AiActionLog::query()
            ->whereDate('created_at', now()->toDateString())
            ->where('is_simulation', false)
            ->whereNotIn('outcome', ['blocked', 'deduplicated']);

        if ($actor) {
            $q->where('actor_id', $actor->id);
        }

        if ($q->count() >= $dailyLimit) {
            return "Daily AI action limit ({$dailyLimit}) reached.";
        }

        $spent = (float) AiActionLog::query()
            ->whereDate('created_at', now()->toDateString())
            ->where('is_simulation', false)
            ->sum('cost_usd');

        if (($spent + (float) $extraCost) > $costLimit) {
            return "Daily AI cost limit (\${$costLimit}) would be exceeded.";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactDataViewed(array $data): array
    {
        foreach (['content', 'conversation', 'full_content', 'messages'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && strlen($data[$key]) > 120) {
                $data[$key] = Str::limit($data[$key], 80).' [redacted — owner reveal required]';
            }
        }

        return $data;
    }

    /**
     * @return array{
     *   allowed: bool,
     *   status: string,
     *   reason: string|null,
     *   requires_approval: bool,
     *   is_simulation: bool,
     *   mode: string,
     *   risk_level: string,
     *   action: array|null,
     *   deduplicated: bool,
     *   existing_log: null,
     * }
     */
    private function decision(
        bool $allowed,
        string $status,
        ?string $reason,
        bool $requiresApproval,
        bool $simulate,
        string $mode,
        string $risk,
        ?array $action,
    ): array {
        return [
            'allowed' => $allowed,
            'status' => $status,
            'reason' => $reason,
            'requires_approval' => $requiresApproval,
            'is_simulation' => $simulate,
            'mode' => $mode,
            'risk_level' => $risk,
            'action' => $action,
            'deduplicated' => false,
            'existing_log' => null,
        ];
    }
}
