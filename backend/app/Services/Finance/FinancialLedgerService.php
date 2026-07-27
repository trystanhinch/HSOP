<?php

namespace App\Services\Finance;

use App\Models\FinancialLedgerEntry;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\Quote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit A-01 / A-26 / A-28 — single authoritative financial read model.
 *
 * Decisions locked for this batch:
 * - Revenue amounts are ex-GST; GST is a separate field (1A).
 * - Realized profit uses paid-out costs only, not unpaid liabilities (2).
 * - Stripe fees treated as $0 until stored (3B).
 * - Company share recipient displays as "Company / Platform" (4).
 *
 * Controllers MUST call this service for financial totals — do not recompute.
 */
class FinancialLedgerService
{
    public const UNPAID_INVOICE_STATUSES = [
        'draft', 'sent', 'invoice_sent', 'awaiting_payment', 'payment_pending',
        'partially_paid', 'overdue', 'unpaid', 'partial', 'payment_failed',
    ];

    public const OPEN_PAYOUT_STATUSES = [
        'not_ready', 'not_eligible', 'waiting_for_payment', 'waiting_for_completion_acceptance',
        'waiting_for_revision_closure', 'eligible', 'scheduled', 'queued', 'pending',
        'ready_for_payout', 'approved', 'in_transit', 'on_hold', 'hold_issue',
    ];

