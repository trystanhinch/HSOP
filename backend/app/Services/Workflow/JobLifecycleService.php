<?php

namespace App\Services\Workflow;

use App\Models\Job;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * A-08 — Job lifecycle vs activity vs payment.
 *
 * Lifecycle lives on jobs.status.
 * Activity (progress notes/photos/messages) lives in job_updates + activity_timeline — NEVER as status.
 * Payment lives on invoices / ledger (A-01) — NEVER as status.
 */
class JobLifecycleService
{
    /** Activity-like values that must never be written to jobs.status. */
    public const ACTIVITY_STATUSES = [
        'progress_updated',
        'update_posted',
    ];

    /** Payment-like values that must never be written to jobs.status (A-01 owns payment). */
    public const PAYMENT_STATUSES = [
        'paid',
        'paid_completed',
        'invoiced',
        'etransfer_pending_confirmation',
    ];

    /**
     * Primary lifecycle states used for filters / dashboards (plus documented aliases).
     *
     * Flow: Needs Schedule → Scheduled → In Progress → Ready for Review
     *     → Completion Pending Customer → Completed | Cancelled
     * Branch: revision_* from completion-pending / ready-for-review.
     */
    public const LIFECYCLE_STATUSES = [
        'new_job',
        'created',
        'contractor_assigned',
        'site_visit_scheduled',
        'site_visit_completed',
        'contractor_pricing_pending',
        'quote_sent',
        'estimate_sent',
        'quote_approved',
        'waiting_to_schedule',
        'estimate_accepted',
        'scheduled',
        'start_date_scheduled',
        'in_progress',
        'waiting_on_customer',
        'ready_for_review',
        'pending_customer_approval',
        'completion_requested',
        'revision_requested',
        'corrections_required',
        'revision_in_progress',
        'completion_accepted',
        'payment_pending', // post-acceptance; AR is on invoice (A-01)
        'completed',
        'closed',
        'completed_by_contractor',
        'final_review',
        'cancelled',
    ];

    /** Statuses counted as "In Progress" for dashboards/filters. */
    public const IN_PROGRESS_STATUSES = [
        'in_progress',
        // Legacy rows until backfill; treated as in_progress, not a distinct lifecycle step.
        'progress_updated',
        'update_posted',
    ];

    /** Statuses counted as "Needs Schedule". */
    public const NEEDS_SCHEDULE_STATUSES = [
        'quote_approved',
        'waiting_to_schedule',
        'estimate_accepted',
    ];

    /** Statuses counted as "Ready for Review" / completion pending customer. */
    public const READY_FOR_REVIEW_STATUSES = [
        'ready_for_review',
        'pending_customer_approval',
        'completion_requested',
    ];

    /** Statuses counted as "Completed" (lifecycle closed — payment is separate). */
    public const COMPLETED_STATUSES = [
        'completed',
        'closed',
        'completion_accepted',
        'payment_pending',
        // Legacy payment-on-job rows until backfill
        'paid',
        'paid_completed',
    ];

    /** Active (non-terminal) work for "Active Jobs" cards. */
    public const ACTIVE_JOB_STATUSES = [
        'new_job',
        'created',
        'contractor_assigned',
        'site_visit_scheduled',
        'contractor_pricing_pending',
        'quote_sent',
        'estimate_sent',
        'quote_approved',
        'waiting_to_schedule',
        'estimate_accepted',
        'scheduled',
        'start_date_scheduled',
        'in_progress',
        'progress_updated',
        'update_posted',
        'waiting_on_customer',
        'ready_for_review',
        'pending_customer_approval',
        'completion_requested',
        'revision_requested',
        'corrections_required',
        'revision_in_progress',
    ];

