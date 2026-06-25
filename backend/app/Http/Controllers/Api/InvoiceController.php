<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Services\JobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(protected JobNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Invoice::with(['job:id,address', 'customer:id,name']);

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
            'amount' => 'required|numeric|min:0',
            'gst' => 'nullable|numeric',
            'due_date' => 'nullable|date',
        ]);

        $invoice = Invoice::create([
            ...$data,
            'invoice_number' => 'INV-'.str_pad(Invoice::count() + 1, 4, '0', STR_PAD_LEFT),
            'balance' => ($data['amount'] ?? 0) + ($data['gst'] ?? 0),
            'status' => 'draft',
            'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
        ]);

        return response()->json($invoice, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['job', 'customer:id,name', 'quote'])->findOrFail($id);
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
            'status' => 'sometimes|in:draft,invoice_sent,awaiting_payment,sent,partially_paid,paid,overdue,cancelled',
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

        $invoice = Invoice::create([
            'job_id' => $quote->job_id,
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

        $quote->job->update(['status' => 'invoiced']);

        $this->notifications->audit('invoice_created', 'invoice', $invoice->id);

        return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
    }

    public function send(Invoice $invoice): JsonResponse
    {
        if (! in_array(auth()->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $invoice->update(['status' => 'invoice_sent', 'sent_at' => now()]);
        $this->notifications->invoiceSent($invoice->fresh());

        return response()->json(['message' => 'Invoice sent', 'invoice' => $invoice->fresh()]);
    }
}
