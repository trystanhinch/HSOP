<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\Quote;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Workflow\JobLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    public function admin(Request $request): JsonResponse
    {
        $filters = array_filter([
            'brand_id' => $request->query('brand_id'),
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json($this->metrics->adminKpis($filters));
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
            ->whereIn('status', JobLifecycleService::NEEDS_SCHEDULE_STATUSES)
            ->with(['customer:id,name'])
            ->latest()
            ->take(10)
            ->get(['id', 'address', 'status', 'customer_id']);

        $missingUpdates = Job::where('pm_id', $id)
            ->whereIn('status', array_values(array_unique([
                ...JobLifecycleService::IN_PROGRESS_STATUSES,
                'scheduled',
            ])))
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

        $activeJobs = $this->metrics->countPmActiveJobs($id);
        $inProgress = $this->metrics->countPmInProgress($id);
        $quotesToSend = Quote::whereIn('status', ['draft', 'revised'])->whereHas('job', fn ($q) => $q->where('pm_id', $id))->count();
        $quotesWaitingCustomer = Quote::whereIn('status', ['sent', 'viewed', 'follow_up'])
            ->whereHas('job', fn ($q) => $q->where('pm_id', $id))
            ->count();
        $myLeadsCount = Lead::where('assigned_pm_id', $id)
            ->whereNotIn('status', ['converted', 'disqualified', 'lost', 'ignored'])
            ->count();

        $stripeUser = auth()->user();
        $stripeMode = app(\App\Services\Payments\PaymentDestinationService::class)->paymentModeLabel();
        $stripeLabel = ! $stripeUser->stripe_account_id
            ? 'Not connected'
            : ($stripeUser->stripe_payout_ready
                ? 'Ready for payouts'
                : ($stripeUser->stripe_onboarding_status
                    ? 'Onboarding: '.$stripeUser->stripe_onboarding_status
                    : 'Connected — setup incomplete'));

        return response()->json([
            'my_leads' => $myLeadsCount,
            // A-09: single active definition (same ACTIVE set as admin, PM-scoped)
            'my_active_jobs' => $activeJobs,
            'active_jobs' => $activeJobs,
            'quotes_to_send' => $quotesToSend,
            'pending_quotes' => $quotesToSend,
            // PM-08: explicit "waiting on customer" — not ambiguous "Awaiting Approval"
            'awaiting_approval' => $quotesWaitingCustomer,
            'quotes_waiting_on_customer_count' => $quotesWaitingCustomer,
            'jobs_in_progress' => $inProgress,
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
                ->whereNotIn('status', ['converted', 'disqualified', 'lost', 'ignored'])
                ->latest()
                ->take(5)
                ->get(['id', 'contact_name', 'address', 'service_category', 'status', 'site_visit_date', 'site_visit_time']),
            // PM-08: preview matches active_jobs card (same ACTIVE set), not all recent jobs
            'my_jobs_list' => Job::where('pm_id', $id)
                ->whereIn('status', \App\Services\Workflow\JobLifecycleService::ACTIVE_JOB_STATUSES)
                ->with('contractor:id,name')
                ->latest()
                ->take(5)
                ->get(),
            'recent_updates' => \App\Models\JobUpdate::whereHas('job', fn ($q) => $q->where('pm_id', $id))
                ->with(['job:id,address', 'postedBy:id,name,role'])
                ->latest()->take(5)->get(),

            'customers_needing_contact' => $contactNeeded,
            'scheduled_calls_and_visits' => $scheduledCalls,
            'contractor_pricing_waiting' => $pricingWaiting,
            'quotes_waiting_on_customer' => $quotesWaiting,
            'approved_needing_schedule' => $approvedNeedingSchedule,
            'jobs_missing_updates' => $missingUpdates,
            'customer_revision_requests' => $revisionRequests,
            'awaiting_completion_acceptance' => $awaitingCompletionAcceptance,
            'customer_feedback_follow_up' => $feedbackFollowUp,
            // PM-05: no legacy "mocked" wording — StripeConnectCard is authoritative
            'payout_status_note' => null,
            'stripe_connect' => [
                'provider' => config('payment.provider'),
                'mode' => $stripeMode,
                'status_label' => $stripeLabel,
                'payout_ready' => (bool) $stripeUser->stripe_payout_ready,
                'has_stripe_account' => filled($stripeUser->stripe_account_id),
                'stripe_account_ref' => filled($stripeUser->stripe_account_id)
                    ? '…'.substr((string) $stripeUser->stripe_account_id, -4)
                    : null,
            ],
            'workflow_thresholds' => app(\App\Services\Workflow\WorkflowSettings::class)->all(),
            'refreshed_at' => now()->toIso8601String(),
            'filters' => [
                'pm_id' => $id,
                'date_range' => 'all_time',
                'scope' => 'pm_assigned',
                'brand_scope' => 'assigned_brands_via_own_work',
            ],
            'metric_definitions' => [
                'my_leads' => [
                    'label' => 'Active leads assigned to me',
                    'entity' => 'leads',
                    'filter' => 'assigned_pm_id = me AND status NOT IN (converted, disqualified, lost, ignored)',
                    'date_range' => 'all_time',
                    'scope' => 'pm_assigned + productionOnly (A-05)',
                    'href' => '/leads?view=active',
                ],
                'active_jobs' => array_merge($this->metrics->definitions()['active_jobs'], [
                    'label' => 'My active jobs',
                    'scope' => 'pm_id = me + productionOnly (A-05)',
                    'href' => '/jobs?status=active',
                ]),
                'quotes_to_send' => [
                    'label' => 'Draft quotes ready to send',
                    'entity' => 'quotes',
                    'filter' => 'status IN (draft, revised) AND job.pm_id = me',
                    'date_range' => 'all_time',
                    'scope' => 'pm_assigned + productionOnly (A-05)',
                    'href' => '/quotes?status=draft',
                ],
                'quotes_waiting_on_customer' => [
                    'label' => 'Quotes sent — waiting on customer',
                    'entity' => 'quotes',
                    'filter' => 'status IN (sent, viewed, follow_up) AND job.pm_id = me',
                    'date_range' => 'all_time',
                    'scope' => 'pm_assigned + productionOnly (A-05)',
                    'href' => '/quotes?status=waiting_on_customer',
                ],
                'jobs_in_progress' => $this->metrics->definitions()['jobs_in_progress'],
            ],
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
            // A-09: same in-progress bucket as admin/PM
            'active_jobs' => $this->metrics->countContractorActiveJobs($id),
            'upcoming_jobs' => $jobs->whereIn('status', ['scheduled', 'contractor_assigned', 'start_date_scheduled'])->count(),
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
            'refreshed_at' => now()->toIso8601String(),
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
            // A-08: exclude completed lifecycle bucket (payment is invoice-side)
            'active_jobs' => Job::where('customer_id', $id)
                ->whereNotIn('status', array_merge(JobLifecycleService::COMPLETED_STATUSES, ['cancelled']))
                ->get(),
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

    public function kpis(Request $request): JsonResponse
    {
        return $this->admin($request);
    }
}
