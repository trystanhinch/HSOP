<?php

namespace App\Services\Workflow;

use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkflowEscalationLog;
use App\Services\AiActionAuthorizer;
use App\Services\ActivityTimelineService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use Throwable;

class EscalationEngine
{
    public function __construct(
        private WorkflowSettings $settings,
        private AiActionAuthorizer $authorizer,
        private ActivityTimelineService $timeline,
        private SmsService $sms,
    ) {}

    /**
     * @return array{processed: int, reminded: int, escalated: int, drafts: int, skipped: int}
     */
    public function run(): array
    {
        $stats = ['processed' => 0, 'reminded' => 0, 'escalated' => 0, 'drafts' => 0, 'skipped' => 0];

        $actions = NextAction::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->with(['subject', 'responsibleUser'])
            ->limit(100)
            ->get();

        foreach ($actions as $action) {
            $stats['processed']++;
            try {
                $result = $this->processAction($action);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            } catch (Throwable $e) {
                Log::warning('EscalationEngine failed for next action', [
                    'next_action_id' => $action->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }
        }

        $this->sweepQuoteFollowUps($stats);
        $this->sweepContractorPricing($stats);

        return $stats;
    }

    private function processAction(NextAction $action): string
    {
        if ($action->status === 'pending') {
            $action->update(['status' => 'overdue', 'last_action_at' => now()]);
        }

        $rule = $action->escalation_rule ?: $this->inferRule($action);
        $desc = strtolower($action->action_description ?? '');

        if (str_contains($desc, 'contact customer') || $rule === 'pm_contact_lead') {
            return $this->handlePmContactOverdue($action);
        }

        return 'skipped';
    }

    private function handlePmContactOverdue(NextAction $action): string
    {
        $mode = $this->authorizer->getModuleMode('escalations');
        $killSwitch = ! $this->authorizer->isAiEnabled();

        if (! $this->alreadyFired($action, 'pm_contact_lead', 'reminder')) {
            $this->markFired($action, 'pm_contact_lead', 'reminder');

            if ($killSwitch) {
                $this->logAi('escalation_reminder_flagged', $action, 'Kill switch on — flagged overdue only', 'pm_contact_lead');

                return 'reminded';
            }

            if ($mode === 'suggestion') {
                $this->createDraftReminder($action, 'PM contact overdue — draft reminder ready for approval.');
                $this->logAi('escalation_reminder_draft', $action, 'suggestion mode draft', 'pm_contact_lead');

                return 'drafts';
            }

            $this->notifyPmReminder($action);
            $this->logAi('escalation_reminder_sent', $action, 'PM reminder sent', 'pm_contact_lead');

            return 'reminded';
        }

        $escalationHours = (int) $this->settings->get('pm_contact_escalation_hours');
        $reminderAt = WorkflowEscalationLog::query()
            ->where('next_action_id', $action->id)
            ->where('rule_key', 'pm_contact_lead')
            ->where('stage', 'reminder')
            ->value('fired_at');

        if (! $reminderAt || now()->lt($reminderAt->copy()->addHours($escalationHours))) {
            return 'skipped';
        }

        if ($this->alreadyFired($action, 'pm_contact_lead', 'escalation')) {
            return 'skipped';
        }

        $this->markFired($action, 'pm_contact_lead', 'escalation');
        $action->update(['status' => 'escalated', 'last_action_at' => now()]);

        $owner = User::query()->where('role', 'owner')->orderBy('id')->first();
        if ($owner && $action->subject instanceof Lead) {
            NextAction::create([
                'subject_type' => $action->subject->getMorphClass(),
                'subject_id' => $action->subject->getKey(),
                'action_description' => 'Escalation: PM has not contacted lead — Owner follow-up required.',
                'responsible_role' => 'owner',
                'responsible_user_id' => $owner->id,
                'due_at' => now()->addHours(4),
                'status' => 'pending',
                'escalation_rule' => 'owner_pm_contact_escalation',
                'last_action_at' => now(),
            ]);

            $this->timeline->record(
                $action->subject,
                'escalation_to_owner',
                'Lead contact overdue escalated to Owner.',
                User::aiSuperAdmin(),
                ['next_action_id' => $action->id]
            );
        }

        if (! $killSwitch && in_array($mode, ['assisted', 'autopilot'], true) && $owner) {
            $this->sms->sendToUser(
                $owner,
                MessageTemplate::render(
                    'pm_contact_escalation_owner',
                    [
                        'pm_name' => $action->responsibleUser?->name ?? 'PM',
                        'lead_name' => $action->subject instanceof Lead ? ($action->subject->contact_name ?? 'lead') : 'lead',
                        'lead_id' => (string) ($action->subject_id ?? ''),
                    ],
                    'ServiceOP escalation: PM has not contacted lead #{{lead_id}} ({{lead_name}}). Please follow up.'
                ),
                'pm_contact_escalation',
                $action->subject_id
            );
        }

        $this->logAi('escalation_to_owner', $action, 'Escalated to owner', 'pm_contact_lead');

        return 'escalated';
    }

    private function notifyPmReminder(NextAction $action): void
    {
        $pm = $action->responsibleUser;
        if (! $pm) {
            return;
        }

        $leadName = $action->subject instanceof Lead ? ($action->subject->contact_name ?? 'a lead') : 'an item';
        $body = MessageTemplate::render(
            'pm_contact_reminder',
            [
                'pm_name' => $pm->name,
                'lead_name' => $leadName,
                'lead_id' => (string) $action->subject_id,
            ],
            'Hi {{pm_name}}, reminder: contact {{lead_name}} (lead #{{lead_id}}) — overdue.'
        );

        $this->sms->sendToUser($pm, $body, 'pm_contact_reminder', $action->subject_id);
    }

    private function createDraftReminder(NextAction $action, string $note): void
    {
        if ($action->subject) {
            $this->timeline->record(
                $action->subject,
                'escalation_draft',
                $note,
                User::aiSuperAdmin(),
                ['next_action_id' => $action->id, 'requires_approval' => true]
            );
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function sweepQuoteFollowUps(array &$stats): void
    {
        $hours = (int) $this->settings->get('quote_follow_up_hours');
        $quotes = Quote::query()
            ->whereIn('status', ['sent', 'viewed'])
            ->where('updated_at', '<=', now()->subHours($hours))
            ->with('job.pm')
            ->limit(50)
            ->get();

        foreach ($quotes as $quote) {
            $key = 'quote_follow_up_'.$quote->id;
            if (WorkflowEscalationLog::query()->where('rule_key', $key)->where('stage', 'follow_up')->exists()) {
                continue;
            }

            // Store against a synthetic next_action_id of 0 is invalid FK — use meta on a log only if we have a next action.
            // Create / update next action on the job instead.
            $job = $quote->job;
            if (! $job) {
                continue;
            }

            $na = NextAction::query()
                ->where('subject_type', $job->getMorphClass())
                ->where('subject_id', $job->id)
                ->where('escalation_rule', 'quote_follow_up')
                ->whereIn('status', ['pending', 'overdue', 'escalated'])
                ->latest('id')
                ->first();

            if (! $na) {
                $na = NextAction::create([
                    'subject_type' => $job->getMorphClass(),
                    'subject_id' => $job->id,
                    'escalation_rule' => 'quote_follow_up',
                    'action_description' => 'Follow up with customer on quote #'.$quote->id,
                    'responsible_role' => 'pm',
                    'responsible_user_id' => $job->pm_id,
                    'due_at' => now(),
                    'status' => 'pending',
                    'last_action_at' => now(),
                ]);
            }

            if ($this->alreadyFired($na, 'quote_follow_up', 'follow_up')) {
                continue;
            }

            $this->markFired($na, 'quote_follow_up', 'follow_up', ['quote_id' => $quote->id]);
            $quote->update(['status' => 'follow_up']);
            $stats['reminded']++;

            $this->logAi('quote_follow_up', $na, 'Quote follow-up flagged', 'quote_follow_up');
        }
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function sweepContractorPricing(array &$stats): void
    {
        $hours = (int) $this->settings->get('contractor_pricing_deadline_hours');

        $leads = Lead::query()
            ->where('status', 'site_visit_scheduled')
            ->whereNull('contractor_price')
            ->whereNotNull('site_visit_date')
            ->where('site_visit_date', '<=', now()->subHours($hours)->toDateString())
            ->limit(50)
            ->get();

        foreach ($leads as $lead) {
            $na = $lead->pendingNextAction
                ?? NextAction::firstOrCreate(
                    [
                        'subject_type' => $lead->getMorphClass(),
                        'subject_id' => $lead->id,
                        'escalation_rule' => 'contractor_pricing_overdue',
                    ],
                    [
                        'action_description' => 'Contractor pricing overdue after site visit',
                        'responsible_role' => 'contractor',
                        'responsible_user_id' => $lead->assigned_contractor_id ?? $lead->site_visit_contractor_id,
                        'due_at' => now(),
                        'status' => 'overdue',
                        'last_action_at' => now(),
                    ]
                );

            if ($this->alreadyFired($na, 'contractor_pricing_overdue', 'reminder')) {
                continue;
            }

            $this->markFired($na, 'contractor_pricing_overdue', 'reminder');
            $stats['reminded']++;
            $this->logAi('contractor_pricing_reminder', $na, 'Contractor pricing overdue', 'contractor_pricing_overdue');

            if ($this->authorizer->isAiEnabled() && $this->authorizer->getModuleMode('escalations') !== 'suggestion') {
                if ($lead->assignedPm) {
                    $this->sms->sendToUser(
                        $lead->assignedPm,
                        MessageTemplate::render(
                            'contractor_pricing_reminder_pm',
                            ['lead_name' => $lead->contact_name, 'lead_id' => (string) $lead->id],
                            'ServiceOP: Contractor pricing still outstanding for {{lead_name}} (lead #{{lead_id}}).'
                        ),
                        'contractor_pricing_reminder',
                        $lead->id
                    );
                }
            }
        }
    }

    private function inferRule(NextAction $action): string
    {
        return $action->escalation_rule ?: 'generic';
    }

    private function alreadyFired(NextAction $action, string $rule, string $stage): bool
    {
        return WorkflowEscalationLog::query()
            ->where('next_action_id', $action->id)
            ->where('rule_key', $rule)
            ->where('stage', $stage)
            ->exists();
    }

    private function markFired(NextAction $action, string $rule, string $stage, array $meta = []): void
    {
        WorkflowEscalationLog::create([
            'next_action_id' => $action->id,
            'rule_key' => $rule,
            'stage' => $stage,
            'fired_at' => now(),
            'meta' => $meta ?: null,
        ]);
    }

    private function logAi(string $event, NextAction $action, string $decision, string $rule): void
    {
        try {
            $actor = User::aiSuperAdmin();
            if (! $actor) {
                return;
            }

            AiActionLog::create([
                'trigger_event' => $event,
                'actor_id' => $actor->id,
                'data_viewed' => [
                    'next_action_id' => $action->id,
                    'subject_type' => $action->subject_type,
                    'subject_id' => $action->subject_id,
                ],
                'decision' => $decision,
                'action_taken' => $event,
                'message_sent' => null,
                'recipient' => null,
                'status_before' => 'pending',
                'status_after' => $action->fresh()?->status,
                'rule_applied' => $rule,
                'required_human_approval' => $this->authorizer->getModuleMode('escalations') === 'suggestion',
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write escalation AiActionLog', ['error' => $e->getMessage()]);
        }
    }
}
