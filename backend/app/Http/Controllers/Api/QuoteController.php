<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Job;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\Authorization\PmAuthorizationService;
use App\Services\BrandResolver;
use App\Services\JobNotificationService;
use App\Services\LeadQuoteWorkflowService;
use App\Services\PayoutWorkflowService;
use App\Services\PricingService;
use App\Services\Workflow\QuoteLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    public function __construct(
        protected PricingService $pricing,
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts,
        protected LeadQuoteWorkflowService $leadQuotes,
        protected PmAuthorizationService $authz,
        protected QuoteLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Quote::with([
            'job:id,address,job_title,pm_id,contractor_id,company_id,service_category',
            'job.pm:id,name',
            'job.contractor:id,name',
            'job.company:id,name',
            'customer:id,name',
        ]);

        if ($user->role === 'pm') {
            $this->authz->scopeQuotesForPm($query, $user);
        } elseif ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif ($user->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('quote_number', 'like', "%{$q}%")
                    ->orWhere('scope_of_work', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)->orWhere('job_id', (int) $q);
                }
            });
        }

        $status = $request->query('status');
        if ($status === 'follow_up_due' || $status === 'follow_up') {
            $query->whereIn('status', ['sent', 'viewed'])
                ->whereNotNull('follow_up_due_at')
                ->whereNull('follow_up_stopped_at');
        } elseif ($status) {
            $expanded = $this->lifecycle->expandFilterStatus($status);
            if ($expanded) {
                $query->whereIn('status', $expanded);
            }
        }

        if ($request->filled('brand_id')) {
            $query->whereHas('job', fn ($j) => $j->where('company_id', (int) $request->brand_id));
        }
        if ($request->filled('pm_id')) {
            $query->whereHas('job', fn ($j) => $j->where('pm_id', (int) $request->pm_id));
        }
        if ($request->filled('contractor_id')) {
            $query->whereHas('job', fn ($j) => $j->where('contractor_id', (int) $request->contractor_id));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }
        if ($request->filled('viewed')) {
            if ($request->boolean('viewed')) {
                $query->whereNotNull('viewed_at');
            } else {
                $query->whereNull('viewed_at')->whereIn('status', ['sent', 'viewed', 'draft', 'internal_review']);
            }
        }
        if ($request->filled('expired')) {
            if ($request->boolean('expired')) {
                $query->where(function ($w) {
                    $w->where('status', 'expired')->orWhereNotNull('expired_at');
                });
            } else {
                $query->where('status', '!=', 'expired')->whereNull('expired_at');
            }
        }
        if ($request->filled('revision_number')) {
            $query->where('revision_number', (int) $request->revision_number);
        }

        return response()->json(QuoteResource::collection($query->latest()->paginate(20)));
    }

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'job_id' => 'required|exists:jobs,id',
            'scope_of_work' => 'required|string',
            'contractor_price' => 'nullable|numeric|min:1',
            'subtotal' => 'nullable|numeric|min:0',
            'gst_enabled' => 'boolean',
            'gst_rate' => 'nullable|numeric',
            'internal_notes' => 'nullable|string',
            'customer_notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'nullable|string',
        ]);

        $job = Job::findOrFail($request->job_id);
        $this->authz->assertJobAccess($request->user(), $job);

        if (! $job->customer_id) {
            return response()->json(['message' => 'Cannot create estimate: no customer attached to this job.'], 422);
        }

        $contractorBase = (float) ($request->contractor_price ?? $job->contractor_submitted_price ?? 0);
        $gstEnabled = $request->boolean('gst_enabled', true);

        if ($request->filled('subtotal')) {
            $subtotal = (float) $request->subtotal;
            $split = $this->pricing->splitFromJob($job);
            $pmAmount = round($subtotal * ($split['pm_pct'] / 100), 2);
            $companyAmount = round($subtotal * ($split['company_pct'] / 100), 2);
            $markup = $pmAmount + $companyAmount;
            $totals = $this->pricing->calculateTotals($subtotal, $gstEnabled, $request->gst_rate);
            $splitFields = [
                'contractor_pct' => $split['contractor_pct'],
                'pm_pct' => $split['pm_pct'],
                'company_pct' => $split['company_pct'],
                'pm_amount' => $pmAmount,
                'company_amount' => $companyAmount,
            ];
        } elseif ($contractorBase > 0) {
            $calc = $this->pricing->fromContractorPrice($contractorBase, $gstEnabled, $job);
            $subtotal = $calc['customer_subtotal'];
            $markup = $calc['hsop_markup'];
            $totals = $calc;
            $splitFields = [
                'contractor_pct' => $calc['contractor_pct'],
                'pm_pct' => $calc['pm_pct'],
                'company_pct' => $calc['company_pct'],
                'pm_amount' => $calc['pm_amount'],
                'company_amount' => $calc['company_amount'],
            ];
        } else {
            return response()->json(['message' => 'Please provide contractor_price or ensure contractor price is submitted.'], 422);
        }

        $brandNameSnapshot = app(BrandResolver::class)->forJob($job);

        $quote = Quote::createWithUniqueQuoteNumber([
            'job_id' => $job->id,
            'company_id' => $job->company_id,
            'customer_id' => $job->customer_id,
            'scope_of_work' => $request->scope_of_work,
            'subtotal' => $subtotal,
            'customer_price_before_gst' => $subtotal,
            'contractor_base_price' => $contractorBase,
            'hsop_markup' => $markup,
            ...$splitFields,
            'gst_enabled' => $gstEnabled,
            'gst_rate' => $totals['gst_rate'],
            'gst' => $totals['gst'],
            'customer_total' => $totals['customer_total'],
            'internal_notes' => $request->internal_notes,
            'customer_notes' => $request->customer_notes,
            'status' => 'draft',
            'revision_number' => 1,
            'is_immutable' => false,
            'brand_name_snapshot' => $brandNameSnapshot,
        ]);
        $quote->update(['root_quote_id' => $quote->id]);

        if ($contractorBase > 0 && $job->contractor_price_status !== 'approved') {
            $job->update([
                'contractor_submitted_price' => $contractorBase,
                'contractor_price_status' => 'submitted',
            ]);
        }

        if ($request->items) {
            foreach ($request->items as $i => $item) {
                $itemTotal = round($item['quantity'] * $item['unit_price'], 2);
                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $item['unit_price'],
                    'total' => $itemTotal,
                    'sort_order' => $i,
                ]);
            }
        }

        $this->notifications->audit('quote_created', 'quote', $quote->id);

        return response()->json(new QuoteResource($quote->load('items')), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $quote = Quote::with(['job', 'customer:id,name', 'items'])->findOrFail($id);
        $user = $request->user();

        if ($user->role === 'customer' && $quote->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess($user, $quote);

        return response()->json(new QuoteResource($quote));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $quote = Quote::findOrFail($id);
        $this->authz->assertQuoteAccess($request->user(), $quote);

        if ($quote->is_immutable || ! in_array($quote->status, QuoteLifecycleService::EDITABLE, true)) {
            return response()->json(['message' => 'Quote cannot be edited in current status'], 422);
        }

        $data = $request->validate([
            'scope_of_work' => 'sometimes|string',
            'subtotal' => 'sometimes|numeric|min:0',
            'gst_enabled' => 'boolean',
            'customer_notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
        ]);

        if (isset($data['subtotal'])) {
            $gstEnabled = $request->boolean('gst_enabled', $quote->gst_enabled);
            $totals = $this->pricing->calculateTotals((float) $data['subtotal'], $gstEnabled, $quote->gst_rate);
            $data['customer_price_before_gst'] = $data['subtotal'];
            $data['gst'] = $totals['gst'];
            $data['customer_total'] = $totals['customer_total'];
            $data['hsop_markup'] = max(0, $data['subtotal'] - ($quote->contractor_base_price ?? 0));
        }

        $quote->update($data);

        return response()->json(new QuoteResource($quote->fresh()->load('items')));
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Not allowed'], 403);
    }

    public function send(string $id): JsonResponse
    {
        $quote = Quote::with(['job', 'customer:id,name,email'])->findOrFail($id);

        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->authz->assertQuoteAccess(auth()->user(), $quote);

        if (! $quote->customer?->email) {
            return response()->json(['message' => 'Cannot send quote: customer has no email on file. Please add one first.'], 422);
        }

        $token = $quote->customer_token ?: Str::random(64);
        $quote = $this->lifecycle->markSent($quote, $token);
        if ($quote->job) {
            $quote->job->update(['status' => 'quote_sent']);
        }

        $quoteUrl = $this->notifications->frontendUrl('quote/view/'.$token);
        $this->notifications->quoteSent($quote->fresh(['customer', 'job']), $quoteUrl);

        return response()->json([
            'message' => 'Quote sent',
            'quote_url' => $quoteUrl,
            'token' => $token,
            'quote' => new QuoteResource($quote->fresh(['job', 'customer', 'items'])),
        ]);
    }

    public function resend(string $id): JsonResponse
    {
        $quote = Quote::with(['job', 'customer:id,name,email'])->findOrFail($id);

        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);

        if (! in_array($quote->status, ['sent', 'viewed'], true)) {
            return response()->json(['message' => 'Only sent/viewed quotes can be resent'], 422);
        }
        if (! $quote->customer?->email) {
            return response()->json(['message' => 'Cannot resend: customer has no email on file.'], 422);
        }

        $token = $quote->customer_token ?: Str::random(64);
        if (! $quote->customer_token) {
            $quote->update(['customer_token' => $token]);
        }

        $quoteUrl = $this->notifications->frontendUrl('quote/view/'.$token);
        $this->notifications->quoteSent($quote->fresh(['customer', 'job']), $quoteUrl);

        return response()->json([
            'message' => 'Quote resent',
            'quote_url' => $quoteUrl,
            'quote' => new QuoteResource($quote->fresh(['job', 'customer', 'items'])),
        ]);
    }

    public function markInternalReview(string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);
        $quote = $this->lifecycle->markInternalReview($quote);

        return response()->json(['message' => 'Marked for internal review', 'quote' => new QuoteResource($quote)]);
    }

    public function revise(string $id): JsonResponse
    {
        $quote = Quote::with('items')->findOrFail($id);
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);

        $originalSnapshot = $quote->only([
            'id', 'quote_number', 'revision_number', 'status', 'customer_total', 'subtotal', 'gst',
            'contractor_base_price', 'scope_of_work', 'sent_at', 'is_immutable',
        ]);

        $revision = $this->lifecycle->createRevision($quote);

        return response()->json([
            'message' => 'Revision created',
            'original' => $originalSnapshot,
            'quote' => new QuoteResource($revision->load(['job', 'customer', 'items'])),
        ], 201);
    }

    public function followUp(string $id): JsonResponse
    {
        $quote = Quote::with('job')->findOrFail($id);
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);

        if (! in_array($quote->status, ['sent', 'viewed'], true)) {
            return response()->json(['message' => 'Follow-up only applies to sent/viewed quotes'], 422);
        }

        $job = $quote->job;
        if (! $job) {
            return response()->json(['message' => 'Quote has no job for follow-up task'], 422);
        }

        $na = NextAction::query()
            ->where('subject_type', $job->getMorphClass())
            ->where('subject_id', $job->id)
            ->where('escalation_rule', QuoteLifecycleService::FOLLOW_UP_RULE)
            ->whereIn('status', ['pending', 'overdue', 'escalated'])
            ->latest('id')
            ->first();

        if (! $na) {
            $na = NextAction::create([
                'subject_type' => $job->getMorphClass(),
                'subject_id' => $job->id,
                'escalation_rule' => QuoteLifecycleService::FOLLOW_UP_RULE,
                'action_description' => 'Follow up with customer on quote #'.$quote->id,
                'responsible_role' => 'pm',
                'responsible_user_id' => $job->pm_id,
                'due_at' => now(),
                'status' => 'pending',
                'last_action_at' => now(),
            ]);
        }

        $quote = $this->lifecycle->flagFollowUpDue($quote, $na);

        return response()->json([
            'message' => 'Follow-up task open (quote status unchanged)',
            'next_action_id' => $na->id,
            'quote' => new QuoteResource($quote->fresh(['job', 'customer', 'items'])),
        ]);
    }

    public function expire(string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);
        $quote = $this->lifecycle->expire($quote);

        return response()->json(['message' => 'Quote expired', 'quote' => new QuoteResource($quote)]);
    }

    public function markDeclined(Request $request, string $id): JsonResponse
    {
        $quote = Quote::findOrFail($id);
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertQuoteAccess(auth()->user(), $quote);
        $data = $request->validate(['rejection_reason' => 'nullable|string|max:1000']);
        $quote = $this->lifecycle->decline($quote, $data['rejection_reason'] ?? null);

        return response()->json(['message' => 'Quote declined', 'quote' => new QuoteResource($quote)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $quote = Quote::with('job')->findOrFail($id);

        if ($quote->customer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $quote = $this->leadQuotes->approveQuote($quote);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        $this->payouts->createPayoutsOnQuoteApproval($quote);
        $this->notifications->quoteApproved($quote);

        return response()->json(['message' => 'Quote approved']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $quote = Quote::with('job')->findOrFail($id);

        if ($quote->customer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['rejection_reason' => 'required|string|max:1000']);
        try {
            $quote = $this->leadQuotes->rejectQuote($quote, $request->rejection_reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        $this->notifications->quoteRejected($quote);

        return response()->json(['message' => 'Quote rejected']);
    }

    public function viewByToken(string $token): JsonResponse
    {
        $quote = Quote::withTestData()
            ->where('customer_token', $token)
            ->with([
                'customer:id,name,email,phone',
                'lead' => fn ($q) => $q->withTestData()->select('id', 'contact_name', 'address', 'service_category', 'company_id', 'assigned_pm_id'),
                'lead.company:id,name,phone,email',
                'lead.assignedPm:id,name,email,phone',
                'job' => fn ($q) => $q->withTestData()->select('id', 'address', 'service_category', 'status', 'scope_of_work', 'scheduled_start_date', 'estimated_completion_date', 'scheduled_end_date', 'company_id', 'pm_id'),
                'job.company:id,name,phone,email',
                'job.pm:id,name,email,phone',
                'items',
            ])
            ->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        if ($quote->status === 'sent') {
            $this->lifecycle->markViewed($quote);
            $quote->refresh();
        }

        $address = $quote->job?->address ?? $quote->lead?->address ?? '';
        $serviceCategory = $quote->job?->service_category ?? $quote->lead?->service_category ?? '';
        $jobStatus = $quote->job?->status ?? '';
        $scopeOfWork = $quote->scope_of_work ?: ($quote->job?->scope_of_work ?? $quote->lead?->project_description ?? '');
        $companyName = app(BrandResolver::class)->forQuote($quote);
        $pm = $quote->job?->pm ?? $quote->lead?->assignedPm;

        // Customer-facing: totals only — never contractor/margin/split.
        return response()->json([
            'quote_number' => $quote->quote_number,
            'revision_number' => (int) ($quote->revision_number ?? 1),
            'status' => $this->lifecycle->normalizeStatus($quote->status),
            'customer_name' => $quote->customer?->name,
            'scope_of_work' => $scopeOfWork,
            'customer_notes' => $quote->customer_notes,
            'subtotal' => $quote->subtotal ?? $quote->customer_price_before_gst,
            'gst' => $quote->gst,
            'gst_rate' => $quote->gst_rate,
            'customer_total' => $quote->customer_total,
            'gst_enabled' => $quote->gst_enabled,
            'items' => $quote->items,
            'sent_at' => $quote->sent_at,
            'viewed_at' => $quote->viewed_at,
            'accepted_at' => $quote->accepted_at,
            'declined_at' => $quote->declined_at,
            'expired_at' => $quote->expired_at,
            'job' => [
                'address' => $address,
                'service_category' => $serviceCategory,
                'status' => $jobStatus,
                'scheduled_start_date' => $quote->job?->scheduled_start_date,
                'estimated_completion' => $quote->job?->estimated_completion_date ?? $quote->job?->scheduled_end_date,
                'scope_of_work' => $scopeOfWork,
                'company_name' => $companyName,
                'pm_name' => $pm?->name ?? '',
                'pm_email' => $pm?->email,
                'pm_phone' => $pm?->phone,
            ],
        ]);
    }

    public function approveByToken(string $token): JsonResponse
    {
        $quote = Quote::withTestData()->with(['job', 'lead'])->where('customer_token', $token)->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        try {
            $quote = $this->leadQuotes->approveQuote($quote);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        $this->payouts->createPayoutsOnQuoteApproval($quote);
        $this->notifications->quoteApproved($quote);

        return response()->json(['message' => 'Quote approved. Thank you!']);
    }

    public function rejectByToken(Request $request, string $token): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string']);
        $quote = Quote::withTestData()->with('job')->where('customer_token', $token)->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        try {
            $quote = $this->leadQuotes->rejectQuote($quote, $request->rejection_reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        $this->notifications->quoteRejected($quote);

        return response()->json(['message' => 'Quote rejected. The team has been notified.']);
    }
}
