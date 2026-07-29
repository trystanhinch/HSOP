<?php

namespace App\Services\Leads;

/**
 * A-35 — Normalize A-02 confidence shapes for list/detail display.
 */
class LeadConfidencePresenter
{
    /**
     * @param  array<string, mixed>|null  $parseMetadata
     * @return array{
     *   min_score: ?int,
     *   low_fields: list<string>,
     *   reasons: list<string>,
     *   fields: list<array{field: string, score: int, valid: ?bool, source_text: ?string}>,
     *   review_reason: ?string
     * }
     */
    public function summarize(?array $parseMetadata, bool $needsManualReview = false): array
    {
        $raw = $parseMetadata['field_confidence'] ?? null;
        $fields = $this->normalizeFields($raw);
        $min = null;
        $low = [];
        foreach ($fields as $row) {
            $score = (int) $row['score'];
            $min = $min === null ? $score : min($min, $score);
            if ($score < 70 || $row['valid'] === false) {
                $low[] = $row['field'];
            }
        }

        $reasons = [];
        if (! empty($parseMetadata['quarantine_reason'])) {
            $reasons[] = (string) $parseMetadata['quarantine_reason'];
        }
        if (! empty($parseMetadata['review_reason'])) {
            $reasons[] = (string) $parseMetadata['review_reason'];
        }
        if (! empty($parseMetadata['classification']['flags'])) {
            foreach ((array) $parseMetadata['classification']['flags'] as $flag => $on) {
                if ($on) {
                    $reasons[] = is_string($flag) ? $flag : (string) $on;
                }
            }
        }
        if ($low !== []) {
            $reasons[] = 'Low confidence: '.implode(', ', array_unique($low));
        }
        if ($needsManualReview && $reasons === []) {
            $reasons[] = 'Flagged for manual review';
        }

        $reasons = array_values(array_unique(array_filter($reasons)));

        return [
            'min_score' => $min,
            'low_fields' => array_values(array_unique($low)),
            'reasons' => $reasons,
            'fields' => $fields,
            'review_reason' => $reasons[0] ?? null,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{field: string, score: int, valid: ?bool, source_text: ?string}>
     */
    public function normalizeFields(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        // List form from A-02 quarantine: [{field, score, valid, source_text}]
        if (array_is_list($raw)) {
            $out = [];
            foreach ($raw as $row) {
                if (! is_array($row) || empty($row['field'])) {
                    continue;
                }
                $score = $row['score'] ?? 0;
                if (is_float($score) || (is_numeric($score) && (float) $score <= 1.0 && (float) $score >= 0)) {
                    // Heuristic: 0–1 floats → percent
                    if ((float) $score <= 1.0) {
                        $score = (int) round((float) $score * 100);
                    }
                }
                $out[] = [
                    'field' => (string) $row['field'],
                    'score' => (int) $score,
                    'valid' => array_key_exists('valid', $row) ? (bool) $row['valid'] : null,
                    'source_text' => isset($row['source_text']) ? (string) $row['source_text'] : null,
                ];
            }

            return $out;
        }

        // Map form from LeadEmailParser: field => 0–1 float
        $out = [];
        foreach ($raw as $field => $score) {
            if (! is_string($field)) {
                continue;
            }
            $pct = is_numeric($score) ? (float) $score : 0.0;
            if ($pct <= 1.0) {
                $pct = (int) round($pct * 100);
            } else {
                $pct = (int) $pct;
            }
            $out[] = [
                'field' => $field,
                'score' => $pct,
                'valid' => $pct >= 70 ? true : false,
                'source_text' => null,
            ];
        }

        return $out;
    }
}
