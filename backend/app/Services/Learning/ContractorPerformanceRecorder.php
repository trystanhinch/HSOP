<?php

namespace App\Services\Learning;

use App\Models\ContractorPerformanceEvent;
use App\Models\EstimateOutcome;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\ReviewFeedback;
use App\Models\RevisionRequest;
use Illuminate\Support\Facades\Log;

/**
 * Thin raw-event recorder for Learning Centre contractor performance.
 * Reuses existing workflow moments — no rollup metrics, no recommendations.
 */
class ContractorPerformanceRecorder
{
    /**
     * @param  array<string, mixed>  $eventData
     */
    public function record(
        ?int $contractorId,
        string $eventType,
        array $eventData = [],
        ?int $jobId = null,
        ?int $leadId = null,
        mixed $occurredAt = null,
    ): ?ContractorPerformanceEvent {
        if (! $contractorId || ! in_array($eventType, ContractorPerformanceEvent::TYPES, true)) {
            return null;
        }

        try {
            return ContractorPerformanceEvent::create([
                'contractor_id' => $contractorId,
                'job_id' => $jobId,
                'lead_id' => $leadId,
                'event_type' => $eventType,
                'event_data' => $eventData,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write contractor_performance_events row', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Contractor notified of assignment — start of response_time window. */
    public function onContractorAssigned(Job $job): void
    {
        $this->record(
            $job->contractor_id,
            'response_time',
            [
                'phase' => 'notified',
                'job_status' => $job->status,
                'notified_at' => now()->toIso8601String(),
            ],
            $job->id,
            $job->lead_id,
        );
    }

    /** First contractor action after assignment (price submit or progress update). */
    public function onContractorFirstAction(Job $job, string $action): void
    {
        if (! $job->contractor_id) {
            return;
        }

        $already = ContractorPerformanceEvent::query()
            ->where('job_id', $job->id)
            ->where('contractor_id', $job->contractor_id)
            ->where('event_type', 'response_time')
            ->where('event_data->phase', 'first_action')
            ->exists();
        if ($already) {
            return;
        }

        $notified = ContractorPerformanceEvent::query()
            ->where('job_id', $job->id)
            ->where('contractor_id', $job->contractor_id)
            ->where('event_type', 'response_time')
            ->where('event_data->phase', 'notified')
            ->orderByDesc('occurred_at')
            ->first();

        $notifiedAt = $notified?->occurred_at;
        $seconds = $notifiedAt ? $notifiedAt->diffInSeconds(now()) : null;

        $this->record(
            $job->contractor_id,
            'response_time',
            [
                'phase' => 'first_action',
                'action' => $action,
                'notified_at' => optional($notifiedAt)?->toIso8601String(),
                'responded_at' => now()->toIso8601String(),
                'response_seconds' => $seconds,
            ],
            $job->id,
            $job->lead_id,
        );
    }

    /** Booked work window captured when PM schedules the job. */
    public function onJobScheduled(Job $job): void
    {
        $this->record(
            $job->contractor_id,
            'schedule_adherence',
            [
                'phase' => 'booked',
                'scheduled_start_date' => $job->scheduled_start_date,
                'scheduled_start_time' => $job->scheduled_start_time,
                'estimated_completion_date' => $job->estimated_completion_date,
            ],
            $job->id,
            $job->lead_id,
        );
    }

    /** Actual start when job first moves to in_progress. */
    public function onJobStarted(Job $job): void
    {
        $already = ContractorPerformanceEvent::query()
            ->where('job_id', $job->id)
            ->where('event_type', 'schedule_adherence')
            ->where('event_data->phase', 'actual_start')
            ->exists();
        if ($already) {
            return;
        }

        $this->record(
            $job->contractor_id,
            'schedule_adherence',
            [
                'phase' => 'actual_start',
                'scheduled_start_date' => $job->scheduled_start_date,
                'scheduled_start_time' => $job->scheduled_start_time,
                'actual_start_at' => now()->toIso8601String(),
            ],
            $job->id,
            $job->lead_id,
        );
    }

    public function onRevisionRequested(Job $job, RevisionRequest $revision): void
    {
        $count = RevisionRequest::query()->where('job_id', $job->id)->count();

        $this->record(
            $job->contractor_id,
            'revision_requested',
            [
                'revision_request_id' => $revision->id,
                'description' => $revision->description,
                'revision_count_on_job' => $count,
            ],
            $job->id,
            $job->lead_id,
        );

        // Alias "callback" using the same existing RevisionRequest signal
        $this->record(
            $job->contractor_id,
            'callback',
            [
                'source' => 'revision_request',
                'revision_request_id' => $revision->id,
                'revision_count_on_job' => $count,
            ],
            $job->id,
            $job->lead_id,
        );
    }

    public function onCustomerRating(ReviewFeedback $feedback): void
    {
        $this->record(
            $feedback->contractor_id,
            'customer_rating',
            [
                'review_feedback_id' => $feedback->id,
                'star_rating' => $feedback->star_rating,
                'issue_category' => $feedback->issue_category,
                'comment' => $feedback->comment,
            ],
            $feedback->job_id,
            Job::query()->where('id', $feedback->job_id)->value('lead_id'),
        );
    }

    /** Planned-vs-actual labour/materials when contractor completes. */
    public function onContractorComplete(Job $job): void
    {
        $outcome = EstimateOutcome::query()
            ->where('lead_id', $job->lead_id)
            ->where('is_current', true)
            ->first();

        $plannedLabour = data_get($outcome?->labour_assumptions, 'hours')
            ?? data_get($outcome?->labour_assumptions, 'estimated_hours');
        $actualLabour = $job->actual_labour_hours;

        if ($actualLabour !== null || $plannedLabour !== null) {
            $this->record(
                $job->contractor_id,
                'labour_variance',
                [
                    'planned_labour_hours' => $plannedLabour,
                    'actual_labour_hours' => $actualLabour !== null ? (float) $actualLabour : null,
                    'estimate_outcome_id' => $outcome?->id,
                ],
                $job->id,
                $job->lead_id,
            );
        }

        $plannedMaterials = $outcome?->materials_assumptions;
        $actualMaterials = $job->materials_used;
        if ($plannedMaterials !== null || $actualMaterials !== null) {
            $this->record(
                $job->contractor_id,
                'materials_variance',
                [
                    'planned_materials' => $plannedMaterials,
                    'actual_materials' => $actualMaterials,
                    'estimate_outcome_id' => $outcome?->id,
                ],
                $job->id,
                $job->lead_id,
            );
        }

        $this->record(
            $job->contractor_id,
            'completion_time',
            [
                'phase' => 'contractor_marked_complete',
                'scheduled_start_date' => $job->scheduled_start_date,
                'estimated_completion_date' => $job->estimated_completion_date,
                'completed_at' => now()->toIso8601String(),
            ],
            $job->id,
            $job->lead_id,
        );
    }

    /** Invoice / payout economics once completion is accepted (invoice ensured). */
    public function onCompletionAccepted(Job $job): void
    {
        $job->loadMissing(['invoice', 'quote']);
        $invoice = $job->invoice;
        $outcome = EstimateOutcome::query()
            ->where('lead_id', $job->lead_id)
            ->where('is_current', true)
            ->first();

        $this->record(
            $job->contractor_id,
            'profitability',
            [
                'invoice_id' => $invoice?->id,
                'invoice_status' => $invoice?->status,
                'invoice_amount' => $invoice?->amount,
                'invoice_subtotal' => $invoice?->subtotal,
                'quote_customer_total' => $job->quote?->customer_total,
                'estimate_low' => $outcome?->price_low,
                'estimate_high' => $outcome?->price_high,
                'contractor_submitted_price' => $job->contractor_submitted_price,
            ],
            $job->id,
            $job->lead_id,
        );

        $this->record(
            $job->contractor_id,
            'completion_time',
            [
                'phase' => 'customer_accepted',
                'customer_accepted_completion_at' => optional($job->customer_accepted_completion_at)?->toIso8601String()
                    ?? now()->toIso8601String(),
                'scheduled_start_date' => $job->scheduled_start_date,
                'estimated_completion_date' => $job->estimated_completion_date,
            ],
            $job->id,
            $job->lead_id,
        );
    }
}
