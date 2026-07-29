<?php

namespace App\Services\Dashboard;

use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\SiteVisit;
use App\Services\Workflow\JobLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A-09 — Single source of truth for dashboard / pipeline / nav badge counts.
 *
 * Every metric documents: entity, filters, date range, brand scope, and list href.
 * Dashboard cards, pipeline widget, and nav badges must call these helpers — never
 * re-define the same concept inline.
 */
class DashboardMetricsService
{
    public function __construct(
        protected JobLifecycleService $lifecycle,
    ) {}

    /**
     * Metric catalogue returned to the UI for transparency.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'new_leads' => [
                'label' => 'New Leads',
                'entity' => 'leads',
                'filter' => 'status = new',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/leads?status=new',
            ],
            'leads_needing_review' => [
                'label' => 'Needs Review',
                'entity' => 'leads',
                'filter' => 'needs_manual_review = true',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/leads?status=needs_review',
                'also_used_by' => 'nav badge GET /leads/review-count',
            ],
            'leads_needing_followup' => [
                'label' => 'Needing Followup',
                'entity' => 'leads',
                'filter' => "status = contacted AND updated_at < now()-2 days",
                'date_range' => 'stale > 2 days',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/leads?status=contacted',
            ],
            'jobs_awaiting_price' => [
                'label' => 'Awaiting Price',
                'entity' => 'jobs',
                'filter' => 'contractor_price_status = pending',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id (via lead)',
                'href' => '/jobs?contractor_price_status=pending',
            ],
            'quotes_needing_review' => [
                'label' => 'Quotes to Review',
                'entity' => 'quotes',
                'filter' => 'status = draft',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id (via job.lead)',
                'href' => '/quotes?status=draft',
            ],
            'quotes_sent' => [
                'label' => 'Quotes Sent',
                'entity' => 'quotes',
                'filter' => 'status = sent',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id (via job.lead)',
                'href' => '/quotes?status=sent',
            ],
            'approved_needing_schedule' => [
                'label' => 'Need Schedule',
                'entity' => 'jobs',
                'filter' => 'status IN (quote_approved, waiting_to_schedule, estimate_accepted)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=needs_schedule',
            ],
            'scheduled_jobs' => [
                'label' => 'Scheduled',
                'entity' => 'jobs',
                'filter' => 'status IN (scheduled, start_date_scheduled)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=scheduled',
            ],
            'jobs_in_progress' => [
                'label' => 'In Progress',
                'entity' => 'jobs',
                'filter' => 'status IN (in_progress, progress_updated, update_posted) — activity aliases count as in_progress',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=in_progress',
            ],
            'jobs_ready_for_review' => [
                'label' => 'Ready for Review',
                'entity' => 'jobs',
                'filter' => 'status IN (ready_for_review, pending_customer_approval, completion_requested)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=completion_pending',
            ],
            'pending_approval' => [
                'label' => 'Pending Approval',
                'entity' => 'jobs',
                'filter' => 'status IN (pending_customer_approval, completion_requested)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=pending_customer_approval',
            ],
            'revision_requested' => [
                'label' => 'Revisions',
                'entity' => 'jobs',
                'filter' => 'status IN (revision_requested, corrections_required, revision_in_progress)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=revision_requested',
            ],
            'completed_jobs' => [
                'label' => 'Completed',
                'entity' => 'jobs',
                'filter' => 'lifecycle completed bucket (completed/closed/completion_accepted/payment_pending + legacy paid*)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=completed',
                'note' => 'Payment is invoice/ledger (A-01), not jobs.status',
            ],
            'active_jobs' => [
                'label' => 'Active Jobs',
                'entity' => 'jobs',
                'filter' => 'status IN active lifecycle set (excludes completed/cancelled/payment-closed)',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'href' => '/jobs?status=active',
            ],
            'pipeline' => [
                'label' => 'Lead Pipeline widget',
                'entity' => 'leads',
                'filter' => 'grouped by status buckets: new, site_visit, quote_needed, converted, lost',
                'date_range' => 'all_time',
                'scope' => 'productionOnly + optional brand_id',
                'note' => 'new bucket count === new_leads metric',
            ],
        ];
    }

    /**
     * @param  array{brand_id?: int|null, company_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function adminKpis(array $filters = []): array
    {
        $brandId = isset($filters['brand_id']) && $filters['brand_id'] !== '' && $filters['brand_id'] !== null
            ? (int) $filters['brand_id']
            : null;

        $ledger = app(\App\Services\Finance\FinancialLedgerService::class)->summary(
            array_filter([
                'brand_id' => $brandId,
            ], fn ($v) => $v !== null)
        );

        $pipeline = $this->leadPipeline($brandId);

        return [
            'new_leads' => $this->countNewLeads($brandId),
            'leads_needing_review' => $this->countLeadsNeedingReview($brandId),
            'leads_needing_followup' => $this->countLeadsNeedingFollowup($brandId),
            'jobs_awaiting_price' => $this->countJobsAwaitingPrice($brandId),
            'quotes_needing_review' => $this->countQuotes($brandId, ['draft']),
            'quotes_sent' => $this->countQuotes($brandId, ['sent']),
            'approved_needing_schedule' => $this->countJobsByStatuses($brandId, JobLifecycleService::NEEDS_SCHEDULE_STATUSES),
            'scheduled_jobs' => $this->countJobsByStatuses($brandId, ['scheduled', 'start_date_scheduled']),
            'jobs_in_progress' => $this->countJobsByStatuses($brandId, JobLifecycleService::IN_PROGRESS_STATUSES),
            'jobs_ready_for_review' => $this->countJobsByStatuses($brandId, JobLifecycleService::READY_FOR_REVIEW_STATUSES),
            'pending_approval' => $this->countJobsByStatuses($brandId, ['pending_customer_approval', 'completion_requested']),
            'revision_requested' => $this->countJobsByStatuses($brandId, ['revision_requested', 'corrections_required', 'revision_in_progress']),
            // A-01: AR from ledger — not job payment_* status
            'payment_pending' => $ledger['counts']['unpaid_invoices'] ?? 0,
            'etransfer_to_confirm' => Job::productionOnly()
                ->when($brandId, fn ($q) => $this->scopeJobsBrand($q, $brandId))
                ->where('status', 'etransfer_pending_confirmation')
                ->count(),
            'compliance_pending_review' => \App\Models\ContractorDocument::where('status', 'pending_review')->count(),
            'site_visits_today' => SiteVisit::productionOnly()->where('visit_date', today())->count(),
            'site_visits_this_week' => SiteVisit::productionOnly()
                ->whereBetween('visit_date', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
            'completed_jobs' => $this->countJobsByStatuses($brandId, JobLifecycleService::COMPLETED_STATUSES),
            'jobs_awaiting_payment' => $ledger['counts']['unpaid_invoices'] ?? 0,
            'payouts_pending' => $ledger['counts']['open_payouts'] ?? 0,

            'total_leads' => $this->leadsQuery($brandId)->count(),
            'active_jobs' => $this->countJobsByStatuses($brandId, JobLifecycleService::ACTIVE_JOB_STATUSES),
            'total_contractors' => app(\App\Services\Contractors\ContractorDirectoryService::class)->directoryCount(),
            'total_customers' => \App\Models\User::productionOnly()->where('role', 'customer')->count(),

            'projected_profit_month' => $ledger['projected_profit_month'],
            'projected_profit_all_time' => $ledger['projected_profit'],
            'realized_profit_month' => $ledger['realized_profit_month'],
            'realized_profit_all_time' => $ledger['realized_profit'],
            'total_collected_revenue' => $ledger['collected_revenue'],
            'accounts_receivable' => $ledger['accounts_receivable'],
            'gst_collected' => $ledger['gst_collected'],
            'incomplete_cost_quote_count' => $ledger['incomplete_cost_quote_count'],
            'financial_refreshed_at' => $ledger['refreshed_at'],
            'financial_filters' => $ledger['filters'],
            'financial_labels' => $ledger['labels'],

            'total_profit_month' => $ledger['projected_profit_month'],
            'total_profit_all_time' => $ledger['projected_profit'],
            'revenue_month' => $ledger['collected_revenue'],
            'total_pending_payouts' => ($ledger['contractor_liability'] ?? 0) + ($ledger['pm_liability'] ?? 0),

            'pipeline' => $pipeline,
            'identity_readiness' => app(\App\Services\Company\CompanyIdentityService::class)->readiness(),
            'lead_status_counts' => $this->leadsQuery($brandId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get(),
            'recent_leads' => $this->leadsQuery($brandId)
                ->with(['assignedPm:id,name', 'company:id,name'])
                ->latest()
                ->take(8)
                ->get(),
            'recent_jobs' => $this->jobsQuery($brandId)
                ->with(['customer:id,name', 'contractor:id,name', 'pm:id,name'])
                ->latest()
                ->take(8)
                ->get(),

            'refreshed_at' => now()->toIso8601String(),
            'filters' => [
                'brand_id' => $brandId,
                'date_range' => 'all_time',
                'scope' => 'productionOnly',
            ],
            'metric_definitions' => $this->definitions(),
        ];
    }

    /**
     * Nav badge + dashboard "Needs Review" — identical definition.
     */
    public function countLeadsNeedingReview(?int $brandId = null): int
    {
        return $this->leadsQuery($brandId)->where('needs_manual_review', true)->count();
    }

