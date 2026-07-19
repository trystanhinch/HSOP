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
        return response()->json([
            'new_leads' => Lead::where('status', 'new')->count(),
            'leads_needing_review' => Lead::where('needs_manual_review', true)->count(),
            'leads_needing_followup' => Lead::where('status', 'contacted')->where('updated_at', '<', now()->subDays(2))->count(),
            'jobs_awaiting_price' => Job::where('contractor_price_status', 'pending')->count(),
            'quotes_needing_review' => Quote::where('status', 'draft')->count(),
            'quotes_sent' => Quote::where('status', 'sent')->count(),
            'approved_needing_schedule' => Job::where('status', 'quote_approved')->count(),
            'scheduled_jobs' => Job::where('status', 'scheduled')->count(),
            'jobs_in_progress' => Job::where('status', 'in_progress')->count(),
            'jobs_ready_for_review' => Job::where('status', 'ready_for_review')->count(),
            'pending_approval' => Job::where('status', 'pending_customer_approval')->count(),
            'revision_requested' => Job::where('status', 'revision_requested')->count(),
            'payment_pending' => Job::where('status', 'payment_pending')->count(),
            'etransfer_to_confirm' => Job::where('status', 'etransfer_pending_confirmation')->count(),
            'site_visits_today' => \App\Models\SiteVisit::where('visit_date', today())->count(),
            'site_visits_this_week' => \App\Models\SiteVisit::whereBetween('visit_date', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'completed_jobs' => Job::whereIn('status', ['completed', 'paid_completed'])->count(),
            'jobs_awaiting_payment' => Invoice::whereIn('status', ['invoice_sent', 'awaiting_payment', 'partially_paid', 'sent', 'draft'])->where('balance', '>', 0)->count(),
            'payouts_pending' => Payout::whereIn('status', ['pending', 'ready_for_payout', 'approved'])->count(),

            'total_leads' => Lead::count(),
            'active_jobs' => Job::whereIn('status', ['new_job', 'contractor_assigned', 'quote_sent', 'quote_approved', 'scheduled', 'in_progress', 'ready_for_review'])->count(),
            'total_contractors' => \App\Models\User::where('role', 'contractor')->count(),
            'total_customers' => \App\Models\User::where('role', 'customer')->count(),
            'revenue_month' => (float) Invoice::where('status', 'paid')->whereMonth('created_at', now()->month)->sum('amount'),
            'total_profit_month' => (float) Quote::where('status', 'approved')
                ->whereMonth('accepted_at', now()->month)
                ->whereYear('accepted_at', now()->year)
                ->sum('hsop_markup'),
            'total_profit_all_time' => (float) Quote::where('status', 'approved')->sum('hsop_markup'),
            'total_collected_revenue' => (float) Invoice::where('status', 'paid')->sum('amount'),
            'total_pending_payouts' => (float) Payout::whereIn('status', ['pending', 'ready_for_payout', 'approved'])->sum('payout_amount'),
            'lead_status_counts' => Lead::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
            'recent_leads' => Lead::with(['assignedPm:id,name', 'company:id,name'])->latest()->take(8)->get(),
            'recent_jobs' => Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name'])->latest()->take(8)->get(),
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
            'payout_status_note' => 'Coming soon — payout amounts land in Phase 4.',
            'workflow_thresholds' => app(\App\Services\Workflow\WorkflowSettings::class)->all(),
        ]);
    }

    public function contractor(): JsonResponse
    {
        $id = auth()->id();
        $contractor = \App\Models\Contractor::where('user_id', $id)->first();
        $jobs = Job::where('contractor_id', $id)->with(['customer:id,name', 'pm:id,name'])->latest()->get();

        $siteVisits = SiteVisit::where('contractor_id', $id)
            ->where('status', 'scheduled')
            ->whereDate('visit_date', '>=', now()->toDateString())
            ->with(['lead:id,contact_name,address,service_category,project_description,status'])
            ->orderBy('visit_date')
            ->get()
            ->map(fn ($sv) => [
                'type' => 'site_visit',
                'id' => $sv->id,
                'lead_id' => $sv->lead_id,
                'address' => $sv->lead->address ?? '',
                'customer_name' => $sv->lead->contact_name ?? '',
                'service' => $sv->lead->service_category ?? '',
                'description' => $sv->lead->project_description ?? '',
                'visit_date' => $sv->visit_date,
                'visit_time' => $sv->visit_time,
                'status' => 'site_visit_scheduled',
                'url' => '/leads/'.$sv->lead_id,
            ])
            ->values();

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
            'document_status' => [
                'wcb' => $contractor->wcb_status ?? 'not_uploaded',
                'insurance' => $contractor->liability_insurance_status ?? 'not_uploaded',
            ],
            'contractor_profile' => $contractor ? $contractor->only(['wcb_status', 'liability_insurance_status', 'approval_status']) : null,
            'recent_messages' => \App\Models\Message::where('sender_id', '!=', $id)
                ->whereHas('job', fn ($q) => $q->where('contractor_id', $id))
                ->with(['sender:id,name,role', 'job:id,address'])
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

    public function kpis(): JsonResponse
    {
        return $this->admin();
    }
}
