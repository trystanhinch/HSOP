<?php

namespace App\Services\CommandCenter;

use App\Models\AiActionLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\User;
use App\Services\PayoutEligibilityService;
use Carbon\Carbon;

/**
 * Structured, permissioned query functions for the Owner AI Command Center.
 * Numbers come only from these queries — never invented by the model.
 */
class CommandCenterQueryService
{
    public function __construct(private PayoutEligibilityService $eligibility) {}

    /**
     * OpenAI tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        return [
            $this->tool('get_today_ops_summary', 'Summary of today\'s ops: leads, quotes, jobs, payments, payouts, reviews, AI errors.'),
            $this->tool('get_stuck_leads', 'List stuck/overdue leads from NextAction escalation data with responsible PM and overdue time.'),
            $this->tool('get_pm_follow_ups', 'PMs with overdue next actions / follow-ups that need attention.'),
            $this->tool('get_jobs_ready_for_payout', 'Jobs eligible for payout (payment received, completion accepted, no open revision).'),
            $this->tool('get_owner_attention_items', 'Owner-level exceptions: system errors, major complaints, payment problems, high-risk items.'),
        ];
    }

    public function dispatch(string $name, array $args = []): array
    {
        return match ($name) {
            'get_today_ops_summary' => $this->todayOpsSummary(),
            'get_stuck_leads' => $this->stuckLeads(),
            'get_pm_follow_ups' => $this->pmFollowUps(),
            'get_jobs_ready_for_payout' => $this->jobsReadyForPayout(),
            'get_owner_attention_items' => $this->ownerAttentionItems(),
            default => ['error' => 'Unknown query tool: '.$name],
        };
    }

    public function todayOpsSummary(): array
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();

        return [
            'window' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],
            'new_leads_today' => Lead::whereBetween('created_at', [$from, $to])->count(),
            'leads_needing_manual_review' => Lead::where('needs_manual_review', true)->count(),
            'quotes_sent_today' => Quote::whereBetween('sent_at', [$from, $to])->count(),
            'quotes_awaiting_customer' => Quote::whereIn('status', ['sent', 'viewed', 'follow_up'])->count(),
            'jobs_in_progress' => Job::whereIn('status', [
                'scheduled', 'in_progress', 'update_posted', 'progress_updated', 'revision_in_progress',
            ])->count(),
            'invoices_paid_today' => Invoice::where('status', 'paid')
                ->whereDate('payment_date', $from->toDateString())->count(),
            'revenue_paid_today_subtotal' => round((float) Invoice::where('status', 'paid')
                ->whereDate('payment_date', $from->toDateString())->sum('subtotal'), 2),
            'payouts_scheduled' => Payout::where('status', 'scheduled')->count(),
            'payouts_queued' => Payout::where('status', 'queued')->count(),
            'reviews_needing_follow_up' => ReviewFeedback::where('star_rating', '<', 5)
                ->whereIn('follow_up_status', ['new', 'pm_notified', 'customer_contacted', 'escalated'])
                ->count(),
            'ai_errors_today' => AiActionLog::whereNotNull('error')
                ->whereBetween('created_at', [$from, $to])->count(),
            'overdue_next_actions' => NextAction::where('status', 'pending')
                ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
        ];
    }

    public function stuckLeads(): array
    {
        $rows = NextAction::query()
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('subject_type', (new Lead)->getMorphClass())
            ->with(['responsibleUser:id,name,role'])
            ->orderBy('due_at')
            ->limit(25)
            ->get();

        $items = $rows->map(function (NextAction $na) {
            $lead = Lead::find($na->subject_id);

            return [
                'lead_id' => $na->subject_id,
                'contact_name' => $lead?->contact_name,
                'address' => $lead?->address,
                'lead_status' => $lead?->status,
                'action' => $na->action_description,
                'responsible_role' => $na->responsible_role,
                'pm_id' => $na->responsible_user_id,
                'pm_name' => $na->responsibleUser?->name,
                'due_at' => optional($na->due_at)->toDateTimeString(),
                'hours_overdue' => $na->due_at
                    ? (int) Carbon::parse($na->due_at)->diffInHours(now())
                    : null,
            ];
        })->values()->all();

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function pmFollowUps(): array
    {
        $rows = NextAction::query()
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->where('responsible_role', 'pm')
                    ->orWhereHas('responsibleUser', fn ($u) => $u->where('role', 'pm'));
            })
            ->where(function ($q) {
                $q->whereNull('due_at')->orWhere('due_at', '<=', now()->addDay());
            })
            ->with(['responsibleUser:id,name,email,role'])
            ->orderBy('due_at')
            ->limit(40)
            ->get();

        $byPm = [];
        foreach ($rows as $na) {
            $pmId = $na->responsible_user_id ?: 0;
            $pmName = $na->responsibleUser?->name ?: 'Unassigned PM';
            if (! isset($byPm[$pmId])) {
                $byPm[$pmId] = [
                    'pm_id' => $pmId ?: null,
                    'pm_name' => $pmName,
                    'overdue_count' => 0,
                    'due_soon_count' => 0,
                    'actions' => [],
                ];
            }
            $overdue = $na->due_at && $na->due_at->lt(now());
            if ($overdue) {
                $byPm[$pmId]['overdue_count']++;
            } else {
                $byPm[$pmId]['due_soon_count']++;
            }
            $byPm[$pmId]['actions'][] = [
                'next_action_id' => $na->id,
                'subject_type' => $na->subject_type,
                'subject_id' => $na->subject_id,
                'action' => $na->action_description,
                'due_at' => optional($na->due_at)->toDateTimeString(),
                'overdue' => $overdue,
            ];
        }

        return [
            'pm_count' => count($byPm),
            'pms' => array_values($byPm),
        ];
    }

    public function jobsReadyForPayout(): array
    {
        $candidates = Job::query()
            ->whereNotNull('customer_accepted_completion_at')
            ->whereHas('invoice', fn ($q) => $q->where('status', 'paid'))
            ->with(['invoice', 'pm:id,name', 'contractor:id,name', 'customer:id,name'])
            ->latest('id')
            ->limit(40)
            ->get();

        $ready = [];
        foreach ($candidates as $job) {
            $result = $this->eligibility->checkEligibility($job);
            if (! ($result['eligible'] ?? false)) {
                continue;
            }
            $payouts = Payout::where('job_id', $job->id)->get();
            $ready[] = [
                'job_id' => $job->id,
                'address' => $job->address,
                'customer' => $job->customer?->name,
                'pm' => $job->pm?->name,
                'contractor' => $job->contractor?->name,
                'invoice_id' => $job->invoice?->id,
                'invoice_status' => $job->invoice?->status,
                'eligibility_reason' => $result['reason'],
                'payout_statuses' => $payouts->mapWithKeys(
                    fn ($p) => [($p->split_type ?: $p->payout_type) => $p->status]
                )->all(),
            ];
        }

        return [
            'count' => count($ready),
            'jobs' => $ready,
        ];
    }

    public function ownerAttentionItems(): array
    {
        $errors = AiActionLog::whereNotNull('error')
            ->latest('id')
            ->limit(10)
            ->get(['id', 'trigger_event', 'error', 'created_at'])
            ->map(fn ($l) => [
                'type' => 'system_error',
                'id' => $l->id,
                'trigger_event' => $l->trigger_event,
                'error' => $l->error,
                'at' => optional($l->created_at)->toDateTimeString(),
            ])->all();

        $complaints = ReviewFeedback::where('star_rating', '<=', 2)
            ->whereIn('follow_up_status', ['new', 'pm_notified', 'customer_contacted', 'escalated'])
            ->with(['job:id,address', 'customer:id,name'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'type' => 'major_complaint',
                'review_id' => $r->id,
                'star_rating' => $r->star_rating,
                'issue_category' => $r->issue_category,
                'job_id' => $r->job_id,
                'address' => $r->job?->address,
                'customer' => $r->customer?->name,
                'follow_up_status' => $r->follow_up_status,
            ])->all();

        $paymentProblems = Invoice::whereIn('status', ['payment_failed', 'disputed', 'overdue'])
            ->latest('id')
            ->limit(10)
            ->get(['id', 'job_id', 'invoice_number', 'status', 'balance', 'amount'])
            ->map(fn ($i) => [
                'type' => 'payment_problem',
                'invoice_id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'job_id' => $i->job_id,
                'status' => $i->status,
                'balance' => (float) $i->balance,
            ])->all();

        $highRisk = NextAction::where('status', 'pending')
            ->where('responsible_role', 'owner')
            ->where(function ($q) {
                $q->whereNull('due_at')->orWhere('due_at', '<=', now());
            })
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($na) => [
                'type' => 'high_risk_owner_action',
                'next_action_id' => $na->id,
                'action' => $na->action_description,
                'subject_type' => $na->subject_type,
                'subject_id' => $na->subject_id,
                'due_at' => optional($na->due_at)->toDateTimeString(),
            ])->all();

        $items = array_merge($errors, $complaints, $paymentProblems, $highRisk);

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    private function tool(string $name, string $description): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
        ];
    }
}