    /**
     * Allowed from → to transitions. Empty list = terminal.
     * Same-status is always allowed (no-op).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'new_job' => [
            'created', 'contractor_assigned', 'site_visit_scheduled', 'contractor_pricing_pending',
            'quote_sent', 'scheduled', 'waiting_to_schedule', 'cancelled',
        ],
        'created' => [
            'contractor_assigned', 'site_visit_scheduled', 'contractor_pricing_pending',
            'quote_sent', 'scheduled', 'waiting_to_schedule', 'cancelled',
        ],
        'contractor_assigned' => [
            'site_visit_scheduled', 'contractor_pricing_pending', 'quote_sent', 'quote_approved',
            'scheduled', 'in_progress', 'waiting_to_schedule', 'cancelled',
        ],
        'site_visit_scheduled' => [
            'site_visit_completed', 'contractor_pricing_pending', 'scheduled', 'cancelled',
        ],
        'site_visit_completed' => [
            'contractor_pricing_pending', 'quote_sent', 'cancelled',
        ],
        'contractor_pricing_pending' => [
            'quote_sent', 'estimate_sent', 'contractor_assigned', 'cancelled',
        ],
        'quote_sent' => [
            'quote_approved', 'waiting_on_customer', 'estimate_sent', 'cancelled',
        ],
        'estimate_sent' => [
            'estimate_accepted', 'quote_approved', 'waiting_on_customer', 'cancelled',
        ],
        'waiting_on_customer' => [
            'quote_approved', 'estimate_accepted', 'waiting_to_schedule', 'cancelled',
        ],
        'quote_approved' => [
            'waiting_to_schedule', 'scheduled', 'cancelled',
        ],
        'estimate_accepted' => [
            'waiting_to_schedule', 'scheduled', 'cancelled',
        ],
        'waiting_to_schedule' => [
            'scheduled', 'start_date_scheduled', 'cancelled',
        ],
        'scheduled' => [
            'in_progress', 'start_date_scheduled', 'cancelled',
        ],
        'start_date_scheduled' => [
            'scheduled', 'in_progress', 'cancelled',
        ],
        'in_progress' => [
            'ready_for_review', 'pending_customer_approval', 'completion_requested',
            'revision_requested', 'corrections_required', 'cancelled',
        ],
        // Legacy activity statuses: only allow repair → in_progress or continue lifecycle
        'progress_updated' => [
            'in_progress', 'ready_for_review', 'pending_customer_approval', 'completion_requested', 'cancelled',
        ],
        'update_posted' => [
            'in_progress', 'ready_for_review', 'pending_customer_approval', 'completion_requested', 'cancelled',
        ],
        'ready_for_review' => [
            'pending_customer_approval', 'completion_requested', 'in_progress',
            'corrections_required', 'revision_requested', 'cancelled',
        ],
        'pending_customer_approval' => [
            'completion_requested', 'completion_accepted', 'payment_pending',
            'revision_requested', 'corrections_required', 'cancelled',
        ],
        'completion_requested' => [
            'pending_customer_approval', 'completion_accepted', 'payment_pending',
            'revision_requested', 'cancelled',
        ],
        'revision_requested' => [
            'revision_in_progress', 'corrections_required', 'in_progress',
            'pending_customer_approval', 'ready_for_review', 'cancelled',
        ],
        'corrections_required' => [
            'revision_in_progress', 'revision_requested', 'in_progress',
            'pending_customer_approval', 'ready_for_review', 'cancelled',
        ],
        'revision_in_progress' => [
            'in_progress', 'ready_for_review', 'pending_customer_approval',
            'completion_requested', 'cancelled',
        ],
        'completion_accepted' => [
            'payment_pending', 'completed', 'closed', 'cancelled',
        ],
        'payment_pending' => [
            'completed', 'closed', 'cancelled',
        ],
        'completed_by_contractor' => [
            'pending_customer_approval', 'ready_for_review', 'final_review', 'cancelled',
        ],
        'final_review' => [
            'completed', 'corrections_required', 'cancelled',
        ],
        'completed' => [],
        'closed' => [],
        'cancelled' => [],
        // Legacy payment-on-job: only allow repair to completed
        'paid' => ['completed', 'closed'],
        'paid_completed' => ['completed', 'closed'],
        'invoiced' => ['payment_pending', 'completed', 'closed'],
        'etransfer_pending_confirmation' => ['payment_pending', 'completed', 'closed'],
    ];

    public function isActivityStatus(?string $status): bool
    {
        return in_array((string) $status, self::ACTIVITY_STATUSES, true);
    }

    public function isPaymentStatus(?string $status): bool
    {
        return in_array((string) $status, self::PAYMENT_STATUSES, true);
    }

    public function isLifecycleStatus(?string $status): bool
    {
        return in_array((string) $status, self::LIFECYCLE_STATUSES, true)
            || array_key_exists((string) $status, self::TRANSITIONS);
    }

    public function canTransition(?string $from, string $to): bool
    {
        $from = $from ?: '';
        if ($from === $to) {
            return true;
        }

        if ($this->isActivityStatus($to) || $this->isPaymentStatus($to)) {
            return false;
        }

        if (! array_key_exists($from, self::TRANSITIONS)) {
            // Unknown current status: allow move into a known lifecycle state (repair path).
            return $this->isLifecycleStatus($to) && ! $this->isActivityStatus($to) && ! $this->isPaymentStatus($to);
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Apply a lifecycle transition. Blocks + logs invalid transitions.
     *
     * @param  array<string, mixed>  $extraAttributes
     *
     * @throws InvalidArgumentException
     */
    public function transition(Job $job, string $to, array $extraAttributes = [], ?string $reason = null): Job
    {
        $from = (string) $job->status;
        $to = str_replace(' ', '_', strtolower(trim($to)));

        if ($this->isActivityStatus($to)) {
            Log::warning('A-08 blocked activity status write on job', [
                'job_id' => $job->id,
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
            ]);
            throw new InvalidArgumentException(
                "\"{$to}\" is an activity event, not a job lifecycle status. Record it via job updates / timeline."
            );
        }

        if ($this->isPaymentStatus($to)) {
            Log::warning('A-08 blocked payment status write on job', [
                'job_id' => $job->id,
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
            ]);
            throw new InvalidArgumentException(
                "\"{$to}\" is a payment state (invoice/ledger), not a job lifecycle status."
            );
        }

        if (! $this->canTransition($from, $to)) {
            Log::warning('A-08 blocked invalid job status transition', [
                'job_id' => $job->id,
                'from' => $from,
                'to' => $to,
                'reason' => $reason,
                'allowed' => self::TRANSITIONS[$from] ?? [],
            ]);
            throw new InvalidArgumentException(
                "Invalid job status transition: {$from} → {$to}."
            );
        }

        if ($from === $to && $extraAttributes === []) {
            return $job;
        }

        $job->update(array_merge(['status' => $to], $extraAttributes));

        return $job->fresh();
    }

