<?php

namespace App\Services\Learning;

use App\Models\AuditLog;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\PricingRule;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Milestone 6B Phase 3 — recommend vs finalize authority.
 * recommend() never changes learning_eligibility_status.
 * approve() is the only path that finalizes status (Owner or can_finalize_learning_eligibility).
 */
class LearningEligibilityService
{
    /** @return list<string> */
    public function allowedStatuses(): array
    {
        return config('learning_ai.eligibility_statuses', [
            'pending_review',
            'provisional',
            'verified',
            'excluded',
        ]);
    }

    public function actorCanFinalize(User $actor): bool
    {
        return $actor->canFinalizeLearningEligibility();
    }

    /**
     * Write / supersede a recommendation. Does NOT change learning_eligibility_status.
     *
     * @param  array<string, mixed>|string|null  $missingActuals
     * @return array{outcome: EstimateOutcome, flags: array<string, mixed>, recommendation_state: string}
     */
    public function recommendEstimateOutcome(
        EstimateOutcome $outcome,
        string $status,
        string $reason,
        User $recommendedBy,
        array|string|null $missingActuals = null,
    ): array {
        $status = $this->assertStatus($status);
        $reason = $this->assertReason($reason, 'recommendation');
        $missing = $this->normalizeMissingActuals($missingActuals);

        $priorRecommendation = [
            'learning_recommended_status' => $outcome->learning_recommended_status,
            'learning_recommended_by' => $outcome->learning_recommended_by,
            'learning_recommendation_reason' => $outcome->learning_recommendation_reason,
            'learning_recommendation_missing_actuals' => $outcome->learning_recommendation_missing_actuals,
        ];

        $outcome->forceFill([
            'learning_recommended_status' => $status,
            'learning_recommended_by' => $recommendedBy->id,
            'learning_recommended_at' => now(),
            'learning_recommendation_reason' => $reason,
            'learning_recommendation_missing_actuals' => $missing,
        ])->save();

        AuditLog::create([
            'user_id' => $recommendedBy->id,
            'user_role' => $recommendedBy->role,
            'object_type' => 'estimate_outcome',
            'object_id' => $outcome->id,
            'action_type' => 'learning_eligibility_recommended',
            'previous_value' => $priorRecommendation,
            'new_value' => [
                'learning_recommended_status' => $status,
                'learning_recommendation_reason' => $reason,
                'learning_recommendation_missing_actuals' => $missing,
                'superseded_prior' => $priorRecommendation['learning_recommended_status'] !== null,
            ],
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $fresh = $outcome->fresh();

        return [
            'outcome' => $fresh,
            'flags' => $this->flagsForOutcome($fresh),
            'recommendation_state' => $this->recommendationState($fresh),
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $missingActuals
     * @return array{job: Job, flags: array<string, mixed>, recommendation_state: string}
     */
    public function recommendJob(
        Job $job,
        string $status,
        string $reason,
        User $recommendedBy,
        array|string|null $missingActuals = null,
    ): array {
        $status = $this->assertStatus($status);
        $reason = $this->assertReason($reason, 'recommendation');
        $missing = $this->normalizeMissingActuals($missingActuals);

        $priorRecommendation = [
            'learning_recommended_status' => $job->learning_recommended_status,
            'learning_recommended_by' => $job->learning_recommended_by,
            'learning_recommendation_reason' => $job->learning_recommendation_reason,
            'learning_recommendation_missing_actuals' => $job->learning_recommendation_missing_actuals,
        ];

        $job->forceFill([
            'learning_recommended_status' => $status,
            'learning_recommended_by' => $recommendedBy->id,
            'learning_recommended_at' => now(),
            'learning_recommendation_reason' => $reason,
            'learning_recommendation_missing_actuals' => $missing,
        ])->save();

        AuditLog::create([
            'user_id' => $recommendedBy->id,
            'user_role' => $recommendedBy->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'learning_eligibility_recommended',
            'previous_value' => $priorRecommendation,
            'new_value' => [
                'learning_recommended_status' => $status,
                'learning_recommendation_reason' => $reason,
                'learning_recommendation_missing_actuals' => $missing,
                'superseded_prior' => $priorRecommendation['learning_recommended_status'] !== null,
            ],
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $fresh = $job->fresh();

        return [
            'job' => $fresh,
            'flags' => [
                'is_placeholder_estimate' => false,
                'note' => 'Job-level eligibility; placeholder flag applies to estimate outcomes separately.',
            ],
            'recommendation_state' => $this->recommendationState($fresh),
        ];
    }

    /**
     * ONLY method that changes learning_eligibility_status.
     *
     * @return array{outcome: EstimateOutcome, flags: array<string, mixed>, recommendation_state: string, override: bool}
     */
    public function approveEstimateOutcome(
        EstimateOutcome $outcome,
        string $finalStatus,
        string $reason,
        User $approvedBy,
    ): array {
        $this->assertNotLearningAi($approvedBy);
        $this->assertCanFinalize($approvedBy);
        $finalStatus = $this->assertStatus($finalStatus);
        $reason = $this->assertReason($reason, 'approval');

        $before = $outcome->learning_eligibility_status ?? config('learning_ai.eligibility_default', 'pending_review');
        $recommended = $outcome->learning_recommended_status;
        $override = $recommended !== null && $recommended !== $finalStatus;
        $matchedRecommendation = $recommended !== null && $recommended === $finalStatus;

        $outcome->forceFill([
            'learning_eligibility_status' => $finalStatus,
            'learning_eligibility_reason' => $reason,
            'learning_eligibility_reviewed_by' => $approvedBy->id,
            'learning_eligibility_reviewed_at' => now(),
            'learning_approved_by' => $approvedBy->id,
            'learning_approved_at' => now(),
        ])->save();

        AuditLog::create([
            'user_id' => $approvedBy->id,
            'user_role' => $approvedBy->role,
            'object_type' => 'estimate_outcome',
            'object_id' => $outcome->id,
            'action_type' => 'learning_eligibility_finalized',
            'previous_value' => [
                'learning_eligibility_status' => $before,
                'learning_recommended_status' => $recommended,
            ],
            'new_value' => [
                'learning_eligibility_status' => $finalStatus,
                'learning_eligibility_reason' => $reason,
                'matched_recommendation' => $matchedRecommendation,
                'override' => $override,
            ],
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $fresh = $outcome->fresh();

        return [
            'outcome' => $fresh,
            'flags' => $this->flagsForOutcome($fresh),
            'recommendation_state' => $this->recommendationState($fresh),
            'override' => $override,
        ];
    }

    /**
     * @return array{job: Job, flags: array<string, mixed>, recommendation_state: string, override: bool}
     */
    public function approveJob(
        Job $job,
        string $finalStatus,
        string $reason,
        User $approvedBy,
    ): array {
        $this->assertNotLearningAi($approvedBy);
        $this->assertCanFinalize($approvedBy);
        $finalStatus = $this->assertStatus($finalStatus);
        $reason = $this->assertReason($reason, 'approval');

        $before = $job->learning_eligibility_status ?? config('learning_ai.eligibility_default', 'pending_review');
        $recommended = $job->learning_recommended_status;
        $override = $recommended !== null && $recommended !== $finalStatus;
        $matchedRecommendation = $recommended !== null && $recommended === $finalStatus;

        $job->forceFill([
            'learning_eligibility_status' => $finalStatus,
            'learning_eligibility_reason' => $reason,
            'learning_eligibility_reviewed_by' => $approvedBy->id,
            'learning_eligibility_reviewed_at' => now(),
            'learning_approved_by' => $approvedBy->id,
            'learning_approved_at' => now(),
        ])->save();

        AuditLog::create([
            'user_id' => $approvedBy->id,
            'user_role' => $approvedBy->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'learning_eligibility_finalized',
            'previous_value' => [
                'learning_eligibility_status' => $before,
                'learning_recommended_status' => $recommended,
            ],
            'new_value' => [
                'learning_eligibility_status' => $finalStatus,
                'learning_eligibility_reason' => $reason,
                'matched_recommendation' => $matchedRecommendation,
                'override' => $override,
            ],
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $fresh = $job->fresh();

        return [
            'job' => $fresh,
            'flags' => [
                'is_placeholder_estimate' => false,
                'note' => 'Job-level eligibility; placeholder flag applies to estimate outcomes separately.',
            ],
            'recommendation_state' => $this->recommendationState($fresh),
            'override' => $override,
        ];
    }

    /**
     * @param  EstimateOutcome|Job  $subject
     */
    public function recommendationState($subject): string
    {
        $recommended = $subject->learning_recommended_status ?? null;
        if ($recommended === null || $recommended === '') {
            return 'none';
        }

        $recommendedAt = $subject->learning_recommended_at;
        $approvedAt = $subject->learning_approved_at;
        $pending = $approvedAt === null
            || ($recommendedAt !== null && $recommendedAt->gt($approvedAt));

        if ($pending) {
            return 'pending_approval';
        }

        $current = $subject->learning_eligibility_status ?? null;
        if ($current === $recommended) {
            return 'accepted';
        }

        return 'overridden';
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOutcome(EstimateOutcome $row): array
    {
        return [
            'id' => $row->id,
            'lead_id' => $row->lead_id,
            'job_id' => $row->job_id,
            'brand_id' => $row->brand_id,
            'service_category' => $row->service_category,
            'price_low' => $row->price_low,
            'price_high' => $row->price_high,
            'is_placeholder' => (bool) $row->is_placeholder,
            'learning_eligibility_status' => $row->learning_eligibility_status,
            'learning_eligibility_reason' => $row->learning_eligibility_reason,
            'learning_eligibility_reviewed_by' => $row->learning_eligibility_reviewed_by,
            'learning_eligibility_reviewed_at' => optional($row->learning_eligibility_reviewed_at)?->toIso8601String(),
            'learning_recommended_status' => $row->learning_recommended_status,
            'learning_recommended_by' => $row->learning_recommended_by,
            'learning_recommended_at' => optional($row->learning_recommended_at)?->toIso8601String(),
            'learning_recommendation_reason' => $row->learning_recommendation_reason,
            'learning_recommendation_missing_actuals' => $row->learning_recommendation_missing_actuals,
            'learning_approved_by' => $row->learning_approved_by,
            'learning_approved_at' => optional($row->learning_approved_at)?->toIso8601String(),
            'recommendation_state' => $this->recommendationState($row),
            'recommendation_matches_current' => $row->learning_recommended_status !== null
                && $row->learning_recommended_status === $row->learning_eligibility_status
                && $this->recommendationState($row) !== 'pending_approval',
            'flags' => $this->flagsForOutcome($row),
            'lead' => $row->lead,
            'job' => $row->job,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function flagsForOutcome(EstimateOutcome $outcome): array
    {
        $rulePlaceholder = false;
        if ($outcome->pricing_rule_id) {
            $rulePlaceholder = (bool) PricingRule::query()
                ->where('id', $outcome->pricing_rule_id)
                ->value('is_placeholder');
        }

        $status = $outcome->learning_eligibility_status ?? 'pending_review';

        return [
            'is_placeholder_estimate' => (bool) $outcome->is_placeholder || $rulePlaceholder,
            'pricing_rule_is_placeholder' => $rulePlaceholder,
            'outcome_is_placeholder' => (bool) $outcome->is_placeholder,
            'is_provisional' => $status === 'provisional',
            'in_production_learning_set' => $status === 'verified',
            'policy_note' => 'Placeholder estimates are flagged for review but are NOT auto-excluded. Provisional must not receive Verified weight. Recommendations alone never enter the production learning set.',
        ];
    }

    private function assertCanFinalize(User $actor): void
    {
        if (! $this->actorCanFinalize($actor)) {
            throw new RuntimeException('Forbidden: only Owner or users with can_finalize_learning_eligibility may finalize eligibility.');
        }
    }

    /**
     * Defense-in-depth: Learning AI must never finalize eligibility, even if a token
     * is mis-issued with can_finalize_learning_eligibility or a route is miswired.
     */
    private function assertNotLearningAi(User $actor): void
    {
        $learningRole = (string) config('learning_ai.actor_role', 'learning_ai');
        if ($actor->role === $learningRole || $actor->isLearningAi()) {
            throw new RuntimeException(
                'Forbidden: Learning AI cannot finalize learning eligibility (approve). Recommendations only.'
            );
        }
    }

    private function assertStatus(string $status): string
    {
        $status = trim($status);
        if (! in_array($status, $this->allowedStatuses(), true)) {
            throw new InvalidArgumentException(
                'Invalid learning_eligibility_status. Allowed: '.implode(', ', $this->allowedStatuses())
            );
        }

        return $status;
    }

    private function assertReason(string $reason, string $context): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("A non-empty reason is required for eligibility {$context}.");
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>|string|null  $missingActuals
     * @return array<string, mixed>|null
     */
    private function normalizeMissingActuals(array|string|null $missingActuals): ?array
    {
        if ($missingActuals === null || $missingActuals === '') {
            return null;
        }
        if (is_string($missingActuals)) {
            $trimmed = trim($missingActuals);

            return $trimmed === '' ? null : ['notes' => $trimmed];
        }

        return $missingActuals === [] ? null : $missingActuals;
    }
}
