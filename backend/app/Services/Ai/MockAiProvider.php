<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Services\LeadIntake\KeywordCategoryClassifier;

class MockAiProvider implements AiProviderInterface
{
    public function __construct(
        private KeywordCategoryClassifier $classifier = new KeywordCategoryClassifier,
    ) {}

    public function classifyLead(array $leadData): array
    {
        $result = $this->classifier->classify($leadData);
        $result['provider'] = 'mock';

        return $result;
    }

    public function summarizeLead(array $leadData): string
    {
        if (($leadData['email_format'] ?? null) === 'voicemail') {
            $phone = $leadData['phone'] ?? 'unknown number';
            $source = $leadData['source_label'] ?? $leadData['source_website'] ?? 'unknown source';
            $duration = $leadData['call_duration'] ?? null;

            return trim(sprintf(
                'Voicemail lead from %s via %s%s — listen to recording and complete contact details.',
                $phone,
                $source,
                $duration ? " ({$duration})" : ''
            ));
        }

        $name = $leadData['contact_name'] ?? trim(($leadData['first_name'] ?? '').' '.($leadData['last_name'] ?? ''));
        $service = $leadData['service_requested'] ?? $leadData['service_category'] ?? 'general inquiry';
        $description = $leadData['project_description'] ?? '';
        $address = $leadData['address'] ?? '';

        $parts = array_filter([
            $name ? "Customer {$name}" : null,
            "requested {$service}",
            $description ? "— {$description}" : null,
            $address ? "at {$address}" : null,
        ]);

        return implode(' ', $parts) ?: 'New lead submission received.';
    }
}