    public function countNewLeads(?int $brandId = null): int
    {
        return $this->leadsQuery($brandId)->where('status', 'new')->count();
    }

    public function countLeadsNeedingFollowup(?int $brandId = null): int
    {
        return $this->leadsQuery($brandId)
            ->where('status', 'contacted')
            ->where('updated_at', '<', now()->subDays(2))
            ->count();
    }

    public function countJobsAwaitingPrice(?int $brandId = null): int
    {
        return $this->jobsQuery($brandId)
            ->where('contractor_price_status', 'pending')
            ->count();
    }

    /**
     * @param  list<string>  $statuses
     */
    public function countJobsByStatuses(?int $brandId, array $statuses): int
    {
        return $this->jobsQuery($brandId)->whereIn('status', $statuses)->count();
    }

    /**
     * @param  list<string>  $statuses
     */
    public function countQuotes(?int $brandId, array $statuses): int
    {
        $q = Quote::productionOnly()->whereIn('status', $statuses);
        if ($brandId) {
            $q->whereHas('job.lead', fn ($lq) => $lq->where('brand_id', $brandId));
        }

        return $q->count();
    }

    /**
     * Pipeline widget buckets. `new` === countNewLeads for the same brand filter.
     *
     * @return array{new: int, site_visit: int, quote_needed: int, converted: int, lost: int}
     */
    public function leadPipeline(?int $brandId = null): array
    {
        $base = $this->leadsQuery($brandId);

        return [
            'new' => (clone $base)->where('status', 'new')->count(),
            'site_visit' => (clone $base)->whereIn('status', [
                'site_visit_scheduled', 'call_scheduled', 'site_visit_completed',
            ])->count(),
            'quote_needed' => (clone $base)->whereIn('status', [
                'quote_needed', 'customer_contacted', 'contacted', 'pm_assigned',
            ])->count(),
            'converted' => (clone $base)->where('status', 'converted')->count(),
            'lost' => (clone $base)->whereIn('status', ['lost', 'disqualified'])->count(),
        ];
    }

