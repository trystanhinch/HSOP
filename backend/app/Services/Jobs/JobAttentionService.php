<?php

namespace App\Services\Jobs;

use App\Models\Job;
use App\Services\Workflow\JobLifecycleService;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * A-34 — Job attention / overdue rules that match dashboard exception definitions (A-09).
 *
 * Do not invent a separate clock; each rule mirrors PM dashboard / A-09 KPI filters.
 */
class JobAttentionService
{
    public function __construct(
        private WorkflowSettings $settings,
    ) {}

    public function missingUpdateDays(): int
    {
        return max(1, (int) $this->settings->get('job_missing_update_days'));
    }

    /**
     * Apply attention filter — OR of dashboard exception rules.
     */
    public function applyAttentionFilter(Builder $query): Builder
    {
        $missingDays = $this->missingUpdateDays();
        $lifecycle = app(JobLifecycleService::class);
        $inProgress = array_merge(
            JobLifecycleService::IN_PROGRESS_STATUSES,
            ['scheduled', 'start_date_scheduled']
        );
        $needsSchedule = JobLifecycleService::NEEDS_SCHEDULE_STATUSES;
        $revision = $lifecycle->expandFilterStatus('revision_requested');
        $completion = JobLifecycleService::READY_FOR_REVIEW_STATUSES;

        return $query->where(function (Builder $q) use ($missingDays, $inProgress, $needsSchedule, $revision, $completion) {
            // missing_updates — PM jobs_missing_updates
            $q->where(function (Builder $inner) use ($missingDays, $inProgress) {
                $inner->whereIn('status', $inProgress)
                    ->whereDoesntHave('updates', fn ($u) => $u->where('created_at', '>=', now()->subDays($missingDays)));
            })
            // needs_schedule — A-09 approved_needing_schedule
                ->orWhereIn('status', $needsSchedule)
            // revision_open
                ->orWhereIn('status', $revision)
            // completion_pending
                ->orWhereIn('status', $completion)
            // price_pending — A-09 jobs_awaiting_price
                ->orWhere('contractor_price_status', 'pending')
            // price_submitted — PM jobs needing quote approval
                ->orWhere('contractor_price_status', 'submitted')
            // next_action_overdue
                ->orWhereHas('nextActions', function (Builder $na) {
                    $na->whereIn('status', ['pending', 'overdue', 'escalated'])
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', now());
                })
            // invoice_overdue
                ->orWhereHas('invoice', function (Builder $inv) {
                    $inv->where('status', '!=', 'paid')
                        ->whereNotNull('due_date')
                        ->whereDate('due_date', '<', now()->toDateString());
                });
        });
    }