    /**
     * @param  array{
     *   from?: string|null,
     *   to?: string|null,
     *   brand_id?: int|null,
     *   service_category?: string|null,
     *   source?: string|null,
     *   pm_id?: int|null,
     *   contractor_id?: int|null,
     *   basis?: 'cash'|'accrual'
     * }  $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters = []): array
    {
        $basis = ($filters['basis'] ?? 'cash') === 'accrual' ? 'accrual' : 'cash';
        $refreshedAt = now()->toIso8601String();

        $invoices = $this->filteredInvoices($filters)->get();
        $quotes = $this->filteredApprovedQuotes($filters)->get();
        $payouts = $this->filteredPayouts($filters)->get();
        $payments = $this->filteredPayments($filters);

        $incompleteQuotes = $quotes->filter(fn (Quote $q) => $this->hasIncompleteCostData($q));
        $completeQuotes = $quotes->reject(fn (Quote $q) => $this->hasIncompleteCostData($q));

        $quotedValue = round((float) Quote::productionOnly()
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->whereIn('status', ['draft', 'sent', 'viewed', 'pending'])
            ->sum(DB::raw('COALESCE(customer_price_before_gst, subtotal, 0)')), 2);

        $approvedContractValue = round((float) $completeQuotes->sum(
            fn (Quote $q) => (float) ($q->customer_price_before_gst ?? $q->subtotal ?? 0)
        ), 2);

        $invoicedRevenue = round((float) $invoices
            ->whereNotIn('status', ['void', 'cancelled', 'draft'])
            ->sum(fn (Invoice $i) => (float) ($i->subtotal ?? 0)), 2);

        // Cash basis: only paid amounts; accrual: full invoiced for recognition period
        if ($basis === 'cash') {
            $collectedRevenue = round((float) $payments->sum('amount_ex_gst'), 2);
            // Prefer invoice amount_paid mapped ex-GST when payment rows incomplete
            if ($collectedRevenue <= 0.009) {
                $collectedRevenue = round((float) $invoices->sum(function (Invoice $i) {
                    $total = (float) ($i->amount ?: 0);
                    $paid = (float) ($i->amount_paid ?: 0);
                    if ($total <= 0 || $paid <= 0) {
                        return 0;
                    }
                    $ratio = min(1, $paid / $total);

                    return (float) ($i->subtotal ?? 0) * $ratio;
                }), 2);
            }
        } else {
            $collectedRevenue = round((float) $invoices->where('status', 'paid')->sum(
                fn (Invoice $i) => (float) ($i->subtotal ?? 0)
            ), 2);
        }

        $accountsReceivable = round((float) $invoices
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->sum(function (Invoice $i) {
                $balance = (float) ($i->balance ?? 0);
                $total = (float) ($i->amount ?: 0);
                $subtotal = (float) ($i->subtotal ?: 0);
                if ($total <= 0) {
                    return $subtotal;
                }

                return $subtotal * ($balance / $total);
            }), 2);

        $gstCollected = round((float) $invoices->whereIn('status', ['paid', 'partially_paid'])->sum(function (Invoice $i) {
            $total = (float) ($i->amount ?: 0);
            $paid = (float) ($i->amount_paid ?: 0);
            $gst = (float) ($i->gst ?: 0);
            if ($total <= 0 || $paid <= 0 || $gst <= 0) {
                return $i->status === 'paid' ? $gst : 0;
            }

            return $gst * min(1, $paid / $total);
        }), 2);

        $splitKey = fn (Payout $p) => $p->split_type ?: $p->payout_type;
        $paidPayouts = $payouts->where('status', 'paid');
        $openPayouts = $payouts->whereIn('status', self::OPEN_PAYOUT_STATUSES);

        $contractorLiability = round((float) $openPayouts->filter(fn ($p) => $splitKey($p) === 'contractor')->sum('payout_amount'), 2);
        $pmLiability = round((float) $openPayouts->filter(fn ($p) => $splitKey($p) === 'pm')->sum('payout_amount'), 2);
        $contractorPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'contractor')->sum('payout_amount'), 2);
        $pmPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'pm')->sum('payout_amount'), 2);
        $companyPaid = round((float) $paidPayouts->filter(fn ($p) => $splitKey($p) === 'company')->sum('payout_amount'), 2);

        $companyMarginProjected = round((float) $completeQuotes->sum(
            fn (Quote $q) => (float) ($q->company_amount ?? 0) ?: max(0, (float) ($q->hsop_markup ?? 0) - (float) ($q->pm_amount ?? 0))
        ), 2);

        $projectedProfit = round((float) $completeQuotes->sum(fn (Quote $q) => (float) ($q->hsop_markup ?? 0)), 2);

        // Realized = collected (ex-GST) − actually paid contractor − actually paid PM (fees=$0)
        $stripeFees = 0.0;
        $realizedProfit = round($collectedRevenue - $contractorPaid - $pmPaid - $stripeFees, 2);

        $monthStart = now()->startOfMonth();
        $projectedProfitMonth = round((float) $completeQuotes
            ->filter(fn (Quote $q) => $q->accepted_at && Carbon::parse($q->accepted_at)->gte($monthStart))
            ->sum(fn (Quote $q) => (float) ($q->hsop_markup ?? 0)), 2);

        $realizedProfitMonth = round((float) $this->monthCollectedExGst($invoices, $payments) - $this->monthPaidOut($paidPayouts, $splitKey), 2);

        return [
            'refreshed_at' => $refreshedAt,
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'brand_id' => $filters['brand_id'] ?? null,
                'service_category' => $filters['service_category'] ?? null,
                'source' => $filters['source'] ?? null,
                'pm_id' => $filters['pm_id'] ?? null,
                'contractor_id' => $filters['contractor_id'] ?? null,
                'basis' => $basis,
            ],
            'labels' => [
                'projected_profit' => 'Projected Profit',
                'realized_profit' => 'Realized Profit',
                'collected_revenue' => 'Collected Revenue (ex-GST)',
                'accounts_receivable' => 'Accounts Receivable (ex-GST)',
            ],
            'quoted_value' => $quotedValue,
            'approved_contract_value' => $approvedContractValue,
            'invoiced_revenue' => $invoicedRevenue,
            'collected_revenue' => $collectedRevenue,
            'accounts_receivable' => $accountsReceivable,
            'gst_collected' => $gstCollected,
            'contractor_liability' => $contractorLiability,
            'pm_liability' => $pmLiability,
            'contractor_paid' => $contractorPaid,
            'pm_paid' => $pmPaid,
            'company_paid' => $companyPaid,
            'company_margin' => $companyMarginProjected,
            'projected_profit' => $projectedProfit,
            'projected_profit_month' => $projectedProfitMonth,
            'realized_profit' => $realizedProfit,
            'realized_profit_month' => $realizedProfitMonth,
            'stripe_fees' => $stripeFees,
            'incomplete_cost_quote_count' => $incompleteQuotes->count(),
            'incomplete_cost_quotes' => $incompleteQuotes->values()->map(fn (Quote $q) => [
                'id' => $q->id,
                'quote_number' => $q->quote_number,
                'job_id' => $q->job_id,
                'contractor_base_price' => $q->contractor_base_price,
                'flag' => 'incomplete_cost_data',
            ])->all(),
            'counts' => [
                'unpaid_invoices' => $invoices->whereIn('status', self::UNPAID_INVOICE_STATUSES)->filter(fn ($i) => (float) $i->balance > 0)->count(),
                'open_payouts' => $openPayouts->count(),
                'approved_quotes_complete' => $completeQuotes->count(),
                'approved_quotes_incomplete' => $incompleteQuotes->count(),
            ],
        ];
    }

    /**
     * Drill-down list for a named metric.
     *
     * @return array{metric: string, label: string, total: float, refreshed_at: string, filters: array, records: list<array>}
     */
    public function drilldown(string $metric, array $filters = []): array
    {
        $summary = $this->summary($filters);
        $refreshedAt = $summary['refreshed_at'];

        return match ($metric) {
            'accounts_receivable', 'unpaid_invoices' => $this->drillUnpaidInvoices($filters, $summary, $refreshedAt),
            'collected_revenue' => $this->drillCollected($filters, $summary, $refreshedAt),
            'projected_profit', 'projected_profit_month' => $this->drillProjectedProfit($filters, $summary, $refreshedAt, $metric),
            'realized_profit', 'realized_profit_month' => $this->drillRealized($filters, $summary, $refreshedAt, $metric),
            'incomplete_cost_data' => [
                'metric' => 'incomplete_cost_data',
                'label' => 'Incomplete cost data (excluded from profit)',
                'total' => (float) $summary['incomplete_cost_quote_count'],
                'refreshed_at' => $refreshedAt,
                'filters' => $summary['filters'],
                'records' => $summary['incomplete_cost_quotes'],
            ],
            'open_payouts', 'contractor_liability', 'pm_liability' => $this->drillOpenPayouts($filters, $summary, $refreshedAt, $metric),
            'revenue_jobs_breakdown' => $this->drillRevenueJobsBreakdown($filters, $summary, $refreshedAt),
            default => [
                'metric' => $metric,
                'label' => $metric,
                'total' => 0,
                'refreshed_at' => $refreshedAt,
                'filters' => $summary['filters'],
                'records' => [],
                'error' => 'Unknown metric',
            ],
        };
    }

