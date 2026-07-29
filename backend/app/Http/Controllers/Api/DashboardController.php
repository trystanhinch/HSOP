<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\SiteVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function admin(): JsonResponse
    {
        $ledger = app(\App\Services\Finance\FinancialLedgerService::class)->summary();

        return response()->json([
            'new_leads' => Lead::productionOnly()->where('status', 'new')->count(),
            'leads_needing_review' => Lead::productionOnly()->where('needs_manual_review', true)->count(),
            'leads_needing_followup' => Lead::productionOnly()->where('status', 'contacted')->where('updated_at', '<', now()->subDays(2))->count(),
            'jobs_awaiting_price' => Job::productionOnly()->where('contractor_price_status', 'pending')->count(),
            'quotes_needing_review' => Quote::productionOnly()->where('status', 'draft')->count(),
            'quotes_sent' => Quote::productionOnly()->where('status', 'sent')->count(),
            'approved_needing_schedule' => Job::productionOnly()->where('status', 'quote_approved')->count(),
            'scheduled_jobs' => Job::productionOnly()->where('status', 'scheduled')->count(),
            'jobs_in_progress' => Job::productionOnly()->where('status', 'in_progress')->count(),
            'jobs_ready_for_review' => Job::productionOnly()->where('status', 'ready_for_review')->count(),
            'pending_approval' => Job::productionOnly()->where('status', 'pending_customer_approval')->count(),
            'revision_requested' => Job::productionOnly()->where('status', 'revision_requested')->count(),
            'payment_pending' => Job::productionOnly()->where('status', 'payment_pending')->count(),
            'etransfer_to_confirm' => Job::productionOnly()->where('status', 'etransfer_pending_confirmation')->count(),
            'compliance_pending_review' => \App\Models\ContractorDocument::where('status', 'pending_review')->count(),
            'site_visits_today' => \App\Models\SiteVisit::productionOnly()->where('visit_date', today())->count(),
            'site_visits_this_week' => \App\Models\SiteVisit::productionOnly()->whereBetween('visit_date', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'completed_jobs' => Job::productionOnly()->whereIn('status', ['completed', 'paid_completed'])->count(),
            'jobs_awaiting_payment' => $ledger['counts']['unpaid_invoices'],
            'payouts_pending' => $ledger['counts']['open_payouts'],

            'total_leads' => Lead::productionOnly()->count(),
            'active_jobs' => Job::productionOnly()->whereIn('status', ['new_job', 'contractor_assigned', 'quote_sent', 'quote_approved', 'scheduled', 'in_progress', 'ready_for_review'])->count(),
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

            // Deprecated aliases — same ledger values
            'total_profit_month' => $ledger['projected_profit_month'],
            'total_profit_all_time' => $ledger['projected_profit'],
            'revenue_month' => $ledger['collected_revenue'],
            'total_pending_payouts' => $ledger['contractor_liability'] + $ledger['pm_liability'],

            'lead_status_counts' => Lead::productionOnly()->select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            'recent_leads' => Lead::productionOnly()->with(['assignedPm:id,name', 'company:id,name'])->latest()->take(8)->get(),
            'recent_jobs' => Job::productionOnly()->with(['customer:id,name', 'contractor:id,name', 'pm:id,name'])->latest()->take(8)->get(),
        ]);
    }

    public function pm(): JsonResponse
    {
        $id = auth()->id();
        $missingDays = (int) app(\App\Services\Workflow\WorkflowSettings::class)->get('job_missing_update_days');

        $contactNeeded = \App\Models\NextAction::query()
            ->where('responsible_user_id', $id)
            ->whereIn('status', ['pending', 'overdue', 'escalated'])
            ->where('subject_type', (new Lead)->getMorphClass())
            ->where(function ($q) {
                $q->where('action_description', 'like', '%Contact customer%')
                    ->orWhere('escalation_rule', 'pm_contact_lead');
            })
            ->with(['subject'])
            ->orderBy('due_at')
            ->get()
            ->map(function ($na) {
                $lead = $na->subject;

                return [
                    'next_action_id' => $na->id,
                    'lead_id' => $lead?->id,
                    'contact_name' => $lead->contact_name ?? null,
                    'due_at' => $na->due_at,
                    'status' => $na->status,
                    'overdue' => $na->due_at && $na->due_at->isPast(),
                    'action_description' => $na->action_description,
                ];
            });

        $scheduledCalls = Lead::where('assigned_pm_id', $id)
            ->whereNotNull('site_visit_date')
            ->whereIn('status', ['site_visit_scheduled', 'call_scheduled', 'pm_assigned', 'customer_contacted', 'contacted'])
            ->whereDate('site_visit_date', '>=', now()->toDateString())
            ->orderBy('site_visit_date')
            ->take(10)
            ->get(['id', 'contact_name', 'address', 'site_visit_date', 'site_visit_time', 'status']);

        $pricingWaiting = Lead::where('assigned_pm_id', $id)
            ->whereNull('contractor_price')
            ->whereIn('status', ['site_visit_scheduled', 'quote_needed', 'pm_assigned', 'customer_contacted', 'contacted'])
            ->latest()
            ->take(10)
            ->get(['id', 'contact_name', 'address', 'status', 'site_visit_date']);

        $quotesWaiting = Quote::whereIn('status', ['sent', 'viewed', 'follow_up'])
            ->whereHas('job', fn ($q) => $q->where('pm_id', $id))
            ->with(['job:id,address,pm_id'])
            ->latest()
            ->take(10)
            ->get();

        $approvedNeedingSchedule = Job::where('pm_id', $id)
            ->whereIn('status', ['quote_approved', 'waiting_to_schedule', 'estimate_accepted'])
            ->with(['customer:id,name'])
            ->latest()
            ->take(10)
            ->get(['id', 'address', 'status', 'customer_id']);

        $missingUpdates = Job::where('pm_id', $id)
            ->whereIn('status', ['in_progress', 'progress_updated', 'update_posted', 'scheduled'])
            ->whereDoesntHave('updates', fn ($q) => $q->where('created_at', '>=', now()->subDays($missingDays)))
            ->with(['customer:id,name'])
            ->take(10)
            ->get(['id', 'address', 'status', 'customer_id', 'updated_at']);

        $revisionRequests = Job::where('pm_id', $id)
            ->whereIn('status', ['revision_requested', 'corrections_required', 'revision_in_progress'])
            ->with(['customer:id,name'])
            ->latest()
            ->take(10)
            ->get(['id', 'address', 'status', 'customer_id']);

        $awaitingCompletionAcceptance = Job::where('pm_id', $id)
            ->whereIn('status', ['pending_customer_approval', 'completion_requested'])
            ->with(['customer:id,name'])
            ->latest()
            ->take(10)
            ->get(['id', 'address', 'status', 'customer_id', 'pending_customer_approval_at']);

        $feedbackFollowUp = \App\Models\ReviewFeedback::where('pm_id', $id)
            ->where('star_rating', '<', 5)
            ->whereIn('follow_up_status', ['new', 'pm_notified', 'customer_contacted', 'escalated'])
            ->with(['job:id,address', 'customer:id,name'])
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'my_leads' => Lead::where('assigned_pm_id', $id)->whereNotIn('status', ['converted', 'disqualified', 'lost'])->count(),
            'my_active_jobs' => Job::where('pm_id', $id)->whereIn('status', ['new_job', 'created', 'contractor_assigned', 'in_progress', 'scheduled', 'ready_for_review', 'update_posted', 'waiting_to_schedule'])->count(),
            'active_jobs' => Job::where('pm_id', $id)->whereIn('status', ['new_job', 'created', 'contractor_assigned', 'in_progress', 'scheduled', 'update_posted'])->count(),
            'quotes_to_send' => Quote::where('status', 'draft')->whereHas('job', fn ($q) => $q->where('pm_id', $id))->count(),
            'pending_quotes' => Quote::where('status', 'draft')->whereHas('job', fn ($q) => $q->where('pm_id', $id))->count(),
            'awaiting_approval' => Quote::whereIn('status', ['sent', 'viewed', 'follow_up'])->whereHas('job', fn ($q) => $q->where('pm_id', $id))->count(),
            'jobs_in_progress' => Job::where('pm_id', $id)->whereIn('status', ['in_progress', 'update_posted', 'progress_updated'])->count(),
            'jobs_needing_quote_approval' => Job::where('pm_id', $id)
                ->where('contractor_price_status', 'submitted')
                ->with(['customer:id,name'])
                ->get(['id', 'address', 'contractor_submitted_price', 'customer_id', 'contractor_price_submitted_at']),
            'leads_needing_quote_review' => Lead::where('assigned_pm_id', $id)
                ->whereNotNull('contractor_price')
                ->whereDoesntHave('job')
                ->whereIn('status', ['new', 'contacted', 'customer_contacted', 'site_visit_scheduled', 'quote_needed', 'pm_assigned'])
                ->latest()
                ->get(['id', 'contact_name', 'address', 'contractor_price', 'contractor_price_submitted_at', 'service_category', 'status']),
            'recent_leads' => Lead::where('assigned_pm_id', $id)->latest()->take(5)->get(),
            'recent_jobs' => Job::where('pm_id', $id)->with(['contractor:id,name', 'customer:id,name'])->latest()->take(5)->get(),
            'my_leads_list' => Lead::where('assigned_pm_id', $id)
                ->whereNotIn('status', ['converted', 'disqualified', 'lost'])
                ->latest()
                ->take(5)
                ->get(['id', 'contact_name', 'address', 'service_category', 'status', 'site_visit_date', 'site_visit_time']),
            'my_jobs_list' => Job::where('pm_id', $id)->with('contractor:id,name')->latest()->take(5)->get(),
            'recent_updates' => \App\Models\JobUpdate::whereHas('job', fn ($q) => $q->where('pm_id', $id))
                ->with(['job:id,address', 'postedBy:id,name,role'])
                ->latest()->take(5)->get(),

            // Phase 3 workflow sections
            'customers_needing_contact' => $contactNeeded,
            'scheduled_calls_and_visits' => $scheduledCalls,
            'contractor_pricing_waiting' => $pricingWaiting,
            'quotes_waiting_on_customer' => $quotesWaiting,
            'approved_needing_schedule' => $approvedNeedingSchedule,
            'jobs_missing_updates' => $missingUpdates,
            'customer_revision_requests' => $revisionRequests,
            'awaiting_completion_acceptance' => $awaitingCompletionAcceptance,
            'customer_feedback_follow_up' => $feedbackFollowUp,
            'payout_status_note' => 'Payout eligibility + scheduling live (Stripe transfer still mocked until keys).',
            'workflow_thresholds' => app(\App\Services\Workflow\WorkflowSettings::class)->all(),
        ]);
    }

    public function contractor(): JsonResponse
    {
        $user = auth()->user();
        $id = $user->id;
        $assignments = app(\App\Services\Contractors\ContractorAssignmentService::class);
        $contractor = \App\Models\Contractor::where('user_id', $id)->first();
        $jobs = Job::where('contractor_id', $id)->with(['customer:id,name', 'pm:id,name'])->latest()->get();
        $siteVisits = $assignments->upcomingSiteVisitsFor($user);

        return response()->json([
            'assigned_jobs' => $jobs->count(),
            'active_jobs' => $jobs->where('status', 'in_progress')->count(),
            'upcoming_jobs' => $jobs->whereIn('status', ['scheduled', 'contractor_assigned'])->count(),
            'needs_pricing' => $jobs->where('contractor_price_status', 'pending')->count(),
            'pending_payout' => (float) Payout::where('contractor_id', $id)
                ->whereIn('status', ['pending', 'ready_for_payout', 'approved'])
                ->where('payout_type', 'contractor')
                ->sum('payout_amount'),
            'paid_payout_total' => (float) Payout::where('contractor_id', $id)
                ->where('status', 'paid')
                ->where('payout_type', 'contractor')
                ->sum('payout_amount'),
            'jobs_list' => $jobs,
            'site_visits' => $siteVisits,
            'work_items' => $assignments->workItemsFor($user),
            'document_status' => $contractor ? [
                'wcb' => $this->resolveDocStatus($contractor, 'wcb'),
                'insurance' => $this->resolveDocStatus($contractor, 'liability_insurance'),
            ] : ['wcb' => 'not_uploaded', 'insurance' => 'not_uploaded'],
            'contractor_id' => $contractor?->id,
            'contractor_profile' => $contractor ? $contractor->only(['wcb_status', 'liability_insurance_status', 'approval_status']) : null,
            'recent_messages' => \App\Models\Message::where('sender_id', '!=', $id)
                ->where(function ($q) use ($id) {
                    $q->whereHas('job', fn ($jq) => $jq->where('contractor_id', $id))
                        ->orWhere(function ($lq) use ($id) {
                            $lq->whereIn('channel', \App\Services\Messaging\AssignmentMessageService::CONTRACTOR_VISIBLE_CHANNELS)
                                ->whereHas('lead', function ($leadQ) use ($id) {
                                    $leadQ->where('assigned_contractor_id', $id)
                                        ->orWhere('site_visit_contractor_id', $id);
                                });
                        });
                })
                ->with(['sender:id,name,role', 'job:id,address', 'lead:id,contact_name,address'])
                ->latest()->take(5)->get(),
        ]);
    }

    public function customer(): JsonResponse
    {
        $id = auth()->id();

        return response()->json([
            'pending_quotes' => Quote::where('customer_id', $id)->whereIn('status', ['sent', 'viewed'])
                ->with('job:id,address,service_category')->get(),
            'quotes' => Quote::where('customer_id', $id)
                ->with('job:id,address,service_category,status')
                ->get(['id', 'job_id', 'customer_total', 'gst', 'customer_price_before_gst', 'subtotal', 'status', 'sent_at', 'accepted_at', 'customer_token', 'quote_number']),
            'active_jobs' => Job::where('customer_id', $id)->whereNotIn('status', ['completed', 'cancelled', 'paid'])->get(),
            'jobs' => Job::where('customer_id', $id)->get(['id', 'address', 'service_category', 'status', 'scheduled_start_date', 'scheduled_end_date', 'estimated_completion_date']),
            'invoices' => Invoice::where('customer_id', $id)->get(),
            'recent_updates' => \App\Models\JobUpdate::where('visibility', 'customer_visible')
                ->whereHas('job', fn ($q) => $q->where('customer_id', $id))
                ->with(['job:id,address', 'photos', 'postedBy:id,name'])
                ->latest()->take(5)->get(),
            'unread_messages' => \App\Models\Message::where('visibility', 'customer_visible')
                ->whereHas('job', fn ($q) => $q->where('customer_id', $id))
                ->where('sender_id', '!=', $id)
                ->where('is_read', false)
                ->count(),
        ]);
    }

    private function resolveDocStatus(\App\Models\Contractor $contractor, string $type): string
    {
        $statusField = $type === 'wcb' ? 'wcb_status' : 'liability_insurance_status';
        $expiryField = $type === 'wcb' ? 'wcb_expiry_date' : 'insurance_expiry_date';
        $status = $contractor->$statusField ?? 'not_uploaded';

        if ($status === 'approved' && $contractor->$expiryField) {
            $expiry = \Carbon\Carbon::parse($contractor->$expiryField);
            if ($expiry->isPast()) {
                return 'expired';
            }
            if ($expiry->isFuture() && $expiry->diffInDays(now(), absolute: true) <= 30) {
                return 'expiring_soon';
            }
        }

        return $status;
    }

    public function kpis(): JsonResponse
    {
        return $this->admin();
    }
}
