<?php

namespace App\Services\LeadIntake;

use App\Contracts\AiProviderInterface;
use App\Models\AiActionLog;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\IntakeSession;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use App\Services\ActivityTimelineService;
use App\Services\LeadCustomerResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin adapter: public intake session → same Lead::create path as email intake.
 * Brand/company_source come from the session's resolved brand — never hardcoded.
 */
class PublicIntakePipeline
{
    public function __construct(
        private DuplicateLeadDetector $duplicateDetector,
        private CompanySourceMatcher $sourceMatcher,
        private AiProviderInterface $aiProvider,
        private ActivityTimelineService $timeline,
        private LeadCustomerResolver $customerResolver,
    ) {}

    /**
     * @param  array{send_notifications?: bool}  $options
     */
    public function submit(IntakeSession $session, array $options = []): LeadIntakeResult
    {
        $sendNotifications = (bool) ($options['send_notifications'] ?? false);
        $brand = $session->brand ?: Brand::find($session->brand_id);
        if (! $brand) {
            throw new \RuntimeException('Intake session has no brand.');
        }

        $parsed = $this->toParsedLead($session, $brand);
        $duplicate = $this->duplicateDetector->detect($parsed);

        if ($duplicate['is_duplicate']) {
            return $this->handleDuplicate($session, $brand, $parsed, $duplicate, $sendNotifications);
        }

        $leadData = $parsed->toArray();
        $collectedCategory = $session->conversation_state['collected']['service_category'] ?? null;

        // Prefer explicit collected category from brand catalog; classifyLead may still help email-style summary
        $classification = $this->aiProvider->classifyLead($leadData);
        $aiSummary = $this->aiProvider->summarizeLead(array_merge($leadData, [
            'service_category' => $collectedCategory ?? $classification['service_category'] ?? null,
        ]));

        $companySource = $this->resolveCompanySource($brand, $collectedCategory ?? $classification['service_category'] ?? null);
        $aiUser = User::aiSuperAdmin();

        $allowedKeys = array_column($brand->serviceCatalog(), 'key');
        $serviceCategory = $collectedCategory;
        if ($serviceCategory && $allowedKeys !== [] && ! in_array($serviceCategory, $allowedKeys, true)) {
            $serviceCategory = null;
        }
        if (! $serviceCategory) {
            $serviceCategory = $classification['service_category'] ?? null;
            if ($serviceCategory && $allowedKeys !== [] && ! in_array($serviceCategory, $allowedKeys, true)) {
                // Map legacy classifier keys onto brand catalog when possible
                $serviceCategory = $this->mapClassifierToBrand($serviceCategory, $brand);
            }
        }

        $needsReview = $parsed->needsManualReview
            || ($classification['flags']['ambiguous_service'] ?? false)
            || $serviceCategory === null;

        $lead = Lead::create([
            'contact_name' => $parsed->contactName() ?: 'Website visitor',
            'phone' => $parsed->phone,
            'email' => $parsed->email,
            'address' => $parsed->address,
            'service_category' => $serviceCategory,
            'source' => 'website',
            'intake_channel' => 'website_chat',
            'conversation_id' => $session->id,
            'brand_id' => $brand->id,
            'company_source_id' => $companySource?->id,
            'project_description' => $parsed->projectDescription ?: $parsed->serviceRequested,
            'notes' => $this->buildInternalNotes($parsed, $classification, $aiSummary, $brand),
            'raw_email_copy' => $parsed->rawCopy,
            'parse_metadata' => [
                'intake_channel' => 'website_chat',
                'conversation_id' => $session->id,
                'brand_id' => $brand->id,
                'brand_domain' => $brand->domain,
                'brand_slug' => $brand->slug,
                'field_confidence' => $parsed->fieldConfidence,
                'classification' => $classification,
                'marketing_consent' => $parsed->marketingConsent,
                'submitted_at' => $parsed->submittedAt,
                'conversation_transcript' => $session->messages(),
                'collected_fields' => $session->conversation_state['collected'] ?? [],
                'ai_usage' => $classification['usage'] ?? null,
            ],
            'needs_manual_review' => $needsReview,
            'assigned_pm_id' => $companySource?->default_pm_id,
            'status' => 'new',
        ]);

        $this->customerResolver->resolveForLead($lead->fresh());
        $lead->refresh();

        $this->createNextAction($lead, $aiUser);
        $this->recordAiLog($aiUser, $lead, 'create_lead', $parsed, $classification, $aiSummary, [
            'brand_id' => $brand->id,
        ]);

        $this->timeline->record(
            $lead,
            'lead_intake',
            'Lead created from public website intake.',
            $aiUser,
            [
                'source' => 'website',
                'intake_channel' => 'website_chat',
                'conversation_id' => $session->id,
                'brand_id' => $brand->id,
                'duplicate' => false,
                'needs_manual_review' => $needsReview,
            ],
        );

        $session->update(['converted_lead_id' => $lead->id]);

        return new LeadIntakeResult(
            parsed: $parsed,
            duplicate: false,
            duplicateMatchType: null,
            lead: $lead->fresh(['companySource', 'assignedPm', 'brand']),
            classification: $classification,
            aiSummary: $aiSummary,
            companySourceId: $companySource?->id,
            notifications: $sendNotifications ? ['skipped' => 'phase1_no_public_notifications'] : [],
            aiActionLogs: [],
        );
    }

