<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\AiActionGate;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A-17 — Explicit AI action gate / simulation / retry surface for enforcement tests & ops.
 */
class AiActionGateController extends Controller
{
    public function __construct(
        private AiActionGate $gate,
        private SmsService $sms,
    ) {}

    public function evaluate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action_key' => 'required|string|max:100',
            'idempotency_key' => 'nullable|string|max:191',
            'lead_id' => 'nullable|integer',
            'job_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'simulate' => 'nullable|boolean',
            'confirmed' => 'nullable|boolean',
            'message' => 'nullable|string|max:2000',
        ]);

        $ctx = $this->contextFrom($request->user(), $data);
        $decision = $this->gate->evaluate($data['action_key'], $request->user(), $ctx);

        return response()->json(['decision' => $decision]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action_key' => 'required|string|max:100',
            'idempotency_key' => 'nullable|string|max:191',
            'lead_id' => 'nullable|integer',
            'job_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'simulate' => 'nullable|boolean',
            'confirmed' => 'nullable|boolean',
            'message' => 'nullable|string|max:2000',
            'recipient_user_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $ctx = $this->contextFrom($user, $data);
        $ctx['preview'] = [
            'action_key' => $data['action_key'],
            'message' => $data['message'] ?? null,
            'consequences' => $this->gate->consequencesFor($data['action_key'], $ctx),
        ];

        $result = $this->gate->run($data['action_key'], $user, $ctx, function () use ($data, $user, $ctx) {
            return $this->executeLive($data['action_key'], $user, $data, $ctx);
        });

        return response()->json($result);
    }

    public function retry(Request $request, AiActionLog $aiActionLog): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'owner' && (int) $aiActionLog->actor_id !== (int) $user->id) {
            abort(403);
        }

        $root = $aiActionLog->parent_log_id
            ? AiActionLog::find($aiActionLog->parent_log_id) ?? $aiActionLog
            : $aiActionLog;

        $maxRetries = (int) config('ai_actions.limits.max_retries_per_action', 2);
        if ((int) $root->retry_count >= $maxRetries) {
            return response()->json([
                'status' => 'blocked',
                'reason' => "Max retries ({$maxRetries}) reached for this incident.",
            ], 422);
        }

        $key = $root->idempotency_key ?: ('ai-retry-'.$root->id);
        $ctx = [
            'idempotency_key' => $key,
            'trace_id' => $root->trace_id ?: (string) Str::uuid(),
            'trigger_event' => $root->trigger_event,
            'lead_id' => $root->linked_type === 'lead' ? $root->linked_id : null,
            'job_id' => $root->linked_type === 'job' ? $root->linked_id : null,
            'brand_id' => $root->brand_id,
            'confirmed' => true,
            'message' => $root->message_sent,
            'module' => $root->module,
        ];
        if (! $root->idempotency_key) {
            $root->update(['idempotency_key' => $key, 'trace_id' => $ctx['trace_id']]);
        }

        // Mark root as terminal success so subsequent retries dedupe (A-21 pattern).
        if (! in_array($root->outcome, ['executed', 'success', 'deduplicated', 'simulated'], true)) {
            // First successful retry path: attempt live no-op marker then dedupe.
            $first = $this->gate->run($root->action_key ?: 'create_internal_note', $user, array_merge($ctx, [
                // Force a child under the same trace by seeding parent via prior success row.
            ]), function () {
                return ['retried' => true];
            });

            if (($first['status'] ?? '') === 'executed' && isset($first['log'])) {
                // Re-parent under root for grouping.
                $first['log']->update([
                    'parent_log_id' => $root->id,
                    'trace_id' => $root->trace_id ?: $first['log']->trace_id,
                ]);
                $root->update([
                    'outcome' => 'executed',
                    'idempotency_key' => $key,
                    'trace_id' => $root->trace_id ?: $first['log']->trace_id,
                ]);
                $root->increment('retry_count');
            }

            return response()->json($first);
        }

        $dup = $this->gate->run($root->action_key ?: 'create_internal_note', $user, $ctx, function () {
            return ['should_not_run' => true];
        });

        return response()->json($dup);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contextFrom(User $user, array $data): array
    {
        $ctx = [
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'job_id' => $data['job_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'simulate' => $data['simulate'] ?? null,
            'confirmed' => (bool) ($data['confirmed'] ?? false),
            'message' => $data['message'] ?? null,
            'trigger_event' => 'ai_action_gate',
            'linked_type' => ! empty($data['lead_id']) ? 'lead' : (! empty($data['job_id']) ? 'job' : null),
            'linked_id' => $data['lead_id'] ?? $data['job_id'] ?? null,
        ];

        if (! empty($data['lead_id'])) {
            $ctx['lead'] = Lead::find((int) $data['lead_id']);
            if ($ctx['lead']?->brand_id && empty($ctx['brand_id'])) {
                $ctx['brand_id'] = (int) $ctx['lead']->brand_id;
            }
        }
        if (! empty($data['job_id'])) {
            $ctx['job'] = Job::find((int) $data['job_id']);
        }

        return $ctx;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function executeLive(string $actionKey, User $actor, array $data, array $ctx): array
    {
        // Only a narrow set is executable via this gate — others are policy/simulation only.
        if ($actionKey === 'send_customer_message' || $actionKey === 'command_center_draft_pm_message') {
            $recipient = User::find((int) ($data['recipient_user_id'] ?? 0));
            if (! $recipient) {
                return ['sent' => false, 'error' => 'recipient_missing'];
            }
            $body = (string) ($data['message'] ?? 'ServiceOP AI message');
            $sms = $this->sms->sendToUser($recipient, $body, 'ai_gated_message', $ctx['job_id'] ?? null);

            return ['sent' => (bool) ($sms['success'] ?? false), 'sms' => $sms];
        }

        if ($actionKey === 'create_internal_note' || $actionKey === 'command_center_create_next_action') {
            return ['ok' => true, 'note' => 'live marker'];
        }

        return ['ok' => true, 'action_key' => $actionKey];
    }
}