    /**
     * @return array{
     *   attention: bool,
     *   overdue: bool,
     *   attention_reasons: list<string>,
     *   next_action: ?array<string, mixed>,
     *   last_update_at: ?string,
     *   appointment_at: ?string,
     *   owner_name: ?string,
     *   deadline_at: ?string
     * }
     */
    public function enrich(Job $job): array
    {
        $reasons = [];
        $missingDays = $this->missingUpdateDays();
        $inProgress = array_merge(
            JobLifecycleService::IN_PROGRESS_STATUSES,
            ['scheduled', 'start_date_scheduled']
        );

        $lastUpdate = $job->relationLoaded('updates')
            ? $job->updates->sortByDesc('created_at')->first()
            : $job->updates()->latest('id')->first();

        $lastUpdateAt = $lastUpdate?->created_at;

        if (in_array($job->status, $inProgress, true)) {
            $cutoff = now()->subDays($missingDays);
            if (! $lastUpdateAt || $lastUpdateAt->lt($cutoff)) {
                $reasons[] = 'missing_updates';
            }
        }
        if (in_array($job->status, JobLifecycleService::NEEDS_SCHEDULE_STATUSES, true)) {
            $reasons[] = 'needs_schedule';
        }
        if (in_array($job->status, ['revision_requested', 'corrections_required', 'revision_in_progress'], true)) {
            $reasons[] = 'revision_open';
        }
        if (in_array($job->status, JobLifecycleService::READY_FOR_REVIEW_STATUSES, true)) {
            $reasons[] = 'completion_pending';
        }
        if (($job->contractor_price_status ?? null) === 'pending' || $job->contractor_price_status === null || $job->contractor_price_status === 'not_requested') {
            if (! in_array($job->status, JobLifecycleService::COMPLETED_STATUSES, true)
                && ! in_array($job->status, ['cancelled', 'closed'], true)) {
                // Only flag when still awaiting price on active work (matches A-09 pending filter usage)
                if ($job->contractor_price_status === 'pending') {
                    $reasons[] = 'price_pending';
                }
            }
        }
        if (($job->contractor_price_status ?? null) === 'submitted') {
            $reasons[] = 'price_submitted';
        }

        $nextAction = $job->relationLoaded('pendingNextAction')
            ? $job->pendingNextAction
            : $job->pendingNextAction()->with('responsibleUser:id,name')->first();

        $nextActionOverdue = false;
        if ($nextAction && $nextAction->due_at && $nextAction->due_at->isPast()) {
            $reasons[] = 'next_action_overdue';
            $nextActionOverdue = true;
        }

        $invoice = $job->relationLoaded('invoice') ? $job->invoice : $job->invoice()->first();
        $invoiceOverdue = false;
        if ($invoice && $invoice->status !== 'paid' && $invoice->due_date) {
            if (now()->startOfDay()->gt($invoice->due_date)) {
                $reasons[] = 'invoice_overdue';
                $invoiceOverdue = true;
            }
        }

        $reasons = array_values(array_unique($reasons));
        $overdue = $nextActionOverdue || $invoiceOverdue || in_array('missing_updates', $reasons, true);

        $appointment = null;
        if ($job->scheduled_start_date) {
            $appointment = $job->scheduled_start_date.(isset($job->scheduled_start_time) && $job->scheduled_start_time
                ? ' '.$job->scheduled_start_time
                : '');
        }

        $deadline = $nextAction?->due_at
            ?? $job->estimated_completion_date
            ?? $invoice?->due_date;

        return [
            'attention' => $reasons !== [],
            'overdue' => $overdue,
            'attention_reasons' => $reasons,
            'next_action' => $nextAction ? [
                'id' => $nextAction->id,
                'action_description' => $nextAction->action_description,
                'due_at' => optional($nextAction->due_at)?->toIso8601String(),
                'status' => $nextAction->status,
                'responsible_user' => $nextAction->responsibleUser?->only(['id', 'name']),
            ] : null,
            'last_update_at' => optional($lastUpdateAt)?->toIso8601String(),
            'appointment_at' => $appointment,
            'owner_name' => $job->pm?->name ?? $nextAction?->responsibleUser?->name,
            'deadline_at' => $deadline ? (is_string($deadline) ? $deadline : optional($deadline)->toIso8601String() ?? (string) $deadline) : null,
        ];
    }

    /**
     * Expand search across customer, address, job #, phone, quote number.
     */
    public function applySearch(Builder $query, string $term): Builder
    {
        $s = trim($term);
        if ($s === '') {
            return $query;
        }

        return $query->where(function (Builder $qq) use ($s) {
            $qq->where('address', 'like', "%{$s}%")
                ->orWhere('job_title', 'like', "%{$s}%")
                ->orWhereHas('customer', function (Builder $c) use ($s) {
                    $c->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");
                })
                ->orWhereHas('lead', function (Builder $l) use ($s) {
                    $l->where('phone', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('contact_name', 'like', "%{$s}%");
                })
                ->orWhereHas('quote', function (Builder $q) use ($s) {
                    $q->where('quote_number', 'like', "%{$s}%");
                });
            if (ctype_digit($s)) {
                $qq->orWhere('id', (int) $s);
            }
            if (preg_match('/^#?(\d+)$/', $s, $m)) {
                $qq->orWhere('id', (int) $m[1]);
            }
        });
    }
}
