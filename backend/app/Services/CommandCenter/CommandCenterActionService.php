<?php

namespace App\Services\CommandCenter;

use App\Models\AiActionLog;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use App\Services\Ai\AiActionGate;
use App\Services\AiActionAuthorizer;
use App\Services\SmsService;
use Illuminate\Support\Str;

class CommandCenterActionService
{
    public function __construct(
        private SmsService $sms,
        private AiActionAuthorizer $authorizer,
        private AiActionGate $gate,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'draft_message_to_pm',
                    'description' => 'Draft an SMS/message to a PM about a lead or job. Does NOT send until owner confirms. High-risk — approval required even in Auto mode.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'pm_id' => ['type' => 'integer', 'description' => 'PM user id'],
                            'lead_id' => ['type' => 'integer', 'description' => 'Optional related lead id'],
                            'job_id' => ['type' => 'integer', 'description' => 'Optional related job id'],
                            'message' => ['type' => 'string', 'description' => 'Message body to send'],
                        ],
                        'required' => ['pm_id', 'message'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_owner_next_action',
                    'description' => 'Create a pending NextAction for owner/PM follow-up. In suggestion mode or simulation, stages only.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'lead_id' => ['type' => 'integer'],
                            'description' => ['type' => 'string'],
                            'responsible_role' => ['type' => 'string', 'enum' => ['owner', 'pm']],
                            'responsible_user_id' => ['type' => 'integer'],
                            'due_hours' => ['type' => 'integer', 'description' => 'Hours from now when due (default 24)'],
                        ],
                        'required' => ['lead_id', 'description'],
                    ],
                ],
            ],
        ];
    }

    public function dispatch(string $name, array $args, User $actor, bool $allowWrites): array
    {
        if (! $allowWrites) {
            return [
                'status' => 'blocked',
                'reason' => 'AI kill switch is on — actions are blocked. Read-only questions still work.',
                'action' => $name,
            ];
        }

        return match ($name) {
            'draft_message_to_pm' => $this->draftMessageToPm($args, $actor),
            'create_owner_next_action' => $this->createNextAction($args, $actor),
            default => ['status' => 'error', 'error' => 'Unknown action tool: '.$name],
        };
    }

    public function confirmPending(array $pending, User $actor): array
    {
        if (! $this->authorizer->isAiEnabled()) {
            return ['status' => 'blocked', 'reason' => 'AI kill switch is on'];
        }

        if (($pending['type'] ?? '') !== 'draft_message_to_pm') {
            return ['status' => 'error', 'error' => 'Unsupported pending action'];
        }

        $idempotencyKey = $pending['idempotency_key'] ?? ('cc-confirm-'.($pending['pending_id'] ?? Str::uuid()));

        $result = $this->gate->run('command_center_draft_pm_message', $actor, [
            'idempotency_key' => $idempotencyKey,
            'confirmed' => true,
            'simulate' => false,
            'message' => $pending['message'] ?? null,
            'lead_id' => $pending['lead_id'] ?? null,
            'job_id' => $pending['job_id'] ?? null,
            'brand_id' => $pending['brand_id'] ?? null,
            'trigger_event' => 'admin_command_center',
            'recipient' => $pending['pm_name'] ?? null,
            'linked_type' => ! empty($pending['lead_id']) ? 'lead' : null,
            'linked_id' => $pending['lead_id'] ?? null,
            'preview' => [
                'consequences' => $this->gate->consequencesFor('command_center_draft_pm_message', $pending),
                'pending' => $pending,
            ],
        ], function () use ($pending, $actor) {
            $pm = User::where('role', 'pm')->find($pending['pm_id'] ?? 0);
            if (! $pm) {
                return ['status' => 'error', 'error' => 'PM not found'];
            }

            $message = (string) ($pending['message'] ?? '');
            $sms = $this->sms->sendToUser($pm, $message, 'admin_command_center_pm_message', $pending['job_id'] ?? null);

            return [
                'status' => 'executed',
                'pm_id' => $pm->id,
                'pm_name' => $pm->name,
                'sms_status' => $sms['status'] ?? null,
                'sms_success' => (bool) ($sms['success'] ?? false),
            ];
        });

        if (($result['status'] ?? '') === 'deduplicated') {
            return [
                'status' => 'executed',
                'deduplicated' => true,
                'pm_name' => $pending['pm_name'] ?? 'PM',
                'ai_action_log_id' => $result['root_log']->id ?? $result['log']->id ?? null,
            ];
        }

        if (($result['status'] ?? '') !== 'executed') {
            return [
                'status' => $result['status'] ?? 'error',
                'reason' => $result['reason'] ?? $result['decision']['reason'] ?? 'blocked',
                'ai_action_log_id' => $result['log']->id ?? null,
            ];
        }

        $inner = $result['result'] ?? [];

        return array_merge($inner, [
            'status' => $inner['status'] ?? 'executed',
            'ai_action_log_id' => $result['log']->id ?? null,
            'audit' => true,
        ]);
    }

    private function draftMessageToPm(array $args, User $actor): array
    {
        $pm = User::where('role', 'pm')->find((int) ($args['pm_id'] ?? 0));
        if (! $pm) {
            return ['status' => 'error', 'error' => 'PM not found'];
        }

        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') {
            return ['status' => 'error', 'error' => 'Message is empty'];
        }

        $pendingId = (string) Str::uuid();
        $pending = [
            'type' => 'draft_message_to_pm',
            'pending_id' => $pendingId,
            'idempotency_key' => 'cc-pm-msg-'.$pendingId,
            'pm_id' => $pm->id,
            'pm_name' => $pm->name,
            'lead_id' => $args['lead_id'] ?? null,
            'job_id' => $args['job_id'] ?? null,
            'brand_id' => $args['brand_id'] ?? null,
            'message' => $message,
            'requires_confirmation' => true,
            'consequences' => $this->gate->consequencesFor('command_center_draft_pm_message', $args),
            'response_kind' => 'action-ready',
        ];

        $gated = $this->gate->run('command_center_draft_pm_message', $actor, [
            'message' => $message,
            'lead_id' => $args['lead_id'] ?? null,
            'job_id' => $args['job_id'] ?? null,
            'brand_id' => $args['brand_id'] ?? null,
            'confirmed' => false,
            'trigger_event' => 'admin_command_center',
            'recipient' => $pm->name,
            'preview' => $pending,
            'linked_type' => ! empty($args['lead_id']) ? 'lead' : null,
            'linked_id' => $args['lead_id'] ?? null,
        ], function () {
            return ['should_not_execute' => true];
        });

        if (($gated['status'] ?? '') === 'blocked') {
            return [
                'status' => 'blocked',
                'reason' => $gated['reason'] ?? 'blocked',
                'ai_action_log_id' => $gated['log']->id ?? null,
            ];
        }

        if (($gated['status'] ?? '') === 'simulated') {
            return [
                'status' => 'simulated',
                'pending_action' => null,
                'proposed_action' => $pending,
                'ai_action_log_id' => $gated['log']->id ?? null,
                'instruction' => 'Simulation only — no message will be sent.',
            ];
        }

        return [
            'status' => 'draft_pending_approval',
            'pending_action' => $pending,
            'ai_action_log_id' => $gated['log']->id ?? null,
            'instruction' => 'Show this draft with consequences and ask for explicit confirmation before sending.',
            'response_kind' => 'action-ready',
        ];
    }

    private function createNextAction(array $args, User $actor): array
    {
        $lead = Lead::find((int) ($args['lead_id'] ?? 0));
        if (! $lead) {
            return ['status' => 'error', 'error' => 'Lead not found'];
        }

        $gated = $this->gate->run('command_center_create_next_action', $actor, [
            'lead_id' => $lead->id,
            'lead' => $lead,
            'brand_id' => $lead->brand_id,
            'confirmed' => true,
            'trigger_event' => 'admin_command_center',
            'linked_type' => 'lead',
            'linked_id' => $lead->id,
            'preview' => ['description' => $args['description'] ?? null],
        ], function () use ($args, $lead) {
            $dueHours = max(1, (int) ($args['due_hours'] ?? 24));
            $role = in_array($args['responsible_role'] ?? 'pm', ['owner', 'pm'], true)
                ? $args['responsible_role']
                : 'pm';

            $na = NextAction::create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id,
                'action_description' => (string) $args['description'],
                'responsible_role' => $role,
                'responsible_user_id' => $args['responsible_user_id'] ?? $lead->assigned_pm_id,
                'due_at' => now()->addHours($dueHours),
                'status' => 'pending',
                'last_action_at' => now(),
                'escalation_rule' => 'admin_command_center',
            ]);

            return [
                'status' => 'executed',
                'next_action_id' => $na->id,
            ];
        });

        if (($gated['status'] ?? '') === 'simulated') {
            return [
                'status' => 'simulated',
                'proposed' => $args,
                'ai_action_log_id' => $gated['log']->id ?? null,
            ];
        }

        if (($gated['status'] ?? '') !== 'executed') {
            return [
                'status' => $gated['status'] ?? 'blocked',
                'reason' => $gated['reason'] ?? $gated['decision']['reason'] ?? 'blocked',
                'ai_action_log_id' => $gated['log']->id ?? null,
            ];
        }

        return array_merge($gated['result'] ?? [], [
            'ai_action_log_id' => $gated['log']->id ?? null,
            'response_kind' => 'action-ready',
        ]);
    }
}
