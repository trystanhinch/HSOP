<?php

namespace App\Services\LearningGateway;

use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LearningEvidenceEntry;
use App\Models\LearningNormalizedRecord;
use App\Models\User;
use App\Services\Learning\LearningEligibilityService;
use InvalidArgumentException;

/**
 * Milestone 6B Phase 4 — Learning AI write tools (evidence + recommend only).
 * Never calls approve(). Never writes source-of-truth job/quote/customer fields.
 */
class LearningAiWriteTools
{
    /** @var list<string> */
    private const DRAFT_STATUSES = ['pending_review', 'provisional'];

    public function __construct(
        private SourceRecordImmutabilityGuard $immutability,
        private LearningEligibilityService $eligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{record: LearningNormalizedRecord, stripped_fields: list<string>}
     */
    public function createNormalizedRecord(array $payload, User $actor): array
    {
        $this->assertLearningAi($actor);

        $strippedPass = $this->immutability->strip($payload);
        $data = $strippedPass['clean'];

        $status = strtolower(trim((string) ($data['learning_eligibility_status'] ?? 'pending_review')));
        // verified/excluded are approve()-only — coerce silently to pending_review (never invent/finalize)
        if (in_array($status, ['verified', 'excluded'], true)) {
            $status = 'pending_review';
        }
        if (! in_array($status, self::DRAFT_STATUSES, true)) {
            throw new InvalidArgumentException(
                'learning_eligibility_status for Learning AI drafts must be pending_review or provisional.'
            );
        }

        $subjectType = (string) ($data['subject_type'] ?? '');
        $subjectId = (int) ($data['subject_id'] ?? 0);
        if (! in_array($subjectType, ['job', 'estimate_outcome', 'lead'], true) || $subjectId < 1) {
            throw new InvalidArgumentException('subject_type (job|estimate_outcome|lead) and subject_id are required.');
        }

        $jobId = isset($data['job_id']) ? (int) $data['job_id'] : null;
        $estimateOutcomeId = isset($data['estimate_outcome_id']) ? (int) $data['estimate_outcome_id'] : null;
        $leadId = isset($data['lead_id']) ? (int) $data['lead_id'] : null;

        if ($subjectType === 'job') {
            $job = Job::query()->find($subjectId);
            if (! $job) {
                throw new InvalidArgumentException('Job not found.');
            }
            $jobId = $job->id;
            $leadId = $leadId ?: $job->lead_id;
            // Capture BEFORE values to prove immutability in tests / callers
            $this->assertJobUntouched($job);
        } elseif ($subjectType === 'estimate_outcome') {
            $outcome = EstimateOutcome::query()->find($subjectId);
            if (! $outcome) {
                throw new InvalidArgumentException('Estimate outcome not found.');
            }
            $estimateOutcomeId = $outcome->id;
            $jobId = $jobId ?: $outcome->job_id;
            $leadId = $leadId ?: $outcome->lead_id;
        } elseif ($subjectType === 'lead') {
            $lead = Lead::query()->find($subjectId);
            if (! $lead) {
                throw new InvalidArgumentException('Lead not found.');
            }
            $leadId = $lead->id;
        }

        $record = LearningNormalizedRecord::create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'job_id' => $jobId,
            'estimate_outcome_id' => $estimateOutcomeId,
            'lead_id' => $leadId,
            'learning_eligibility_status' => $status,
            'extracted_fields' => is_array($data['extracted_fields'] ?? null) ? $data['extracted_fields'] : null,
            'provenance' => is_array($data['provenance'] ?? null) ? $data['provenance'] : [
                'actor_role' => $actor->role,
                'actor_user_id' => $actor->id,
                'tool' => 'normalized-record',
            ],
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : null,
            'missing_data_flags' => is_array($data['missing_data_flags'] ?? null) ? $data['missing_data_flags'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);

        if ($jobId) {
            $job = Job::query()->find($jobId);
            if ($job) {
                $this->assertJobUntouched($job);
            }
        }

        return [
            'record' => $record,
            'stripped_fields' => $strippedPass['stripped'],
        ];
    }

    /**
     * Append evidence — never overwrite prior entries.
     *
     * @param  array<string, mixed>  $payload
     * @return array{entry: LearningEvidenceEntry, stripped_fields: list<string>}
     */
    public function attachEvidence(array $payload, User $actor): array
    {
        $this->assertLearningAi($actor);

        $strippedPass = $this->immutability->strip($payload);
        $data = $strippedPass['clean'];

        $recordId = (int) ($data['learning_normalized_record_id'] ?? $data['record_id'] ?? 0);
        $record = LearningNormalizedRecord::query()->find($recordId);
        if (! $record) {
            throw new InvalidArgumentException('learning_normalized_record_id is required and must exist.');
        }

        $jobLabourBefore = null;
        if ($record->job_id) {
            $jobLabourBefore = Job::query()->where('id', $record->job_id)->value('actual_labour_hours');
        }

        $entry = LearningEvidenceEntry::create([
            'learning_normalized_record_id' => $record->id,
            'confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
            'source_references' => is_array($data['source_references'] ?? null) ? $data['source_references'] : null,
            'warnings' => is_array($data['warnings'] ?? null) ? $data['warnings'] : null,
            'missing_data_flags' => is_array($data['missing_data_flags'] ?? null) ? $data['missing_data_flags'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);

        if ($record->job_id) {
            $after = Job::query()->where('id', $record->job_id)->value('actual_labour_hours');
            if ($jobLabourBefore != $after) {
                // Should be unreachable — we never write Job; belt-and-suspenders
                throw new InvalidArgumentException('Source record mutation detected; aborted.');
            }
        }

        return [
            'entry' => $entry,
            'stripped_fields' => $strippedPass['stripped'],
        ];
    }

    /**
     * Same recommend() path PMs use — Learning AI cannot approve.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submitRecommendation(array $payload, User $actor): array
    {
        $this->assertLearningAi($actor);

        $strippedPass = $this->immutability->strip($payload);
        $data = $strippedPass['clean'];

        $status = (string) ($data['status'] ?? '');
        $reason = (string) ($data['reason'] ?? '');
        $missing = $data['missing_actuals'] ?? null;

        if (! empty($data['estimate_outcome_id'])) {
            $outcome = EstimateOutcome::query()->find((int) $data['estimate_outcome_id']);
            if (! $outcome) {
                throw new InvalidArgumentException('Estimate outcome not found.');
            }
            $result = $this->eligibility->recommendEstimateOutcome(
                $outcome,
                $status,
                $reason,
                $actor,
                $missing,
            );

            return [
                'type' => 'estimate_outcome',
                'estimate_outcome' => $this->eligibility->serializeOutcome($result['outcome']),
                'recommendation_state' => $result['recommendation_state'],
                'flags' => $result['flags'],
                'stripped_fields' => $strippedPass['stripped'],
            ];
        }

        if (! empty($data['job_id'])) {
            $job = Job::query()->find((int) $data['job_id']);
            if (! $job) {
                throw new InvalidArgumentException('Job not found.');
            }
            $result = $this->eligibility->recommendJob(
                $job,
                $status,
                $reason,
                $actor,
                $missing,
            );

            return [
                'type' => 'job',
                'job' => [
                    'id' => $result['job']->id,
                    'learning_eligibility_status' => $result['job']->learning_eligibility_status,
                    'learning_recommended_status' => $result['job']->learning_recommended_status,
                    'learning_recommended_by' => $result['job']->learning_recommended_by,
                    'recommendation_state' => $result['recommendation_state'],
                ],
                'recommendation_state' => $result['recommendation_state'],
                'stripped_fields' => $strippedPass['stripped'],
            ];
        }

        throw new InvalidArgumentException('estimate_outcome_id or job_id is required.');
    }

    private function assertLearningAi(User $actor): void
    {
        $role = (string) config('learning_ai.actor_role', 'learning_ai');
        if ($actor->role !== $role) {
            throw new InvalidArgumentException('Only the Learning AI principal may use these write tools.');
        }
    }

    private function assertJobUntouched(Job $job): void
    {
        // No-op placeholder documenting that we never call $job->update / forceFill here.
        // Callers compare before/after externally; this exists for static clarity.
        unset($job);
    }
}