    /**
     * Job-level payout reconciliation group (A-28).
     *
     * @return array<string, mixed>
     */
    public function payoutReconciliationForJob(int $jobId): array
    {
        $job = Job::productionOnly()->with(['invoice', 'quote', 'contractor:id,name', 'pm:id,name'])->findOrFail($jobId);
        $payouts = Payout::productionOnly()->where('job_id', $jobId)->orderBy('id')->get();
        $invoice = $job->invoice;
        $payments = $invoice
            ? Payment::productionOnly()->where('invoice_id', $invoice->id)->orderBy('id')->get()
            : collect();

        $customerPaid = round((float) ($invoice?->amount_paid ?? $payments->sum('amount') ?? 0), 2);
        $customerPaidExGst = 0.0;
        if ($invoice && (float) $invoice->amount > 0) {
            $customerPaidExGst = round(((float) $invoice->subtotal) * min(1, $customerPaid / (float) $invoice->amount), 2);
        }

        $allocations = $payouts->map(function (Payout $p) {
            $type = $p->split_type ?: $p->payout_type;

            return [
                'payout_id' => $p->id,
                'split_type' => $type,
                'recipient_label' => $this->recipientLabel($p),
                'amount' => round((float) $p->payout_amount, 2),
                'status' => $p->status,
                'eligibility_status' => $p->eligibility_status,
                'not_ready_reasons' => $this->notReadyReasons($p, $p->job ?? Job::find($p->job_id)),
                'stripe_transfer_id' => $p->stripe_transfer_id,
                'paid_date' => $p->paid_date,
                'stripe_onboarding' => $this->stripeOnboardingLabel($p),
            ];
        })->values()->all();

        $allocSum = round(collect($allocations)->sum('amount'), 2);
        $fees = 0.0; // 3B — not stored yet
        $refunds = round((float) FinancialLedgerEntry::productionOnly()
            ->where('job_id', $jobId)
            ->where('entry_type', FinancialLedgerEntry::TYPE_REFUND)
            ->sum('amount'), 2);

        $reconcileTo = $customerPaid; // GST-inclusive customer payment per A-28 wording
        $sumCheck = round($allocSum + $fees + $refunds, 2);
        // Prefer ex-GST compare when payment includes GST and allocations are ex-GST
        $reconcileExGst = $customerPaidExGst;
        $balancedExGst = abs($sumCheck - $reconcileExGst) < 0.02;
        $balancedGross = abs($sumCheck - $reconcileTo) < 0.02;

        return [
            'job_id' => $jobId,
            'job_address' => $job->address,
            'completion_state' => $job->status,
            'customer_accepted_completion_at' => $job->customer_accepted_completion_at,
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'subtotal' => (float) $invoice->subtotal,
                'gst' => (float) $invoice->gst,
                'amount' => (float) $invoice->amount,
                'amount_paid' => (float) $invoice->amount_paid,
                'balance' => (float) $invoice->balance,
            ] : null,
            'customer_payment_amount' => $customerPaid,
            'customer_payment_ex_gst' => $customerPaidExGst,
            'allocations' => $allocations,
            'fees' => $fees,
            'refunds' => $refunds,
            'allocations_plus_fees_refunds' => $sumCheck,
            'reconciles_to_payment_ex_gst' => $balancedExGst,
            'reconciles_to_payment_gross' => $balancedGross,
            'events' => PayoutEvent::productionOnly()->where('job_id', $jobId)->orderBy('id')->get(),
        ];
    }

    /**
     * Group all payouts by job for owner reconciliation view.
     *
     * @return list<array<string, mixed>>
     */
    public function payoutGroups(array $filters = []): array
    {
        $query = Payout::productionOnly()
            ->with([
                'job:id,address,job_title,customer_id,pm_id,contractor_id,status,customer_accepted_completion_at',
                'job.customer:id,name',
                'job.invoice',
                'contractor:id,name,stripe_account_id,stripe_payout_ready,stripe_onboarding_status',
                'pm:id,name',
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rows = $query->latest('id')->get();
        $byJob = $rows->groupBy('job_id');

        return $byJob->map(function (Collection $group, $jobId) {
            $first = $group->first();
            $job = $first->job;
            $recon = $jobId ? $this->payoutReconciliationForJob((int) $jobId) : null;

            return [
                'job_id' => (int) $jobId,
                'job_address' => $job?->address,
                'customer_name' => $job?->customer?->name,
                'completion_state' => $job?->status,
                'payment_state' => $job?->invoice?->status,
                'allocations' => $group->map(fn (Payout $p) => [
                    'id' => $p->id,
                    'split_type' => $p->split_type ?: $p->payout_type,
                    'recipient_label' => $this->recipientLabel($p),
                    'amount' => (float) $p->payout_amount,
                    'status' => $p->status,
                    'eligibility_status' => $p->eligibility_status,
                    'not_ready_reasons' => $this->notReadyReasons($p, $job),
                    'stripe_transfer_id' => $p->stripe_transfer_id,
                    'paid_date' => $p->paid_date,
                ])->values()->all(),
                'total_allocations' => round((float) $group->sum('payout_amount'), 2),
                'reconciles' => $recon['reconciles_to_payment_ex_gst'] ?? null,
                'customer_payment_ex_gst' => $recon['customer_payment_ex_gst'] ?? null,
            ];
        })->values()->all();
    }

    public function hasIncompleteCostData(Quote $quote): bool
    {
        $price = $quote->contractor_base_price;

        return $price === null || (float) $price <= 0;
    }

    public function recipientLabel(Payout $payout): string
    {
        $type = $payout->split_type ?: $payout->payout_type;
        if ($type === 'company') {
            return 'Company / Platform';
        }
        if ($type === 'pm') {
            return $payout->pm?->name ?: $payout->contractor?->name ?: 'PM';
        }

        return $payout->contractor?->name ?: 'Contractor';
    }

    /**
     * @return list<string>
     */
    public function notReadyReasons(Payout $payout, ?Job $job = null): array
    {
        $reasons = [];
        $job = $job ?: $payout->job;
        $status = $payout->status;

        if (in_array($status, ['paid', 'approved', 'scheduled', 'eligible', 'ready_for_payout', 'in_transit'], true)) {
            return [];
        }

        if ($status === 'on_hold' || $status === 'hold_issue') {
            $reasons[] = 'Payout is on hold.';
        }
        if ($status === 'waiting_for_payment' || ($payout->eligibility_status && str_contains(strtolower((string) $payout->eligibility_status), 'payment'))) {
            $reasons[] = 'Customer payment not received (invoice not fully paid).';
        }
        if ($status === 'waiting_for_completion_acceptance' || ! $job?->customer_accepted_completion_at) {
            if (! $job?->customer_accepted_completion_at) {
                $reasons[] = 'Customer has not accepted job completion.';
            }
        }
        if ($status === 'waiting_for_revision_closure') {
            $reasons[] = 'Open revision request must be closed.';
        }
        if ($status === 'failed') {
            $reasons[] = 'Last transfer failed — retry required.';
        }
        if ($status === 'queued') {
            $reasons[] = 'Queued until Stripe Connect / balance is ready.';
        }

        $type = $payout->split_type ?: $payout->payout_type;
        if (in_array($type, ['contractor', 'pm'], true)) {
            $user = $type === 'pm' ? ($payout->pm ?: User::find($payout->pm_id)) : ($payout->contractor ?: User::find($payout->contractor_id));
            if ($user && ! $user->stripe_payout_ready) {
                $reasons[] = 'Payee Stripe onboarding incomplete (payouts not ready).';
            }
            if ($user && ! $user->stripe_account_id) {
                $reasons[] = 'Payee has no Stripe Connect account.';
            }
        }

        if ($reasons === [] && in_array($status, ['not_ready', 'not_eligible', 'pending'], true)) {
            $reasons[] = $payout->eligibility_status
                ?: 'Not ready — eligibility requirements not met.';
        }

        return array_values(array_unique($reasons));
    }

    public function recordEntry(array $attrs): FinancialLedgerEntry
    {
        return FinancialLedgerEntry::create(array_merge([
            'currency' => 'CAD',
            'direction' => 'credit',
            'occurred_at' => now(),
            'is_test_data' => false,
        ], $attrs));
    }

    public function recordPayoutEvent(Payout $payout, string $eventType, ?int $actorId = null, ?string $notes = null): PayoutEvent
    {
        return PayoutEvent::create([
            'payout_id' => $payout->id,
            'job_id' => $payout->job_id,
            'event_type' => $eventType,
            'from_status' => $payout->getOriginal('status'),
            'to_status' => $payout->status,
            'amount' => $payout->payout_amount,
            'actor_user_id' => $actorId,
            'snapshot' => $payout->toArray(),
            'notes' => $notes,
            'is_test_data' => (bool) ($payout->is_test_data ?? false),
            'occurred_at' => now(),
        ]);
    }

    private function filteredInvoices(array $filters)
    {
        $q = Invoice::productionOnly()->with(['job:id,service_category,pm_id,contractor_id']);

        return $this->applyCommonFilters($q, $filters, 'invoices');
    }

    private function filteredApprovedQuotes(array $filters)
    {
        $q = Quote::productionOnly()
            ->where('status', 'approved')
            ->with(['job:id,address,service_category,pm_id,contractor_id', 'customer:id,name']);

        if (! empty($filters['from'])) {
            $q->whereDate('accepted_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('accepted_at', '<=', $filters['to']);
        }
        if (! empty($filters['service_category'])) {
            $q->whereHas('job', fn ($j) => $j->where('service_category', $filters['service_category']));
        }
        if (! empty($filters['pm_id'])) {
            $q->whereHas('job', fn ($j) => $j->where('pm_id', $filters['pm_id']));
        }
        if (! empty($filters['contractor_id'])) {
            $q->whereHas('job', fn ($j) => $j->where('contractor_id', $filters['contractor_id']));
        }

        return $q;
    }

    private function filteredPayouts(array $filters)
    {
        $q = Payout::productionOnly()->with(['job', 'contractor:id,name', 'pm:id,name']);

        if (! empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['pm_id'])) {
            $q->where(function ($w) use ($filters) {
                $w->where('pm_id', $filters['pm_id'])
                    ->orWhereHas('job', fn ($j) => $j->where('pm_id', $filters['pm_id']));
            });
        }
        if (! empty($filters['contractor_id'])) {
            $q->where(function ($w) use ($filters) {
                $w->where('contractor_id', $filters['contractor_id'])
                    ->orWhereHas('job', fn ($j) => $j->where('contractor_id', $filters['contractor_id']));
            });
        }

        return $q;
    }

    private function filteredPayments(array $filters)
    {
        $q = Payment::query()->whereHas('invoice', function ($iq) use ($filters) {
            $iq->productionOnly();
            $this->applyCommonFilters($iq, $filters, 'invoices');
        });

        // Annotate ex-GST amount via join ratio when loading
        return $q->with('invoice')->get()->map(function (Payment $p) {
            $inv = $p->invoice;
            $ex = (float) $p->amount;
            if ($inv && (float) $inv->amount > 0) {
                $ex = round((float) $inv->subtotal * ((float) $p->amount / (float) $inv->amount), 2);
            }
            $p->amount_ex_gst = $ex;

            return $p;
        });
    }

    private function applyCommonFilters($query, array $filters, string $context)
    {
        if (! empty($filters['from'])) {
            $query->whereDate($context === 'invoices' ? 'created_at' : 'created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['source'])) {
            $query->where('source_company', $filters['source']);
        }
        if (! empty($filters['service_category'])) {
            $query->whereHas('job', fn ($j) => $j->where('service_category', $filters['service_category']));
        }
        if (! empty($filters['pm_id'])) {
            $query->whereHas('job', fn ($j) => $j->where('pm_id', $filters['pm_id']));
        }
        if (! empty($filters['contractor_id'])) {
            $query->whereHas('job', fn ($j) => $j->where('contractor_id', $filters['contractor_id']));
        }

        return $query;
    }

    private function monthCollectedExGst(Collection $invoices, Collection $payments): float
    {
        $month = now()->month;
        $year = now()->year;
        $sum = 0.0;
        foreach ($payments as $p) {
            $d = $p->paid_date ?: $p->created_at;
            if (! $d) {
                continue;
            }
            $c = Carbon::parse($d);
            if ($c->month === $month && $c->year === $year) {
                $sum += (float) ($p->amount_ex_gst ?? $p->amount);
            }
        }
        if ($sum > 0) {
            return $sum;
        }
        foreach ($invoices->where('status', 'paid') as $i) {
            $d = $i->payment_date ?: $i->updated_at;
            if (! $d) {
                continue;
            }
            $c = Carbon::parse($d);
            if ($c->month === $month && $c->year === $year) {
                $sum += (float) $i->subtotal;
            }
        }

        return $sum;
    }

    private function monthPaidOut(Collection $paidPayouts, callable $splitKey): float
    {
        $month = now()->month;
        $year = now()->year;
        $sum = 0.0;
        foreach ($paidPayouts as $p) {
            if (! in_array($splitKey($p), ['contractor', 'pm'], true)) {
                continue;
            }
            $d = $p->paid_date ?: $p->updated_at;
            if (! $d) {
                continue;
            }
            $c = Carbon::parse($d);
            if ($c->month === $month && $c->year === $year) {
                $sum += (float) $p->payout_amount;
            }
        }

        return $sum;
    }

    private function stripeOnboardingLabel(Payout $payout): ?string
    {
        $type = $payout->split_type ?: $payout->payout_type;
        if ($type === 'company') {
            return 'Platform retain (no Connect)';
        }
        $user = $type === 'pm' ? $payout->pm : $payout->contractor;
        if (! $user) {
            return null;
        }
        if ($user->stripe_payout_ready) {
            return 'Ready';
        }
        if (! $user->stripe_account_id) {
            return 'Not connected';
        }

        return 'Onboarding: '.($user->stripe_onboarding_status ?: 'incomplete');
    }

    private function drillUnpaidInvoices(array $filters, array $summary, string $refreshedAt): array
    {
        $rows = $this->filteredInvoices($filters)->get()
            ->whereIn('status', self::UNPAID_INVOICE_STATUSES)
            ->filter(fn (Invoice $i) => (float) $i->balance > 0)
            ->values()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'status' => $i->status,
                'subtotal' => (float) $i->subtotal,
                'amount' => (float) $i->amount,
                'balance' => (float) $i->balance,
                'job_id' => $i->job_id,
            ]);

        return [
            'metric' => 'accounts_receivable',
            'label' => 'Accounts Receivable (ex-GST)',
            'total' => (float) $summary['accounts_receivable'],
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => $rows->all(),
        ];
    }

    private function drillCollected(array $filters, array $summary, string $refreshedAt): array
    {
        $rows = $this->filteredInvoices($filters)->get()
            ->filter(fn (Invoice $i) => (float) $i->amount_paid > 0)
            ->values()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'status' => $i->status,
                'subtotal' => (float) $i->subtotal,
                'amount_paid' => (float) $i->amount_paid,
                'job_id' => $i->job_id,
            ]);

        return [
            'metric' => 'collected_revenue',
            'label' => 'Collected Revenue (ex-GST)',
            'total' => (float) $summary['collected_revenue'],
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => $rows->all(),
        ];
    }

    private function drillProjectedProfit(array $filters, array $summary, string $refreshedAt, string $metric): array
    {
        $quotes = $this->filteredApprovedQuotes($filters)->get()
            ->reject(fn (Quote $q) => $this->hasIncompleteCostData($q));
        if ($metric === 'projected_profit_month') {
            $start = now()->startOfMonth();
            $quotes = $quotes->filter(fn (Quote $q) => $q->accepted_at && Carbon::parse($q->accepted_at)->gte($start));
        }

        return [
            'metric' => $metric,
            'label' => 'Projected Profit',
            'total' => (float) ($metric === 'projected_profit_month' ? $summary['projected_profit_month'] : $summary['projected_profit']),
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => $quotes->values()->map(fn (Quote $q) => [
                'id' => $q->id,
                'quote_number' => $q->quote_number,
                'customer' => $q->customer?->name,
                'job_id' => $q->job_id,
                'contractor_base_price' => (float) $q->contractor_base_price,
                'customer_price_before_gst' => (float) ($q->customer_price_before_gst ?? $q->subtotal),
                'projected_profit' => (float) $q->hsop_markup,
                'accepted_at' => $q->accepted_at,
            ])->all(),
        ];
    }

    private function drillRealized(array $filters, array $summary, string $refreshedAt, string $metric): array
    {
        return [
            'metric' => $metric,
            'label' => 'Realized Profit',
            'total' => (float) ($metric === 'realized_profit_month' ? $summary['realized_profit_month'] : $summary['realized_profit']),
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => [
                ['component' => 'collected_revenue', 'amount' => $summary['collected_revenue']],
                ['component' => 'contractor_paid', 'amount' => -$summary['contractor_paid']],
                ['component' => 'pm_paid', 'amount' => -$summary['pm_paid']],
                ['component' => 'stripe_fees', 'amount' => -$summary['stripe_fees']],
            ],
        ];
    }

    private function drillOpenPayouts(array $filters, array $summary, string $refreshedAt, string $metric): array
    {
        $rows = $this->filteredPayouts($filters)->get()->whereIn('status', self::OPEN_PAYOUT_STATUSES);
        if ($metric === 'contractor_liability') {
            $rows = $rows->filter(fn (Payout $p) => ($p->split_type ?: $p->payout_type) === 'contractor');
        }
        if ($metric === 'pm_liability') {
            $rows = $rows->filter(fn (Payout $p) => ($p->split_type ?: $p->payout_type) === 'pm');
        }

        return [
            'metric' => $metric,
            'label' => 'Open payouts',
            'total' => (float) $rows->sum('payout_amount'),
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => $rows->values()->map(fn (Payout $p) => [
                'id' => $p->id,
                'job_id' => $p->job_id,
                'split_type' => $p->split_type ?: $p->payout_type,
                'recipient_label' => $this->recipientLabel($p),
                'amount' => (float) $p->payout_amount,
                'status' => $p->status,
                'not_ready_reasons' => $this->notReadyReasons($p),
            ])->all(),
        ];
    }

    private function drillRevenueJobsBreakdown(array $filters, array $summary, string $refreshedAt): array
    {
        $invoices = $this->filteredInvoices($filters)->with('job')->get();
        $byMonth = $invoices->groupBy(fn (Invoice $i) => Carbon::parse($i->created_at)->format('Y-m'))
            ->map(function ($group, $month) {
                $paid = $group->where('status', 'paid');

                return [
                    'period' => $month,
                    'jobs_invoiced' => $group->pluck('job_id')->unique()->count(),
                    'invoiced_revenue' => round((float) $group->sum('subtotal'), 2),
                    'collected_revenue' => round((float) $paid->sum('subtotal'), 2),
                    'invoice_ids' => $group->pluck('id')->values()->all(),
                ];
            })->sortKeysDesc()->values();

        return [
            'metric' => 'revenue_jobs_breakdown',
            'label' => 'Revenue / jobs by month',
            'total' => (float) $summary['collected_revenue'],
            'refreshed_at' => $refreshedAt,
            'filters' => $summary['filters'],
            'records' => $byMonth->all(),
        ];
    }
}
