<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Services\Accounting\InvoicePdfService;
use App\Services\Accounting\InvoiceService;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Invoice::with(['job:id,address,service_category', 'customer:id,name']);

        if ($user->role === 'customer') {
            $query->where('customer_id', $user->id);
        } elseif (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoices = $query->latest()->paginate(20);
        $invoices->getCollection()->transform(function ($invoice) {
            $invoice->is_overdue = $invoice->is_overdue;

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
        $invoice->is_overdue = $invoice->is_overdue;

        return response()->json($invoice);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoice = Invoice::findOrFail($id);
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

        $link = $this->payments->createPaymentLink($invoice);
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

    public function pdf(Invoice $invoice): Response
    {
        $user = auth()->user();
        if ($user->role === 'customer' && (int) $invoice->customer_id !== (int) $user->id) {
            abort(403);
        }
        if (! in_array($user->role, ['owner', 'pm', 'customer'], true)) {
            abort(403);
        }

        $binary = $this->pdf->pdfBinary($invoice);
        $filename = ($invoice->invoice_number ?: 'invoice-'.$invoice->id).'.pdf';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
