<?php

namespace App\Services\LeadIntake;

use App\Models\IntakeAuditLog;
use App\Models\IntakeQuarantine;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityTimelineService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LeadIntakeQuarantineService
{
    public function __construct(
        private LeadIntakeQuarantineEvaluator $evaluator,
        private LeadIntakePipeline $pipeline,
        private ActivityTimelineService $timeline,
    ) {}

    /**
     * Entry for Gmail (and test harness): evaluate → ignore / quarantine / auto-create.
     *
     * @param  array{
     *   channel?: string,
     *   mailbox_email?: ?string,
     *   gmail_message_id?: ?string,
     *   gmail_thread_id?: ?string,
     *   send_notifications?: bool,
     *   is_test_data?: bool
     * }  $context
     */
    public function ingest(string $rawEmail, ParsedLeadEmail $parsed, array $context = []): LeadIntakeResult
    {
        $sendNotifications = (bool) ($context['send_notifications'] ?? true);
        $isTest = (bool) ($context['is_test_data'] ?? false);
        $evaluation = $this->evaluator->evaluate($parsed, $rawEmail);

        // Voicemail duplicate merge within window
        if ($evaluation['duplicate_group_key']) {
            $merged = $this->tryMergeVoicemailDuplicate(
                $evaluation['duplicate_group_key'],
                $rawEmail,
                $parsed,
                $evaluation,
                $context,
                $isTest,
            );
            if ($merged) {
                return $merged;
            }
        }

        if ($evaluation['action'] === 'ignore') {
            $row = $this->storeQuarantine($rawEmail, $parsed, $evaluation, $context, 'ignored', $isTest);
            $this->audit($row, null, 'system', null, 'ignored', $evaluation['reason'], $rawEmail, $evaluation['field_confidence']);

            return LeadIntakeResult::ignored($parsed, $row);
        }

        if ($evaluation['action'] === 'quarantine') {
            $row = $this->storeQuarantine($rawEmail, $parsed, $evaluation, $context, 'pending', $isTest);
            $this->audit($row, null, 'system', null, 'quarantined', $evaluation['reason'], $rawEmail, $evaluation['field_confidence']);

            return LeadIntakeResult::quarantined($parsed, $row);
        }

        // Auto-approve: create lead via pipeline create-from-fields (no second quarantine pass)
        $result = $this->pipeline->createFromSanitizedFields(
            rawEmail: $rawEmail,
            parsed: $parsed,
            fields: $evaluation['parsed_fields'],
            companySourceId: $evaluation['company_source_id'],
            fieldConfidence: $evaluation['field_confidence'],
            sendNotifications: $sendNotifications,
            isTestData: $isTest,
            forceManualReview: false,
            matchedNeedle: $evaluation['matched_needle'] ?? null,
            matchMethod: $evaluation['match_method'] ?? null,
        );

        $row = $this->storeQuarantine($rawEmail, $parsed, $evaluation, $context, 'auto_approved', $isTest, $result->lead?->id);
        $this->audit(
            $row,
            $result->lead?->id,
            'system',
            null,
            'auto_approved',
            $evaluation['reason'],
            $rawEmail,
            $evaluation['field_confidence'],
            ['lead_id' => $result->lead?->id],
        );

        return $result->withQuarantine($row);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function approve(IntakeQuarantine $item, User $actor, array $overrides = [], bool $sendNotifications = true): LeadIntakeResult
    {
        if (! $item->isPending() && $item->status !== 'ignored') {
            throw new \RuntimeException('Only pending (or re-openable ignored) quarantine items can be approved.');
        }
        if ($item->converted_lead_id) {
            throw new \RuntimeException('This quarantine item was already converted to a lead.');
        }

        $fields = array_merge($item->parsed_fields ?? [], $overrides);
        // Never allow email-as-phone through approve
        if (! empty($fields['phone']) && ! $this->evaluator->isValidPhone((string) $fields['phone'])) {
            throw new \RuntimeException('Phone must be a valid phone number — email addresses are not allowed in the phone field.');
        }
        if (! empty($fields['contact_name']) && ! $this->evaluator->isAcceptableName((string) $fields['contact_name'])) {
            throw new \RuntimeException('Contact name is not acceptable.');
        }

        $parsed = $this->pipeline->getParser()->parse($item->raw_email);
        $result = $this->pipeline->createFromSanitizedFields(
            rawEmail: $item->raw_email,
            parsed: $parsed,
            fields: $fields,
            companySourceId: $item->company_source_id,
            fieldConfidence: $item->field_confidence ?? [],
            sendNotifications: $sendNotifications,
            isTestData: (bool) $item->is_test_data,
            forceManualReview: false,
            matchedNeedle: $item->matched_needle,
            matchMethod: $item->match_method,
        );

        $decision = $overrides === [] ? 'manually_approved' : 'edited_approved';
        $item->update([
            'status' => 'approved',
            'parsed_fields' => $fields,
            'converted_lead_id' => $result->lead?->id,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->audit(
            $item,
            $result->lead?->id,
            'user',
            $actor->id,
            $decision,
            'Reviewer approved quarantine item',
            $item->raw_email,
            $item->field_confidence,
            ['overrides' => $overrides],
        );

        return $result->withQuarantine($item->fresh());
    }

    public function ignore(IntakeQuarantine $item, User $actor, string $reason): IntakeQuarantine
    {
        if ($item->converted_lead_id) {
            throw new \RuntimeException('Cannot ignore an item that already created a lead.');
        }

        $item->update([
            'status' => 'ignored',
            'ignore_reason' => $reason,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $this->audit(
            $item,
            null,
            'user',
            $actor->id,
            'ignored',
            $reason,
            $item->raw_email,
            $item->field_confidence,
        );

        return $item->fresh();
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $context
     */
    private function storeQuarantine(
        string $rawEmail,
        ParsedLeadEmail $parsed,
        array $evaluation,
        array $context,
        string $status,
        bool $isTest,
        ?int $leadId = null,
    ): IntakeQuarantine {
        return IntakeQuarantine::create([
            'channel' => $context['channel'] ?? 'gmail',
            'status' => $status,
            'mailbox_email' => $context['mailbox_email'] ?? null,
            'gmail_message_id' => $context['gmail_message_id'] ?? null,
            'gmail_thread_id' => $context['gmail_thread_id'] ?? null,
            'message_id_hash' => hash('sha256', $rawEmail),
            'raw_email' => $rawEmail,
            'subject' => $evaluation['subject'] ?? $parsed->subject,
            'from_header' => $evaluation['from_header'] ?? null,
            'email_format' => $parsed->emailFormat,
            'parsed_fields' => $evaluation['parsed_fields'],
            'field_confidence' => $evaluation['field_confidence'],
            'validation_errors' => $evaluation['validation_errors'],
            'quarantine_reason' => $evaluation['reason'],
            'company_source_id' => $evaluation['company_source_id'],
            'matched_needle' => $evaluation['matched_needle'] ?? null,
            'match_method' => $evaluation['match_method'] ?? null,
            'duplicate_group_key' => $evaluation['duplicate_group_key'],
            'converted_lead_id' => $leadId,
            'is_test_data' => $isTest,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $context
     */
    private function tryMergeVoicemailDuplicate(
        string $groupKey,
        string $rawEmail,
        ParsedLeadEmail $parsed,
        array $evaluation,
        array $context,
        bool $isTest,
    ): ?LeadIntakeResult {
        $hours = (int) config('gmail.voicemail_dedupe_hours', 24);
        $since = Carbon::now()->subHours($hours);

        $existingLead = Lead::withTestData()
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($groupKey, $evaluation) {
                $q->where('parse_metadata->voicemail_duplicate_key', $groupKey);
                $phone = $evaluation['parsed_fields']['phone'] ?? null;
                if ($phone) {
                    $digits = substr(preg_replace('/\D+/', '', $phone) ?: '', -10);
                    if (strlen($digits) === 10) {
                        $q->orWhereRaw(
                            "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),'-',''),' ',''),'(',''), 10) = ?",
                            [$digits]
                        );
                    }
                }
            })
            ->where(function ($q) {
                $q->where('parse_metadata->email_format', 'voicemail')
                    ->orWhere('notes', 'like', '%Voicemail%')
                    ->orWhereNotNull('parse_metadata->recording_url');
            })
            ->latest('id')
            ->first();

        if ($existingLead) {
            $row = $this->storeQuarantine($rawEmail, $parsed, $evaluation, $context, 'ignored', $isTest, $existingLead->id);
            $row->update([
                'quarantine_reason' => 'duplicate voicemail merged into lead #'.$existingLead->id,
                'duplicate_group_key' => $groupKey,
            ]);
            $aiUser = User::aiSuperAdmin();
            $this->timeline->record(
                $existingLead,
                'lead_intake_duplicate',
                'Duplicate voicemail notification merged (same caller within '.$hours.'h).',
                $aiUser,
                [
                    'match_type' => 'voicemail_window',
                    'duplicate_group_key' => $groupKey,
                    'quarantine_id' => $row->id,
                ],
            );
            $this->audit($row, $existingLead->id, 'system', null, 'ignored', 'duplicate voicemail merged', $rawEmail, $evaluation['field_confidence'], [
                'merged_into_lead_id' => $existingLead->id,
            ]);

            return LeadIntakeResult::duplicateMerged($parsed, $existingLead, $row, 'voicemail_window');
        }

        $existingQ = IntakeQuarantine::withTestData()
            ->where('duplicate_group_key', $groupKey)
            ->where('created_at', '>=', $since)
            ->whereIn('status', ['pending', 'approved', 'auto_approved'])
            ->latest('id')
            ->first();

        if ($existingQ) {
            $row = $this->storeQuarantine($rawEmail, $parsed, $evaluation, $context, 'ignored', $isTest, $existingQ->converted_lead_id);
            $row->update([
                'quarantine_reason' => 'duplicate voicemail of quarantine #'.$existingQ->id,
                'duplicate_of_quarantine_id' => $existingQ->id,
                'duplicate_group_key' => $groupKey,
            ]);
            $this->audit($row, $existingQ->converted_lead_id, 'system', null, 'ignored', 'duplicate voicemail of quarantine', $rawEmail, $evaluation['field_confidence'], [
                'duplicate_of_quarantine_id' => $existingQ->id,
            ]);

            if ($existingQ->converted_lead_id) {
                $lead = Lead::withTestData()->find($existingQ->converted_lead_id);

                return LeadIntakeResult::duplicateMerged($parsed, $lead, $row, 'voicemail_window');
            }

            return LeadIntakeResult::quarantined($parsed, $row);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>|null  $confidence
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        IntakeQuarantine $row,
        ?int $leadId,
        string $actorType,
        ?int $actorId,
        string $decision,
        ?string $reason,
        ?string $sourceText,
        ?array $confidence,
        array $metadata = [],
    ): void {
        IntakeAuditLog::create([
            'intake_quarantine_id' => $row->id,
            'lead_id' => $leadId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'decision' => $decision,
            'reason' => $reason,
            'source_text' => $sourceText ? Str::limit($sourceText, 5000) : null,
            'confidence' => $confidence,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
