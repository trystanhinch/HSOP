<?php

namespace App\Services\LeadIntake;

/**
 * Deterministic Drywall vs Insulation keyword classifier.
 * Uses subject + content — never the sender address.
 */
class KeywordCategoryClassifier
{
    /**
     * @param  array<string, mixed>  $leadData
     * @return array{service_category: ?string, urgency: string, flags: array<string, bool>, confidence: array<string, float>, source_label: ?string, provider: string}
     */
    public function classify(array $leadData): array
    {
        $subject = (string) ($leadData['subject'] ?? '');
        $sourceLabel = $leadData['source_label'] ?? $this->extractSourceLabel($subject, $leadData);
        $text = strtolower(implode(' ', array_filter([
            $subject,
            $sourceLabel,
            $leadData['source_website'] ?? '',
            $leadData['service_requested'] ?? '',
            $leadData['project_description'] ?? '',
            $leadData['service_category'] ?? '',
        ])));

        $serviceCategory = null;
        $serviceConfidence = 0.0;

        $hasDrywall = str_contains($text, 'drywall');
        $hasInsulation = str_contains($text, 'insulation');

        if ($hasDrywall && ! $hasInsulation) {
            $serviceCategory = 'drywall_paint';
            $serviceConfidence = 0.9;
        } elseif ($hasInsulation && ! $hasDrywall) {
            $serviceCategory = 'insulation';
            $serviceConfidence = 0.9;
        } elseif ($hasDrywall && $hasInsulation) {
            // Ambiguous — both keywords present
            $serviceConfidence = 0.3;
        } elseif ($this->containsAny($text, ['paint', 'painting', 'popcorn', 'ceiling', 'texture', 'finishing'])) {
            $serviceCategory = 'drywall_paint';
            $serviceConfidence = 0.7;
        } elseif ($this->containsAny($text, ['spray foam', 'blown-in', 'batt', 'attic insulation'])) {
            $serviceCategory = 'insulation';
            $serviceConfidence = 0.75;
        }

        $urgency = 'normal';
        if ($this->containsAny($text, ['emergency', 'asap', 'urgent', 'immediately', 'today'])) {
            $urgency = 'high';
        }

        return [
            'service_category' => $serviceCategory,
            'urgency' => $urgency,
            'flags' => [
                'ambiguous_service' => $serviceCategory === null,
                'high_urgency' => $urgency === 'high',
            ],
            'confidence' => [
                'service_category' => $serviceConfidence,
            ],
            'source_label' => $sourceLabel,
            'provider' => 'keyword',
        ];
    }

    /**
     * Pull original city/listing name from subject lines like:
     * "Coquitlam Drywall Client From Bil"
     * "Insulation Vancouver [Voicemail] From +1… to Insulation Vancouver"
     *
     * @param  array<string, mixed>  $leadData
     */
    public function extractSourceLabel(string $subject, array $leadData = []): ?string
    {
        if ($subject !== '') {
            if (preg_match('/^(.+?)\s*\[Voicemail\]/i', $subject, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/^(.+?)\s+Client\s+From\b/i', $subject, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/\bto\s+(.+)$/i', $subject, $m)) {
                return trim($m[1]);
            }
        }

        $source = trim((string) ($leadData['source_website'] ?? ''));
        if ($source !== '') {
            $source = preg_replace('/\s*(website|google lead form|\/).*$/i', '', $source) ?? $source;

            return trim($source) ?: null;
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
