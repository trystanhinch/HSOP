<?php

namespace App\Services\CommandCenter;

use App\Models\AiActionLog;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\SmsService;
use Illuminate\Support\Str;

class CommandCenterActionService
{
    public function __construct(
        private SmsService $sms,
        private AiActionAuthorizer $authorizer,
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
                    'description' => 'Draft an SMS/message to a PM about a lead or job. Does NOT send until owner confirms.',
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
                    'description' => 'Create a pending NextAction for owner/PM follow-up (low risk, executes immediately unless kill switch on).',
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

    public function dispatch(string $name, array $args, User $owner, bool $allowWrites): array
    {
        if (! $allowWrites) {
            return [
                'status' => 'blocked',
                'reason' => 'AI kill switch is on — actions are blocked. Read-only questions still work.',
                'action' => $name,
            ];
        }

        return match ($name) {
            'draft_message_to_pm' => $this->draftMessageToPm($args, $owner),
            'create_owner_next_action' => $this->createNextAction($args, $owner),
            default => ['status' => 'error', 'error' => 'Unknown action tool: '.$name],
        };
    }

    public function confirmPending(array $pending, User $owner): array
    {
        if (! $this->authorizer->isAiEnabled()) {
            return ['status' => 'blocked', 'reason' => 'AI kill switch is on'];
        }

        if (($pending['type'] ?? '') !== 'draft_message_to_pm') {
            return ['status' => 'error', 'error' => 'Unsupported pending action'];
        }

        $pm = User::where('role', 'pm')->find($pending['pm_id'] ?? 0);
        if (! $pm) {
            return ['status' => 'error', 'error' => 'PM not found'];
        }

        $message = (string) ($pending['message'] ?? '');
        $result = $this->sms->sendToUser($pm, $message, 'admin_command_center_pm_message', $pending['job_id'] ?? null);

        $log = AiActionLog::create([
            'trigger_event' => 'admin_command_center',
            'actor_id' => $owner->id,
            'data_viewed' => ['pending' => $pending, 'sms' => ['status' => $result['status'] ?? null]],
            'decision' => 'executed',
            'action_taken' => 'send_pm_message',
            'message_sent' => $message,
            'recipient' => $pm->phone ?? $pm->email,
            'rule_applied' => 'owner_confirmed_draft',
            'required_human_approval' => true,
        ]);

        return [
            'status' => 'executed',
            'pm_id' => $pm->id,
            'pm_name' => $pm->name,
            'ai_action_log_id' => $log->id,
            'sms_status' => $result['status'] ?? null,
        ];
    }

    private function draftMessageToPm(array $args, User $owner): array
    {
        $pm = User::where('role', 'pm')->find((int) ($args['pm_id'] ?? 0));
        if (! $pm) {
            return ['status' => 'error', 'error' => 'PM not found'];
        }

        $message = trim((string) ($args['message'] ?? ''));
        if ($message === '') {
            return ['status' => 'error', 'error' => 'Message is empty'];
        }

        $pending = [
            'type' => 'draft_message_to_pm',
            'pending_id' => (string) Str::uuid(),
            'pm_id' => $pm->id,
            'pm_name' => $pm->name,
            'lead_id' => $args['lead_id'] ?? null,
            'job_id' => $args['job_id'] ?? null,
            'message' => $message,
            'requires_confirmation' => true,
        ];

        $log = AiActionLog::create([
            'trigger_event' => 'admin_command_center',
            'actor_id' => $owner->id,
            'data_viewed' => $pending,
            'decision' => 'draft_pending_approval',
            'action_taken' => 'draft_message_to_pm',
            'message_sent' => $message,
            'recipient' => $pm->name,
            'rule_applied' => 'send_customer_message requires_human_approval → draft first',
            'required_human_approval' => true,
        ]);

        return [
            'status' => 'draft_pending_approval',
            'pending_action' => $pending,
            'ai_action_log_id' => $log->id,
            'instruction' => 'Show this draft to the owner and ask them to confirm before sending.',
        ];
    }

    private function createNextAction(array $args, User $owner): array
    {
        $lead = Lead::find((int) ($args['lead_id'] ?? 0));
        if (! $lead) {
            return ['status' => 'error', 'error' => 'Lead not found'];
        }

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

        $log = AiActionLog::create([
            'trigger_event' => 'admin_command_center',
            'actor_id' => $owner->id,
            'data_viewed' => ['next_action_id' => $na->id, 'lead_id' => $lead->id],
            'decision' => 'executed',
            'action_taken' => 'create_next_action',
            'rule_applied' => 'create_next_action low-risk → execute',
            'required_human_approval' => false,
        ]);

        return [
            'status' => 'executed',
            'next_action_id' => $na->id,
            'ai_action_log_id' => $log->id,
        ];
    }
}