    /**
     * PM active jobs — same ACTIVE set as admin, scoped to PM.
     */
    public function countPmActiveJobs(int $pmId): int
    {
        return Job::query()
            ->where('pm_id', $pmId)
            ->whereIn('status', JobLifecycleService::ACTIVE_JOB_STATUSES)
            ->count();
    }

    public function countPmInProgress(int $pmId): int
    {
        return Job::query()
            ->where('pm_id', $pmId)
            ->whereIn('status', JobLifecycleService::IN_PROGRESS_STATUSES)
            ->count();
    }

    public function countContractorActiveJobs(int $contractorUserId): int
    {
        return Job::query()
            ->where('contractor_id', $contractorUserId)
            ->whereIn('status', JobLifecycleService::IN_PROGRESS_STATUSES)
            ->count();
    }

    protected function leadsQuery(?int $brandId): Builder
    {
        $q = Lead::productionOnly();
        if ($brandId) {
            $q->where('brand_id', $brandId);
        }

        return $q;
    }

    protected function jobsQuery(?int $brandId): Builder
    {
        $q = Job::productionOnly();
        if ($brandId) {
            $this->scopeJobsBrand($q, $brandId);
        }

        return $q;
    }

    protected function scopeJobsBrand(Builder $query, int $brandId): Builder
    {
        return $query->where(function ($q) use ($brandId) {
            $q->whereHas('lead', fn ($lq) => $lq->where('brand_id', $brandId));
        });
    }
}
