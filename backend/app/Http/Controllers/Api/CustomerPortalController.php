<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CompletionAcceptedMail;
use App\Mail\JobReadyForReviewMail;
use App\Mail\RevisionRequestedMail;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\RevisionRequest;
use App\Models\RevisionRequestPhoto;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmailService;
use App\Services\JobNotificationService;
use App\Services\LeadQuoteWorkflowService;
use App\Services\PayoutWorkflowService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email,
        protected JobNotificationService $notifications,
        protected PayoutWorkflowService $payouts,
        protected LeadQuoteWorkflowService $leadQuotes,
        protected UploadStorage $uploads,
    ) {}

    protected function leadFromToken(string $token): Lead
    {
        $lead = Lead::where('customer_portal_token', $token)->first();

        if (! $lead) {
            abort(404, 'This link is invalid or has expired.');
        }

        return $lead;
    }

    protected function frontendUrl(string $path = ''): string
    {
        return rtrim(config('app.frontend_url', 'http://localhost:5173'), '/').'/'.ltrim($path, '/');
    }

    public function show(string $token): JsonResponse
    {
        $lead = $this->leadFromToken($token);
        $lead->load('assignedPm:id,name,email,phone');
        $job = Job::with(['quote', 'invoice', 'updates.photos', 'updates.postedBy:id,name', 'pm:id,name,email,phone'])
            ->where('lead_id', $lead->id)
            ->first();

        $leadQuote = Quote::leadLevelFor($lead);
        $activeQuote = $job?->quote ?? $leadQuote;

        return response()->json([
            'lead' => [
                'contact_name' => $lead->contact_name,
                'address' => $lead->address,
                'service_category' => $lead->service_category,
                'status' => $lead->status,
                'site_visit_date' => $lead->site_visit_date,
                'site_visit_time' => $lead->site_visit_time,
            ],
            'quote' => $activeQuote ? [
                'quote_number' => $activeQuote->quote_number,
                'scope_of_work' => $activeQuote->scope_of_work,
                'customer_notes' => $activeQuote->customer_notes,
                'customer_price_before_gst' => $activeQuote->customer_price_before_gst,
                'gst' => $activeQuote->gst,
                'gst_rate' => $activeQuote->gst_rate,
                'customer_total' => $activeQuote->customer_total,
                'status' => $activeQuote->status,
                'sent_at' => $activeQuote->sent_at,
                'accepted_at' => $activeQuote->accepted_at,
            ] : null,
            'pm' => ($job?->pm ?? $lead->assignedPm) ? [
                'name' => ($job?->pm ?? $lead->assignedPm)->name,
                'email' => ($job?->pm ?? $lead->assignedPm)->email,
                'phone' => ($job?->pm ?? $lead->assignedPm)->phone,
            ] : null,
            'job' => $job ? [
                'id' => $job->id,
                'status' => $job->status,
                'scheduled_start_date' => $job->scheduled_start_date,
                'scheduled_start_time' => $job->scheduled_start_time,
                'estimated_completion' => $job->estimated_completion_date,
                'revision_description' => $job->revision_description,
            ] : null,
            'updates' => $job ? $job->updates()
                ->where('visibility', 'customer_visible')
                ->with('photos')
                ->latest()
                ->get()
                ->map(fn ($u) => [
                    'text' => $u->update_text,
                    'created_at' => $u->created_at,
                    'posted_by' => $u->postedBy?->name,
                    'photos' => $u->photos->pluck('file_url')->filter()->values(),
                ]) : [],
            'invoice' => $job?->invoice ? [
                'amount' => $job->invoice->amount,
                'gst' => $job->invoice->gst,
                'balance' => $job->invoice->balance,
                'status' => $job->invoice->status,
            ] : null,
            'payment' => [
                'company_email' => Setting::where('key', 'company_email')->value('value') ?? 'payments@hsop.ca',
                'instructions' => Setting::where('key', 'payment_instructions')->value('value'),
            ],
            'token' => $token,
        ]);
    }

    public function acceptQuote(string $token): JsonResponse
    {
        $lead = $this->leadFromToken($token);
        $quote = $this->leadQuotes->findQuoteForLead($lead);

        if (! $quote) {
            return response()->json(['message' => 'No quote found for this lead.'], 404);
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

    public function rejectQuote(Request $request, string $token): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $lead = $this->leadFromToken($token);
        $quote = $this->leadQuotes->findQuoteForLead($lead);

        if (! $quote) {
            return response()->json(['message' => 'No quote found for this lead.'], 404);
        }

        try {
            $quote = $this->leadQuotes->rejectQuote($quote, $request->rejection_reason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }
        $this->notifications->quoteRejected($quote);

        return response()->json(['message' => 'Quote rejected. The team has been notified.']);
    }

    public function acceptCompletion(string $token): JsonResponse
    {
        $lead = $this->leadFromToken($token);
        $job = Job::where('lead_id', $lead->id)->firstOrFail();

        if ($job->status !== 'pending_customer_approval') {
            return response()->json(['message' => 'Job is not awaiting customer approval'], 422);
        }

        $job->update([
            'status' => 'payment_pending',
            'customer_accepted_completion_at' => now(),
        ]);

        $this->ensureInvoice($job);

        $paymentUrl = $this->frontendUrl('payment/'.$job->id);
        $customer = User::find($job->customer_id);

        if ($customer) {
            $this->email->sendMailable(
                $customer->email,
                new CompletionAcceptedMail($job->fresh(), $paymentUrl),
                'customer_accepted_completion',
                $customer->id,
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => $job->customer_id,
            'user_role' => 'customer',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'customer_accepted_completion',
        ]);

        return response()->json([
            'message' => 'Completion accepted',
            'payment_url' => $paymentUrl,
        ]);
    }

    public function requestRevision(Request $request, string $token): JsonResponse
    {
        $request->validate(['description' => 'required|string|max:2000']);
        $lead = $this->leadFromToken($token);
        $job = Job::with(['contractor', 'pm', 'lead'])->where('lead_id', $lead->id)->firstOrFail();

        $revision = RevisionRequest::create([
            'job_id' => $job->id,
            'requested_by' => $job->customer_id,
            'description' => $request->description,
            'status' => 'open',
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $this->uploads->store($photo, 'revision-requests/'.$job->id);
                RevisionRequestPhoto::create([
                    'revision_request_id' => $revision->id,
                    'file_name' => $photo->getClientOriginalName(),
                    'file_url' => $this->uploads->publicUrl($path),
                ]);
            }
        }

        $job->update([
            'status' => 'revision_requested',
            'revision_description' => $request->description,
        ]);

        $contractorPortalUrl = SmsMessageTemplates::contractorDashboardUrl();
        $job->loadMissing('contractor');
        $this->sms->send(
            SmsService::phoneForUser($job->contractor),
            SmsMessageTemplates::revisionRequested($job->contractor, $job, $contractorPortalUrl),
            'revision_requested',
            $job->contractor_id,
            $job->id
        );

        if ($job->contractor?->email) {
            $this->email->sendMailable(
                $job->contractor->email,
                new RevisionRequestedMail($job, $request->description, $contractorPortalUrl),
                'revision_requested',
                $job->contractor_id,
                $job->id
            );
        }

        if ($job->pm) {
            $this->sms->sendToUser(
                $job->pm,
                "Revision requested for job at {$job->address}.",
                'revision_requested',
                $job->id
            );
        }

        AuditLog::create([
            'user_id' => $job->customer_id,
            'user_role' => 'customer',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'revision_requested',
            'new_value' => json_encode(['description' => $request->description]),
        ]);

        return response()->json(['message' => 'Revision request submitted. Contractor and PM have been notified.']);
    }

    public function notifyPayment(string $token): JsonResponse
    {
        $lead = $this->leadFromToken($token);
        $job = Job::where('lead_id', $lead->id)->firstOrFail();

        if (! in_array($job->status, ['payment_pending', 'etransfer_pending_confirmation'], true)) {
            return response()->json(['message' => 'Job is not awaiting payment'], 422);
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
            'user_id' => $job->customer_id,
            'user_role' => 'customer',
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'etransfer_notified',
        ]);

        return response()->json(['message' => 'Thank you. The team has been notified to confirm your payment.']);
    }

    public function paymentDetails(string $token): JsonResponse
    {
        $lead = $this->leadFromToken($token);
        $job = Job::with(['quote', 'invoice', 'lead'])->where('lead_id', $lead->id)->firstOrFail();

        if (! in_array($job->status, ['payment_pending', 'etransfer_pending_confirmation'], true)) {
            return response()->json(['message' => 'Job is not awaiting payment'], 422);
        }

        $this->ensureInvoice($job);
        $job->refresh();

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
            ] : null,
            'company_email' => Setting::where('key', 'company_email')->value('value') ?? 'payments@hsop.ca',
            'payment_instructions' => Setting::where('key', 'payment_instructions')->value('value'),
            'token' => $token,
        ]);
    }

    protected function ensureInvoice(Job $job): void
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
}
