<?php

namespace App\Services\LearningGateway;

/**
 * Milestone 6B Phase 4 — strip source-of-truth fields Learning AI must never write.
 * Silent strip (not loud reject) so crafted payloads cannot move production evidence.
 */
class SourceRecordImmutabilityGuard
{
    /** Root-level keys that are never Learning AI inputs for mutation. */
    private const ROOT_FORBIDDEN = [
        'actual_labour_hours',
        'materials_used',
        'contractor_submitted_price',
        'customer_id',
        'contractor_id',
        'pm_id',
        'address',
        'scope_of_work',
        'job_title',
        'completed_at',
        'ready_for_review_at',
        'split_contractor_pct',
        'split_pm_pct',
        'split_company_pct',
        'payment_confirmed_at',
        'internal_notes',
        'scheduled_start_date',
        'scheduled_end_date',
        'start_date',
        'end_date',
        'price_low',
        'price_high',
        'inputs_used',
        'calculation',
        'materials_assumptions',
        'labour_assumptions',
        'reasoning_snapshot',
        'is_current',
        'version',
        'estimate_group_id',
        'password',
        'do_not_contact',
        'stripe_payment_intent_id',
        'paid_at',
        'balance',
        'line_items',
        'pricing_rule',
        'pricing_rules',
        // Nested smuggling containers — drop entirely
        'job',
        'quote',
        'customer',
        'payment',
        'invoice',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{clean: array<string, mixed>, stripped: list<string>}
     */
    public function strip(array $payload): array
    {
        $clean = [];
        $stripped = [];

        foreach ($payload as $key => $value) {
            $keyStr = (string) $key;
            if (in_array($keyStr, self::ROOT_FORBIDDEN, true)) {
                $stripped[] = $keyStr;
                continue;
            }
            // Nested assoc arrays under unknown keys: still strip known source fields inside
            if (is_array($value) && $this->isAssoc($value)) {
                $nested = $this->stripNestedSourceFields($value, $keyStr);
                $clean[$key] = $nested['clean'];
                $stripped = array_merge($stripped, $nested['stripped']);
            } else {
                $clean[$key] = $value;
            }
        }

        return ['clean' => $clean, 'stripped' => $stripped];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{clean: array<string, mixed>, stripped: list<string>}
     */
    private function stripNestedSourceFields(array $payload, string $prefix): array
    {
        $forbiddenNested = array_merge(self::ROOT_FORBIDDEN, [
            'status',
            'name',
            'email',
            'phone',
            'amount',
            'total',
            'notes',
            'sent_at',
            'approved_at',
            'declined_at',
        ]);
        $clean = [];
        $stripped = [];
        foreach ($payload as $key => $value) {
            $path = $prefix.'.'.$key;
            if (in_array((string) $key, $forbiddenNested, true)) {
                $stripped[] = $path;
                continue;
            }
            if (is_array($value) && $this->isAssoc($value)) {
                $nested = $this->stripNestedSourceFields($value, $path);
                $clean[$key] = $nested['clean'];
                $stripped = array_merge($stripped, $nested['stripped']);
            } else {
                $clean[$key] = $value;
            }
        }

        return ['clean' => $clean, 'stripped' => $stripped];
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
