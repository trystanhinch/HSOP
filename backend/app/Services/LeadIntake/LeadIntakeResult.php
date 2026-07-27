<?php

namespace App\Services\LeadIntake;

use App\Models\IntakeQuarantine;
use App\Models\Lead;

class LeadIntakeResult
{
    public function __construct(
        public readonly ParsedLeadEmail $parsed,
        public readonly bool $duplicate,
        public readonly ?string $duplicateMatchType,
        public readonly ?Lead $lead,
        public readonly ?array $classification,
        public readonly ?string $aiSummary,
        public readonly ?int $companySourceId,
        public readonly array $notifications,
        public readonly array $aiActionLogs,
        public readonly ?IntakeQuarantine $quarantine = null,
        public readonly string $outcome = 'created', // created|quarantined|ignored|duplicate
    ) {}

    public static function quarantined(ParsedLeadEmail $parsed, IntakeQuarantine $row): self
    {
        return new self(
            parsed: $parsed,
            duplicate: false,
            duplicateMatchType: null,
            lead: null,
            classification: null,
            aiSummary: null,
            companySourceId: $row->company_source_id,
            notifications: [],
            aiActionLogs: [],
            quarantine: $row,
            outcome: 'quarantined',
        );
    }

    public static function ignored(ParsedLeadEmail $parsed, IntakeQuarantine $row): self
    {
        return new self(
            parsed: $parsed,
            duplicate: false,
            duplicateMatchType: null,
            lead: null,
            classification: null,
            aiSummary: null,
            companySourceId: $row->company_source_id,
            notifications: [],
            aiActionLogs: [],
            quarantine: $row,
            outcome: 'ignored',
        );
    }

    public static function duplicateMerged(
        ParsedLeadEmail $parsed,
        ?Lead $lead,
        IntakeQuarantine $row,
        string $matchType,
    ): self {
        return new self(
            parsed: $parsed,
            duplicate: true,
            duplicateMatchType: $matchType,
            lead: $lead,
            classification: null,
            aiSummary: null,
            companySourceId: $lead?->company_source_id ?? $row->company_source_id,
            notifications: ['skipped' => 'duplicate_voicemail'],
            aiActionLogs: [],
            quarantine: $row,
            outcome: 'duplicate',
        );
    }

    public function withQuarantine(IntakeQuarantine $row): self
    {
        return new self(
            parsed: $this->parsed,
            duplicate: $this->duplicate,
            duplicateMatchType: $this->duplicateMatchType,
            lead: $this->lead,
            classification: $this->classification,
            aiSummary: $this->aiSummary,
            companySourceId: $this->companySourceId,
            notifications: $this->notifications,
            aiActionLogs: $this->aiActionLogs,
            quarantine: $row,
            outcome: $this->outcome === 'created' ? 'created' : $this->outcome,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'duplicate' => $this->duplicate,
            'duplicate_match_type' => $this->duplicateMatchType,
            'lead_id' => $this->lead?->id,
            'quarantine_id' => $this->quarantine?->id,
            'quarantine_status' => $this->quarantine?->status,
            'quarantine_reason' => $this->quarantine?->quarantine_reason,
            'needs_manual_review' => $this->lead?->needs_manual_review ?? $this->parsed->needsManualReview,
            'parsed' => $this->parsed->toArray(),
            'classification' => $this->classification,
            'ai_summary' => $this->aiSummary,
            'company_source_id' => $this->companySourceId,
            'notifications' => $this->notifications,
            'ai_action_log_ids' => array_column($this->aiActionLogs, 'id'),
        ];
    }
}