    public function toParsedLead(IntakeSession $session, Brand $brand): ParsedLeadEmail
    {
        $collected = $session->conversation_state['collected'] ?? [];
        $messages = $session->messages();
        $transcript = $this->formatTranscript($messages);

        $name = trim((string) ($collected['contact_name'] ?? ''));
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [null, null];
        $first = $parts[0] ?? null;
        $last = $parts[1] ?? null;

        $serviceKey = $collected['service_category'] ?? null;
        $serviceLabel = null;
        foreach ($brand->serviceCatalog() as $item) {
            if ($item['key'] === $serviceKey) {
                $serviceLabel = $item['label'];
                break;
            }
        }

        $missingContact = empty($collected['phone']) && empty($collected['email']);

        return new ParsedLeadEmail(
            rawCopy: $transcript,
            firstName: $first ?: null,
            lastName: $last ?: null,
            phone: $collected['phone'] ?? null,
            email: $collected['email'] ?? null,
            serviceRequested: $serviceLabel ?: ($collected['service_requested'] ?? null),
            projectDescription: $collected['project_description'] ?? null,
            address: $collected['address'] ?? null,
            sourceWebsite: 'website',
            submittedAt: now()->toIso8601String(),
            marketingConsent: isset($collected['marketing_consent']) ? (bool) $collected['marketing_consent'] : null,
            fieldConfidence: [
                'contact_name' => $name !== '' ? 0.9 : 0.2,
                'phone' => ! empty($collected['phone']) ? 0.9 : 0.1,
                'email' => ! empty($collected['email']) ? 0.9 : 0.1,
                'project_description' => ! empty($collected['project_description']) ? 0.8 : 0.2,
            ],
            needsManualReview: $missingContact || empty($collected['project_description']),
            subject: 'Website chat intake',
            emailFormat: 'form',
            sourceLabel: $brand->company_name,
        );
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function formatTranscript(array $messages): string
    {
        if ($messages === []) {
            return '[empty website chat transcript]';
        }

        $lines = [];
        foreach ($messages as $msg) {
            $role = strtoupper((string) ($msg['role'] ?? 'user'));
            $content = trim((string) ($msg['content'] ?? ''));
            $lines[] = $role.': '.$content;
        }

        return implode("\n", $lines);
    }

    private function resolveCompanySource(Brand $brand, ?string $serviceCategory): ?CompanySource
    {
        if ($brand->company_source_id) {
            return $brand->companySource ?: CompanySource::find($brand->company_source_id);
        }

        // Fallback only if brand misconfigured — still generic, not brand-named
        return $this->sourceMatcher->matchByCategory($serviceCategory)
            ?? $this->sourceMatcher->match('website');
    }

    private function mapClassifierToBrand(string $classifierKey, Brand $brand): ?string
    {
        foreach ($brand->serviceCatalog() as $item) {
            if ($item['key'] === $classifierKey) {
                return $item['key'];
            }
            if (in_array($classifierKey, $item['keywords'], true)) {
                return $item['key'];
            }
        }

        return null;
    }

    /**
     * @param  array{is_duplicate: bool, match_type: ?string, lead: ?Lead, customer: ?\App\Models\Customer}  $duplicate
     */
    private function handleDuplicate(
        IntakeSession $session,
        Brand $brand,
        ParsedLeadEmail $parsed,
        array $duplicate,
        bool $sendNotifications,
    ): LeadIntakeResult {
        $aiUser = User::aiSuperAdmin();
        $subject = $duplicate['lead'] ?? $duplicate['customer'];
        $lead = $duplicate['lead'] ?? null;

        if ($subject instanceof Model) {
            $this->timeline->record(
                $subject,
                'lead_intake_duplicate',
                'Duplicate website intake — attached to existing record ('.$duplicate['match_type'].').',
                $aiUser,
                [
                    'match_type' => $duplicate['match_type'],
                    'intake_channel' => 'website_chat',
                    'conversation_id' => $session->id,
                    'brand_id' => $brand->id,
                    'parsed' => $parsed->toArray(),
                ],
            );
        }

        $this->recordAiLog($aiUser, $lead, 'create_internal_note', $parsed, null, null, [
            'duplicate' => true,
            'match_type' => $duplicate['match_type'],
            'intake_channel' => 'website_chat',
            'brand_id' => $brand->id,
        ]);

        if ($lead) {
            $session->update(['converted_lead_id' => $lead->id]);
        }

        return new LeadIntakeResult(
            parsed: $parsed,
            duplicate: true,
            duplicateMatchType: $duplicate['match_type'],
            lead: $lead,
            classification: null,
            aiSummary: null,
            companySourceId: $lead?->company_source_id,
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
                'action_description' => 'Contact customer about this new website lead.',
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
            'action_description' => 'Assign a PM to this website lead — no default PM found for this source.',
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
            'trigger_event' => 'public_intake',
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
            'rule_applied' => 'public_intake_pipeline',
            'required_human_approval' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function buildInternalNotes(ParsedLeadEmail $parsed, array $classification, string $aiSummary, Brand $brand): string
    {
        return implode("\n", [
            'AI summary: '.$aiSummary,
            'Urgency: '.($classification['urgency'] ?? 'normal'),
            'Provider: '.($classification['provider'] ?? 'unknown'),
            'Intake channel: website_chat',
            'Brand: '.$brand->company_name.' ('.$brand->domain.')',
        ]);
    }
}
