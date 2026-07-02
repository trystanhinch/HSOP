<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\JobNotificationService;
use App\Services\PayoutWorkflowService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    public function __construct(
        protected PricingService $pricing,
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Quote::with(['job:id,address,job_title', 'customer:id,name']);

        if ($user->role === 'pm') {
            $query->whereHas('job', fn ($q) => $q->where('pm_id', $user->id));
        } elseif ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif ($user->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->status) {
            $query->where('status', $request->status);
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

        if ($request->user()->role === 'pm' && $job->pm_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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

        $quote = Quote::create([
            'job_id' => $job->id,
            'company_id' => $job->company_id,
            'customer_id' => $job->customer_id,
            'quote_number' => 'QT-'.str_pad(Quote::count() + 1, 4, '0', STR_PAD_LEFT),
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
        ]);

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

        return response()->json(new QuoteResource($quote));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $quote = Quote::findOrFail($id);

        if (! in_array($quote->status, ['draft', 'revised'])) {
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

        return response()->json($quote->fresh()->load('items'));
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

        if (! $quote->customer?->email) {
            return response()->json(['message' => 'Cannot send quote: customer has no email on file. Please add one first.'], 422);
        }

        $token = Str::random(64);
        $quote->update([
            'status' => 'sent',
            'customer_token' => $token,
            'sent_at' => now(),
        ]);
        $quote->job->update(['status' => 'quote_sent']);

        $quoteUrl = $this->notifications->frontendUrl('quote/view/'.$token);
        $this->notifications->quoteSent($quote->fresh(), $quoteUrl);

        return response()->json(['message' => 'Quote sent', 'quote_url' => $quoteUrl, 'token' => $token]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $quote = Quote::with('job')->findOrFail($id);

        if ($quote->customer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($quote->status, ['sent', 'viewed'])) {
            return response()->json(['message' => 'Quote cannot be approved in current status'], 422);
        }

        $quote->update(['status' => 'approved', 'accepted_at' => now()]);
        $quote->job->update(['status' => 'quote_approved']);
        $this->payouts->createPayoutsOnQuoteApproval($quote->fresh());
        $this->notifications->quoteApproved($quote->fresh());

        return response()->json(['message' => 'Quote approved']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $quote = Quote::with('job')->findOrFail($id);

        if ($quote->customer_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $quote->update(['status' => 'rejected', 'rejection_reason' => $request->rejection_reason]);
        $quote->job->update(['status' => 'waiting_on_customer']);
        $this->notifications->quoteRejected($quote->fresh());

        return response()->json(['message' => 'Quote rejected']);
    }

    public function viewByToken(string $token): JsonResponse
    {
        $quote = Quote::where('customer_token', $token)
            ->with([
                'job:id,address,service_category,status,scope_of_work,scheduled_start_date,estimated_completion_date,scheduled_end_date,company_id,pm_id',
                'job.company:id,name,phone,email',
                'job.pm:id,name',
                'items',
            ])
            ->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        if ($quote->status === 'sent') {
            $quote->update(['status' => 'viewed', 'viewed_at' => now()]);
        }

        return response()->json([
            'quote_number' => $quote->quote_number,
            'status' => $quote->status,
            'scope_of_work' => $quote->scope_of_work,
            'customer_notes' => $quote->customer_notes,
            'subtotal' => $quote->subtotal ?? $quote->customer_price_before_gst,
            'gst' => $quote->gst,
            'gst_rate' => $quote->gst_rate,
            'customer_total' => $quote->customer_total,
            'gst_enabled' => $quote->gst_enabled,
            'items' => $quote->items,
            'sent_at' => $quote->sent_at,
            'accepted_at' => $quote->accepted_at,
            'job' => [
                'address' => $quote->job->address ?? '',
                'service_category' => $quote->job->service_category ?? '',
                'scheduled_start_date' => $quote->job->scheduled_start_date,
                'estimated_completion' => $quote->job->estimated_completion_date ?? $quote->job->scheduled_end_date,
                'scope_of_work' => $quote->job->scope_of_work ?? '',
                'company_name' => optional($quote->job->company)->name ?? 'HSOP',
                'pm_name' => optional($quote->job->pm)->name ?? '',
            ],
        ]);
    }

    public function approveByToken(string $token): JsonResponse
    {
        $quote = Quote::with('job')->where('customer_token', $token)->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        if (! in_array($quote->status, ['sent', 'viewed'])) {
            return response()->json(['message' => 'Quote cannot be approved'], 422);
        }

        $quote->update(['status' => 'approved', 'accepted_at' => now()]);
        $quote->job->update(['status' => 'quote_approved']);
        $this->payouts->createPayoutsOnQuoteApproval($quote->fresh());
        $this->notifications->quoteApproved($quote->fresh());

        return response()->json(['message' => 'Quote approved. Thank you!']);
    }

    public function rejectByToken(Request $request, string $token): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string']);
        $quote = Quote::with('job')->where('customer_token', $token)->first();

        if (! $quote) {
            return response()->json(['message' => 'This link is invalid or has expired.'], 404);
        }

        $quote->update(['status' => 'rejected', 'rejection_reason' => $request->rejection_reason]);
        $quote->job->update(['status' => 'waiting_on_customer']);
        $this->notifications->quoteRejected($quote->fresh());

        return response()->json(['message' => 'Quote rejected. The team has been notified.']);
    }
}
