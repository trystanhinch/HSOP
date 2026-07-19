<?php

namespace App\Services\LeadIntake;

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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'duplicate' => $this->duplicate,
            'duplicate_match_type' => $this->duplicateMatchType,
            'lead_id' => $this->lead?->id,
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