    /**
     * On progress note/photo: only advance Scheduled → In Progress when needed.
     * Never overwrite status with progress_updated / update_posted.
     */
    public function onProgressPosted(Job $job): Job
    {
        $status = (string) $job->status;

        if (in_array($status, [
            'scheduled',
            'start_date_scheduled',
            'contractor_assigned',
            'created',
            'waiting_to_schedule',
            'new_job',
        ], true)) {
            return $this->transition($job, 'in_progress', [], 'progress_posted_starts_work');
        }

        // Repair legacy activity statuses back to lifecycle in_progress.
        if ($this->isActivityStatus($status)) {
            return $this->transition($job, 'in_progress', [], 'progress_posted_repairs_activity_status');
        }

        return $job;
    }

    /**
     * After invoice fully paid (A-01): keep lifecycle at completed — do not write paid*.
     */
    public function onInvoiceFullyPaid(Job $job): Job
    {
        $status = (string) $job->status;

        if (in_array($status, ['completed', 'closed', 'cancelled'], true)) {
            return $job;
        }

        if (in_array($status, ['payment_pending', 'completion_accepted', 'paid', 'paid_completed', 'invoiced', 'etransfer_pending_confirmation'], true)) {
            try {
                return $this->transition($job, 'completed', [
                    'completed_at' => $job->completed_at ?? now(),
                ], 'invoice_fully_paid');
            } catch (InvalidArgumentException $e) {
                Log::warning('A-08 could not transition job on invoice paid', [
                    'job_id' => $job->id,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ]);

                return $job;
            }
        }

        return $job;
    }

    /**
     * Expand a filter status into the DB values that belong to that lifecycle bucket.
     *
     * @return list<string>
     */
    public function expandFilterStatus(string $status): array
    {
        $status = str_replace(' ', '_', strtolower(trim($status)));

        return match ($status) {
            'in_progress' => self::IN_PROGRESS_STATUSES,
            'quote_approved', 'waiting_to_schedule', 'needs_schedule' => self::NEEDS_SCHEDULE_STATUSES,
            'ready_for_review', 'pending_customer_approval', 'completion_pending' => self::READY_FOR_REVIEW_STATUSES,
            'completed' => self::COMPLETED_STATUSES,
            'active' => self::ACTIVE_JOB_STATUSES,
            'revision_requested' => ['revision_requested', 'corrections_required', 'revision_in_progress'],
            default => [$status],
        };
    }
}
