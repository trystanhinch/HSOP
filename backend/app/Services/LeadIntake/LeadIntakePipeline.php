<?php

namespace App\Services\LeadIntake;

use App\Contracts\AiProviderInterface;
use App\Models\AiActionLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use App\Services\ActivityTimelineService;
use App\Services\AiActionAuthorizer;
use App\Services\LeadCustomerResolver;
use Illuminate\Database\Eloquent\Model;

class LeadIntakePipeline
{
    public function __construct(
        private LeadEmailParser $parser,
        private DuplicateLeadDetector $duplicateDetector,
        private CompanySourceMatcher $sourceMatcher,
        private AiProviderInterface $aiProvider,
        private ActivityTimelineService $timeline,
        private LeadCustomerResolver $customerResolver,
        private LeadIntakeMessagingService $messaging,
        private AiActionAuthorizer $authorizer,
    ) {}

    public function process(string $rawEmail, bool $sendNotifications = true): LeadIntakeResult
    {
        $parsed = $this->parser->parse($rawEmail);
        $duplicate = $this->duplicateDetector->detect($parsed);

        if ($duplicate['is_duplicate']) {
            return $this->handleDuplicate($parsed, $duplicate, $sendNotifications);
        }

        $leadData = $parsed->toArray();
        $classification = $this->aiProvider->classifyLead($leadData);
        $aiSummary = $this->aiProvider->summarizeLead(array_merge($leadData, [
            'service_category' => $classification['service_category'],
        ]));

        $companySource = $this->sourceMatcher->matchByCategory($classification['service_category'] ?? null)
            ?? $this->sourceMatcher->match($parsed->sourceLabel ?? $parsed->sourceWebsite);
        $aiUser = User::aiSuperAdmin();

        $needsReview = $parsed->needsManualReview
            || $parsed->isVoicemail()
            || ($classification['flags']['ambiguous_service'] ?? false)
            || (($classification['service_category'] ?? null) === null);

        $sourceLabel = $classification['source_label']
            ?? $parsed->sourceLabel
            ?? $parsed->sourceWebsite;

        $lead = Lead::create([
            'contact_name' => $parsed->contactName() ?: 'Unknown caller',
            'phone' => $parsed->phone,
            'email' => $parsed->email,
            'address' => $parsed->address,
            'service_category' => $classification['service_category'],
            'source' => $sourceLabel ?? $parsed->sourceWebsite,
            'company_source_id' => $companySource?->id,
            'project_description' => $parsed->projectDescription ?: $parsed->serviceRequested,
            'notes' => $this->buildInternalNotes($parsed, $classification, $aiSummary),
            'raw_email_copy' => $parsed->rawCopy,
            'parse_metadata' => [
                'field_confidence' => $parsed->fieldConfidence,
                'classification' => $classification,
                'marketing_consent' => $parsed->marketingConsent,
                'submitted_at' => $parsed->submittedAt,
                'email_format' => $parsed->emailFormat,
                'subject' => $parsed->subject,
                'source_label' => $sourceLabel,
                'recording_url' => $parsed->recordingUrl,
                'call_duration' => $parsed->callDuration,
                'call_city' => $parsed->callCity,
                'ai_usage' => $classification['usage'] ?? null,
            ],
            'needs_manual_review' => $needsReview,
            'assigned_pm_id' => $companySource?->default_pm_id,
            'status' => 'new',
        ]);

        $this->customerResolver->resolveForLead($lead->fresh());
        $lead->refresh();

        $this->createNextAction($lead, $aiUser);
        $this->recordAiLog($aiUser, $lead, 'create_lead', $parsed, $classification, $aiSummary);

        $this->timeline->record(
            $lead,
            'lead_intake',
            'Lead created from email intake pipeline.',
            $aiUser,
            [
                'source' => $parsed->sourceWebsite,
                'duplicate' => false,
                'needs_manual_review' => $needsReview,
            ],
        );

        $notifications = [];
        $aiLogs = AiActionLog::where('trigger_event', 'lead_intake')
            ->where('data_viewed->lead_id', $lead->id)
            ->get()
            ->all();

        if ($sendNotifications) {
            $aiEnabled = $this->authorizer->isAiEnabled();
            $lead->load('companySource', 'assignedPm');
            $notifications = $this->messaging->handle($lead, $parsed, $aiSummary, $aiEnabled);
            $aiLogs = AiActionLog::where('trigger_event', 'lead_intake')
                ->latest()
                ->take(5)
                ->get()
                ->all();
        }

        return new LeadIntakeResult(
            parsed: $parsed,
            duplicate: false,
            duplicateMatchType: null,
            lead: $lead->fresh(['companySource', 'assignedPm']),
            classification: $classification,
            aiSummary: $aiSummary,
            companySourceId: $companySource?->id,
            notifications: $notifications,
            aiActionLogs: array_map(fn ($l) => ['id' => $l->id], $aiLogs),
        );
    }

