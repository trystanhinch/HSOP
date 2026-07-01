<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\RevisionRequest;
use App\Models\RevisionRequestPhoto;
use App\Models\Setting;
use App\Models\User;
use App\Mail\JobReadyForReviewMail;
use App\Mail\PaymentConfirmedMail;
use App\Mail\RevisionRequestedMail;
use App\Services\EmailService;
use App\Services\JobNotificationService;
use App\Services\PricingService;
use App\Services\PayoutWorkflowService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JobController extends Controller
{
    public function __construct(
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts,
        protected PricingService $pricing,
        protected SmsService $sms,
        protected EmailService $email
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name', 'company:id,name', 'invoice', 'payout']);

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        } elseif ($user->role === 'contractor') {
            $query->where('contractor_id', $user->id);
        } elseif ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        }

        if ($request->status && $request->status !== 'All') {
            $status = str_replace(' ', '_', strtolower($request->status));
            $query->where('status', $status);
        }

        if ($request->q) {
            $s = $request->q;
            $query->where(function ($qq) use ($s) {
                $qq->where('address', 'like', "%{$s}%")
                    ->orWhere('job_title', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }
        if ($request->contractor_id) {
            $query->where('contractor_id', $request->contractor_id);
        }
        if ($request->pm_id) {
            $query->where('pm_id', $request->pm_id);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->payment_status) {
            $query->whereHas('invoice', fn ($i) => $i->where('status', $request->payment_status));
        }
        if ($request->payout_status) {
            $query->whereHas('payout', fn ($p) => $p->where('status', $request->payout_status));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function search(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name', 'invoice', 'payout']);

        if ($request->user()->role === 'pm') {
            $query->where('pm_id', $request->user()->id);
        }

        if ($request->q) {
            $s = $request->q;
            $query->where(function ($qq) use ($s) {
                $qq->where('address', 'like', "%{$s}%")
                    ->orWhere('job_title', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"));
            });
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->contractor_id) {
            $query->where('contractor_id', $request->contractor_id);
        }
        if ($request->pm_id) {
            $query->where('pm_id', $request->pm_id);
        }
        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->payment_status) {
            $query->whereHas('invoice', fn ($i) => $i->where('status', $request->payment_status));
        }
        if ($request->payout_status) {
            $query->whereHas('payout', fn ($p) => $p->where('status', $request->payout_status));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'customer_id' => 'required|exists:users,id',
            'company_id' => 'nullable|exists:companies,id',
            'service_category' => 'required|in:drywall_paint,insulation',
            'address' => 'required|string',
            'job_title' => 'nullable|string',
            'scope_of_work' => 'nullable|string',
            'pm_id' => 'nullable|exists:users,id',
        ]);

        $data['status'] = 'new_job';
        if ($request->user()->role === 'pm') {
            $data['pm_id'] = $request->user()->id;
        }

        $job = Job::create($data);
        $this->pricing->seedSplitOntoJob($job);
        $this->notifications->audit('job_created', 'job', $job->id);

        return response()->json($job->load(['customer:id,name', 'pm:id,name']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $job = Job::with([
            'lead:id,contact_name,phone,email,address',
            'customer:id,name,email',
            'contractor:id,name,email',
            'pm:id,name,email',
            'company:id,name',
            'quote.items',
            'invoice',
            'payout',
            'updates' => fn ($q) => $q->latest(),
            'updates.photos',
            'updates.postedBy:id,name,role',
        ])->findOrFail($id);

        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }
        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }

        if ($user->role === 'customer') {
            $job->setRelation('updates', $job->updates->where('visibility', 'customer_visible')->values());
        }

        return response()->json($job);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::findOrFail($id);

        if ($request->user()->role === 'pm' && $job->pm_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'job_title' => 'nullable|string',
            'scope_of_work' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
            'contractor_submitted_price' => 'nullable|numeric|min:0',
        ]);

        $job->update($data);

        return response()->json($job->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Not allowed'], 403);
    }

    public function assignPm(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['pm_id' => 'required|exists:users,id']);
        $pm = User::where('id', $request->pm_id)->where('role', 'pm')->firstOrFail();
        $job = Job::findOrFail($id);
        $job->update(['pm_id' => $pm->id]);

        $this->notifications->audit('pm_assigned', 'job', $job->id, null, null, ['pm_id' => $pm->id]);

        return response()->json(['message' => 'PM assigned', 'pm' => $pm->only('id', 'name', 'email')]);
    }

    public function assignContractor(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['contractor_id' => 'required|exists:users,id']);
        $contractor = User::where('id', $request->contractor_id)->where('role', 'contractor')->firstOrFail();
        $job = Job::findOrFail($id);

        if ($request->user()->role === 'pm' && $job->pm_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job->update([
            'contractor_id' => $contractor->id,
            'status' => 'contractor_assigned',
            'contractor_price_status' => 'pending',
        ]);

        $this->notifications->contractorAssigned($job->fresh(), $contractor);

        return response()->json(['message' => 'Contractor assigned']);
    }

    public function schedule(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'scheduled_start_date' => 'required|date',
            'scheduled_start_time' => 'nullable|date_format:H:i',
            'estimated_completion_date' => 'required|date|after_or_equal:scheduled_start_date',
            'schedule_notes' => 'nullable|string',
        ], [
            'scheduled_start_date.required' => 'Please select a start date.',
            'estimated_completion_date.after_or_equal' => 'Completion date must be after the start date.',
        ]);

        $job = Job::findOrFail($id);
        $wasScheduled = $job->status === 'scheduled';

        $job->update([
            ...$data,
            'scheduled_end_date' => $data['estimated_completion_date'],
            'status' => 'scheduled',
        ]);

        $this->notifications->jobScheduled($job->fresh(), $wasScheduled);

        return response()->json(['message' => 'Job scheduled', 'job' => $job->fresh()]);
    }

    public function submitPrice(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate(['price' => 'required|numeric|min:0']);
        $job = Job::findOrFail($id);

        if ($job->contractor_id !== $user->id) {
            abort(403, 'You do not have permission to view this job.');
        }

        $job->update([
            'contractor_submitted_price' => $data['price'],
            'contractor_price_status' => 'submitted',
            'contractor_price_submitted_at' => now(),
        ]);

        $this->notifications->priceSubmitted($job->fresh());

        return response()->json(['message' => 'Price submitted', 'job' => $job->fresh()]);
    }

    public function approvePrice(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job = Job::findOrFail($id);
        $job->update(['contractor_price_status' => 'approved']);

        return response()->json(['message' => 'Contractor price approved']);
    }

    public function updateSplit(Request $request, Job $job): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'split_contractor_pct' => 'required|numeric|min:1|max:99',
            'split_pm_pct' => 'required|numeric|min:0|max:99',
            'split_company_pct' => 'required|numeric|min:0|max:99',
        ]);

        $total = $request->split_contractor_pct + $request->split_pm_pct + $request->split_company_pct;
        if (abs($total - 100) > 0.01) {
            return response()->json(['message' => "Split percentages must add up to 100. Current total: {$total}"], 422);
        }

        $job->update($request->only(['split_contractor_pct', 'split_pm_pct', 'split_company_pct']));

        return response()->json(['message' => 'Split updated', 'job' => $job->fresh()]);
    }

    public function contractorComplete(Request $request, Job $job): JsonResponse
    {
        if (auth()->id() !== $job->contractor_id) {
            abort(403);
        }

        if (! in_array($job->status, ['in_progress', 'progress_updated', 'revision_requested', 'corrections_required'], true)) {
            return response()->json(['message' => 'Job must be in progress to mark complete'], 422);
        }

        $job->update([
            'status' => 'pending_customer_approval',
            'pending_customer_approval_at' => now(),
        ]);

        $job->load('lead', 'customer');
        $portalUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/portal/'.($job->lead?->customer_portal_token ?? '');

        $this->sms->sendToUser(
            $job->customer,
            "Hi {$job->customer?->name}, your project is complete and ready for your review. Please accept or request changes here: {$portalUrl}",
            'contractor_marked_complete',
            $job->customer_id,
            $job->id
        );

        if ($job->customer?->email) {
            $this->email->sendMailable(
                $job->customer->email,
                new JobReadyForReviewMail($job, $portalUrl),
                'contractor_marked_complete',
                $job->customer_id,
                $job->id
            );
        }

        if ($job->pm) {
            $this->sms->sendToUser(
                $job->pm,
                "Contractor marked job at {$job->address} complete — awaiting customer approval.",
                'contractor_marked_complete',
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => 'contractor',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'contractor_marked_complete',
        ]);

        return response()->json(['message' => 'Job marked complete, customer notified for review']);
    }

    public function acceptCompletion(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        if ($job->status !== 'pending_customer_approval') {
            return response()->json(['message' => 'Job is not awaiting customer approval'], 422);
        }

        $job->update([
            'status' => 'payment_pending',
            'customer_accepted_completion_at' => now(),
        ]);

        $this->ensureInvoiceForJob($job);

        $paymentUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/payment/'.$job->id;

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'customer_accepted_completion',
        ]);

        return response()->json([
            'message' => 'Completion accepted',
            'payment_url' => $paymentUrl,
        ]);
    }

    public function requestRevision(Request $request, Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $request->validate(['description' => 'required|string|max:2000']);

        $revision = RevisionRequest::create([
            'job_id' => $job->id,
            'requested_by' => auth()->id(),
            'description' => $request->description,
            'status' => 'open',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('revision-requests/'.$job->id, 'public');
                RevisionRequestPhoto::create([
                    'revision_request_id' => $revision->id,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_url' => Storage::disk('public')->url($path),
                ]);
            }
        }

        $job->update([
            'status' => 'revision_requested',
            'revision_description' => $request->description,
        ]);

        $contractorPortalUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/dashboard/contractor';
        $this->sms->sendToUser(
            $job->contractor,
            "Revision requested for your job at {$job->address}. Please review and address the client's feedback: {$contractorPortalUrl}",
            'revision_requested',
            $job->contractor_id,
            $job->id
        );

        if ($job->contractor?->email) {
            $this->email->sendMailable(
                $job->contractor->email,
                new RevisionRequestedMail($job->fresh(), $request->description, $contractorPortalUrl),
                'revision_requested',
                $job->contractor_id,
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'revision_requested',
            'new_value' => json_encode(['description' => $request->description]),
        ]);

        return response()->json(['message' => 'Revision request submitted. Contractor and PM have been notified.']);
    }

    public function notifyEtransferSent(Request $request, Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($job->customer_id !== $user->id && $user->role !== 'owner') {
            abort(403);
        }

        $job->update(['status' => 'etransfer_pending_confirmation', 'payment_method' => 'e_transfer']);

        $admin = User::where('role', 'owner')->first();
        if ($admin) {
            $this->sms->sendToUser(
                $admin,
                "Customer notified e-transfer sent for job at {$job->address}. Please confirm payment.",
                'etransfer_pending',
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => $user->role,
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'etransfer_notified',
        ]);

        return response()->json(['message' => 'Thank you. The team has been notified to confirm your payment.']);
    }

    /**
     * STRIPE INTEGRATION POINT
     * When Stripe is added:
     * 1. Create Stripe PaymentIntent via POST /api/jobs/{job}/create-payment-intent
     * 2. Return client_secret to frontend
     * 3. Mount Stripe Elements in the #stripe-payment-element div
     * 4. On payment success, Stripe webhook calls POST /api/webhooks/stripe
     * 5. Webhook verifies signature, updates job status to paid_completed
     */
    public function confirmPayment(Request $request, Job $job): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'payment_reference' => 'nullable|string',
            'payment_date' => 'required|date',
        ]);

        $this->ensureInvoiceForJob($job);
        $job->refresh();

        $job->update([
            'status' => 'paid_completed',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by' => auth()->id(),
            'payment_reference' => $request->payment_reference,
            'payment_method' => 'e_transfer',
            'completed_at' => now(),
        ]);

        if ($job->invoice) {
            $job->invoice->update(['status' => 'paid', 'balance' => 0]);
            Payment::create([
                'invoice_id' => $job->invoice->id,
                'amount' => $job->invoice->amount,
                'method' => 'e_transfer',
                'paid_status' => true,
                'cleared_status' => true,
                'marked_by' => auth()->id(),
                'paid_date' => $request->payment_date,
                'reference_number' => $request->payment_reference,
            ]);
        }

        $this->payouts->markPayoutsReady($job);

        $portalUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/portal/'.($job->lead?->customer_portal_token ?? '');
        if ($job->customer?->email) {
            $this->email->sendMailable(
                $job->customer->email,
                new PaymentConfirmedMail($job->fresh(), $portalUrl),
                'payment_confirmed',
                $job->customer_id,
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => auth()->id(),
            'user_role' => 'owner',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'payment_confirmed',
        ]);

        return response()->json(['message' => 'Payment confirmed. Job marked as Paid/Completed.']);
    }

    public function paymentDetails(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $job->load(['quote', 'invoice', 'lead']);

        return response()->json([
            'job' => [
                'id' => $job->id,
                'address' => $job->address,
                'scope_of_work' => $job->scope_of_work,
                'status' => $job->status,
            ],
            'invoice' => $job->invoice ? [
                'amount' => $job->invoice->amount,
                'gst' => $job->invoice->gst,
                'subtotal' => $job->invoice->subtotal ?? $job->quote?->customer_price_before_gst,
                'balance' => $job->invoice->balance,
                'status' => $job->invoice->status,
            ] : ($job->quote ? [
                'amount' => $job->quote->customer_total,
                'gst' => $job->quote->gst,
                'subtotal' => $job->quote->customer_price_before_gst,
                'balance' => $job->quote->customer_total,
                'status' => 'awaiting_payment',
            ] : null),
            'company_email' => Setting::where('key', 'company_email')->value('value') ?? 'payments@hsop.ca',
            'payment_instructions' => Setting::where('key', 'payment_instructions')->value('value'),
        ]);
    }

    protected function ensureInvoiceForJob(Job $job): void
    {
        if ($job->invoice) {
            return;
        }

        $quote = $job->quote;
        if (! $quote) {
            return;
        }

        Invoice::create([
            'job_id' => $job->id,
            'quote_id' => $quote->id,
            'company_id' => $quote->company_id,
            'customer_id' => $quote->customer_id,
            'invoice_number' => 'INV-'.str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT),
            'scope_of_work' => $quote->scope_of_work,
            'subtotal' => $quote->subtotal ?? $quote->customer_price_before_gst,
            'gst' => $quote->gst,
            'gst_rate' => $quote->gst_rate,
            'balance' => $quote->customer_total,
            'amount' => $quote->customer_total,
            'status' => 'awaiting_payment',
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function markReadyForReview(Job $job): JsonResponse
    {
        if (auth()->id() !== $job->contractor_id) {
            abort(403, 'Only the assigned contractor can mark this job ready for review.');
        }

        $job->update(['status' => 'ready_for_review', 'ready_for_review_at' => now()]);
        $this->notifications->audit('marked_ready_for_review', 'job', $job->id);
        $this->notifications->readyForReview($job->fresh());

        return response()->json(['message' => 'Job marked ready for review']);
    }

    public function markComplete(Job $job): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $job->update(['status' => 'completed', 'completed_at' => now()]);
        $this->notifications->audit('marked_complete', 'job', $job->id);
        $this->notifications->jobComplete($job->fresh());
        $this->payouts->syncPayoutReadiness($job->fresh(['invoice']));

        return response()->json(['message' => 'Job marked complete']);
    }

    public function requestCorrections(Request $request, Job $job): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['corrections_notes' => 'required|string|max:2000']);
        $job->update([
            'status' => 'corrections_required',
            'corrections_notes' => $request->corrections_notes,
        ]);

        $this->notifications->audit('corrections_requested', 'job', $job->id, null, null, ['notes' => $request->corrections_notes]);
        $this->notifications->correctionsRequested($job->fresh());

        return response()->json(['message' => 'Corrections requested']);
    }

    public function activityLog(Job $job): JsonResponse
    {
        $user = auth()->user();
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            abort(403);
        }
        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            abort(403);
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            abort(403);
        }

        $quoteIds = Quote::where('job_id', $job->id)->pluck('id')->all();

        $logs = AuditLog::where(function ($q) use ($job, $quoteIds) {
            $q->where(fn ($qq) => $qq->where('object_type', 'job')->where('object_id', $job->id));
            if ($quoteIds) {
                $q->orWhere(fn ($qq) => $qq->where('object_type', 'quote')->whereIn('object_id', $quoteIds));
            }
        })
            ->with('user:id,name,role')
            ->latest()
            ->get();

        return response()->json($logs);
    }
}
