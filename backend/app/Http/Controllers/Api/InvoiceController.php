<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Services\Accounting\InvoicePdfService;
use App\Services\Accounting\InvoiceService;
use App\Services\Authorization\PmAuthorizationService;
use App\Services\JobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class InvoiceController extends Controller
{
    public function __construct(
        protected JobNotificationService $notifications,
        protected InvoiceService $invoices,
        protected InvoicePdfService $pdf,
        protected PaymentProviderInterface $payments,
        protected PmAuthorizationService $authz,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Invoice::with([
            'job:id,address,service_category,pm_id',
            'customer:id,name,email,phone',
        ]);

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif ($user->role === 'pm') {
            $this->authz->scopeInvoicesForPm($query, $user);
        } elseif ($user->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = $query->latest()->paginate(20);
        $invoices->getCollection()->transform(function ($invoice) {
            $invoice->is_overdue = $invoice->is_overdue;
            $jobId = $invoice->job_id;
            $lastReminder = $jobId
                ? \App\Models\EmailLog::query()
                    ->where('related_job_id', $jobId)
                    ->whereIn('trigger_event', ['invoice_sent', 'invoice_reminder', 'payment_reminder'])
                    ->latest('id')
                    ->first(['id', 'trigger_event', 'created_at', 'status'])
                : null;
            $invoice->setAttribute('issued_at', $invoice->created_at);
            $invoice->setAttribute('payment_state', $this->paymentStateLabel($invoice));
            $invoice->setAttribute('last_reminder_at', $lastReminder?->created_at);
            $invoice->setAttribute('last_reminder_event', $lastReminder?->trigger_event);
            $invoice->setAttribute('customer_phone', $invoice->customer?->phone);
            $invoice->setAttribute('customer_email', $invoice->customer?->email);
            $invoice->setAttribute('can_void', false);
            $invoice->setAttribute('can_refund', false);

            return $invoice;
        });

        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'job_id' => 'required|exists:jobs,id',
        ]);

        $job = Job::with(['quote', 'lead.companySource', 'invoice'])->findOrFail($data['job_id']);
        $this->authz->assertJobAccess($request->user(), $job);
        if ($job->invoice) {
            return response()->json(['message' => 'Invoice already exists for this job', 'invoice' => $job->invoice], 422);
        }

        $invoice = $this->invoices->createFromJob($job);
        $this->notifications->audit('invoice_created', 'invoice', $invoice->id);

        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['job', 'customer:id,name', 'quote', 'companySource'])->findOrFail($id);
        $user = $request->user();
        if ($user->role === 'customer' && (int) $invoice->customer_id !== (int) $user->id) {
            abort(403);
        }
        $this->authz->assertInvoiceAccess($user, $invoice);
        $invoice->is_overdue = $invoice->is_overdue;

        return response()->json($invoice);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoice = Invoice::findOrFail($id);
        $this->authz->assertInvoiceAccess($request->user(), $invoice);
        $data = $request->validate([
            'status' => 'sometimes|in:draft,invoice_sent,awaiting_payment,payment_pending,payment_failed,sent,partially_paid,paid,refunded,disputed,overdue,cancelled',
            'notes' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);
        $invoice->update($data);

        return response()->json($invoice->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Not allowed'], 403);
    }

    public function fromQuote(string $quoteId): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $quote = Quote::with('job.invoice')->findOrFail($quoteId);
        $this->authz->assertQuoteAccess(auth()->user(), $quote);

        if ($quote->status !== 'approved') {
            return response()->json(['message' => 'Only approved quotes can be converted to invoices'], 422);
        }

        if ($quote->job->invoice) {
            return response()->json(['message' => 'Invoice already exists for this job'], 422);
        }

        $invoice = $this->invoices->createFromQuote($quote);
        $quote->job->update(['status' => 'invoiced']);
        $this->notifications->audit('invoice_created', 'invoice', $invoice->id);

        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function fromJob(Job $job): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->authz->assertJobAccess(auth()->user(), $job);

        $job->loadMissing('invoice');
        if ($job->invoice) {
            return response()->json(['message' => 'Invoice already exists', 'invoice' => $job->invoice], 422);
        }

        $invoice = $this->invoices->createFromJob($job);
        $this->notifications->audit('invoice_created', 'invoice', $invoice->id);

        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function send(Invoice $invoice): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->authz->assertInvoiceAccess(auth()->user(), $invoice);

        $linkPayload = $this->payments->createPaymentLink($invoice);
        $link = is_array($linkPayload) ? ($linkPayload['payment_link'] ?? null) : $linkPayload;
        $invoice->update([
            'status' => 'invoice_sent',
            'sent_at' => now(),
        ]);
        $this->notifications->invoiceSent($invoice->fresh());

        return response()->json([
            'message' => 'Invoice sent',
            'invoice' => $invoice->fresh(),
            'payment_link' => $link,
        ]);
    }

    /**
     * PM-14 — generate/return payment link without changing financial status beyond send semantics.
     * Void/refund stay owner-only (not exposed here).
     */
    public function paymentLink(Invoice $invoice): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertInvoiceAccess(auth()->user(), $invoice);

        $linkPayload = $this->payments->createPaymentLink($invoice);
        $link = is_array($linkPayload) ? ($linkPayload['payment_link'] ?? null) : $linkPayload;

        return response()->json([
            'payment_link' => $link,
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
        ]);
    }

    /**
     * PM-14 — light follow-up audit when PM records customer contact about an invoice.
     */
    public function recordContact(Request $request, Invoice $invoice): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $this->authz->assertInvoiceAccess(auth()->user(), $invoice);

        $data = $request->validate([
            'note' => 'nullable|string|max:500',
            'channel' => 'nullable|in:call,sms,email,other',
        ]);

        $this->notifications->audit('invoice_customer_contacted', 'invoice', $invoice->id, null, null, [
            'note' => $data['note'] ?? null,
            'channel' => $data['channel'] ?? 'other',
            'by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Contact recorded']);
    }

    public function pdf(Invoice $invoice): Response
    {
        $user = auth()->user();
        if ($user->role === 'customer' && (int) $invoice->customer_id !== (int) $user->id) {
            abort(403);
        }
        if (! in_array($user->role, ['owner', 'pm', 'customer'], true)) {
            abort(403);
        }
        $this->authz->assertInvoiceAccess($user, $invoice);

        $binary = $this->pdf->pdfBinary($invoice);
        $filename = ($invoice->invoice_number ?: 'invoice-'.$invoice->id).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function paymentStateLabel(Invoice $invoice): string
    {
        if (in_array($invoice->status, ['paid', 'refunded'], true)) {
            return $invoice->status;
        }
        if ((float) $invoice->balance <= 0) {
            return 'paid';
        }
        if ($invoice->is_overdue) {
            return 'overdue';
        }
        if ($invoice->sent_at || in_array($invoice->status, ['invoice_sent', 'sent', 'awaiting_payment'], true)) {
            return 'awaiting_payment';
        }
        if (in_array($invoice->status, ['partially_paid'], true)) {
            return 'partially_paid';
        }

        return $invoice->status ?: 'draft';
    }
}