    /**
     * @param  array{is_duplicate: bool, match_type: ?string, lead: ?Lead, customer: ?Customer}  $duplicate
     */
    private function handleDuplicate(ParsedLeadEmail $parsed, array $duplicate, bool $sendNotifications): LeadIntakeResult
    {
        $aiUser = User::aiSuperAdmin();
        $subject = $duplicate['lead'] ?? $duplicate['customer'];

        if ($subject instanceof Lead) {
            $lead = $subject;
        } elseif ($subject instanceof Customer) {
            $lead = Lead::query()->where('customer_id', $subject->user_id)->latest()->first();
        } else {
            $lead = null;
        }

        if ($subject instanceof Model) {
            $this->timeline->record(
                $subject,
                'lead_intake_duplicate',
                'Duplicate lead submission received — attached to existing record ('.$duplicate['match_type'].').',
                $aiUser,
                [
                    'match_type' => $duplicate['match_type'],
                    'parsed' => $parsed->toArray(),
                    'raw_email_copy' => $parsed->rawCopy,
                ],
            );
        }

        $this->recordAiLog($aiUser, $lead, 'create_internal_note', $parsed, null, null, [
            'duplicate' => true,
            'match_type' => $duplicate['match_type'],
        ]);

        return new LeadIntakeResult(
            parsed: $parsed,
            duplicate: true,
            duplicateMatchType: $duplicate['match_type'],
            lead: $lead,
            classification: null,
            aiSummary: null,
            companySourceId: null,
            notifications: $sendNotifications ? ['skipped' => 'duplicate'] : [],
            aiActionLogs: [],
        );
    }

    private function createNextAction(Lead $lead, ?User $aiUser): NextAction
    {
        $dueAt = app(\App\Services\Workflow\WorkflowSettings::class)->pmContactDueAt();

        if ($lead->assigned_pm_id) {
            if ($lead->status === 'new') {
                $lead->update(['status' => 'pm_assigned']);
            }

            return NextAction::create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->id,
                'action_description' => 'Contact customer about this new lead.',
                'responsible_role' => 'pm',
                'responsible_user_id' => $lead->assigned_pm_id,
                'due_at' => $dueAt,
                'status' => 'pending',
                'escalation_rule' => 'pm_contact_lead',
                'last_action_at' => now(),
            ]);
        }

        return NextAction::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'action_description' => 'Assign a PM to this lead — no default PM found for this source.',
            'responsible_role' => 'owner',
            'responsible_user_id' => null,
            'due_at' => $dueAt,
            'status' => 'pending',
            'escalation_rule' => 'assign_pm',
            'last_action_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $classification
     * @param  array<string, mixed>  $extra
     */
    private function recordAiLog(
        ?User $aiUser,
        ?Lead $lead,
        string $action,
        ParsedLeadEmail $parsed,
        ?array $classification,
        ?string $aiSummary,
        array $extra = [],
    ): void {
        AiActionLog::create([
            'trigger_event' => 'lead_intake',
            'actor_id' => $aiUser?->id,
            'data_viewed' => array_merge([
                'lead_id' => $lead?->id,
                'parsed' => $parsed->toArray(),
                'classification' => $classification,
                'ai_summary' => $aiSummary,
            ], $extra),
            'decision' => ($extra['duplicate'] ?? false) ? 'duplicate_attached' : 'created',
            'action_taken' => $action,
            'message_sent' => null,
            'recipient' => null,
            'status_before' => null,
            'status_after' => $lead?->status,
            'rule_applied' => 'lead_intake_pipeline',
            'required_human_approval' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function buildInternalNotes(ParsedLeadEmail $parsed, array $classification, string $aiSummary): string
    {
        $lines = [
            'AI summary: '.$aiSummary,
            'Urgency: '.($classification['urgency'] ?? 'normal'),
            'Provider: '.($classification['provider'] ?? 'unknown'),
        ];

        if ($parsed->sourceLabel) {
            $lines[] = 'Source label: '.$parsed->sourceLabel;
        }

        if ($parsed->isVoicemail()) {
            $lines[] = 'Format: voicemail — listen to recording before contacting.';
            if ($parsed->recordingUrl) {
                $lines[] = 'Recording: '.$parsed->recordingUrl;
            }
            if ($parsed->callDuration) {
                $lines[] = 'Call duration: '.$parsed->callDuration;
            }
        }

        if ($parsed->marketingConsent !== null) {
            $lines[] = 'Marketing consent: '.($parsed->marketingConsent ? 'yes' : 'no');
        }

        if ($parsed->submittedAt) {
            $lines[] = 'Submitted: '.$parsed->submittedAt;
        }

        return implode("\n", $lines);
    }
}
