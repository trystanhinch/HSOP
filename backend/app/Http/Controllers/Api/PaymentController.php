<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\JobNotificationService;
use App\Services\PayoutWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PayoutWorkflowService $payouts,
        protected JobNotificationService $notifications
    ) {}

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.$invoice->balance,
            'reference_number' => 'nullable|string|max:100',
            'payment_date' => 'required|date',
        ], [
            'amount.required' => 'Please enter the payment amount.',
            'payment_date.required' => 'Please select a payment date.',
        ]);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $request->amount,
            'method' => 'e_transfer',
            'paid_status' => true,
            'cleared_status' => true,
            'marked_by' => auth()->id(),
            'paid_date' => $request->payment_date,
            'reference_number' => $request->reference_number,
        ]);

        $newBalance = round($invoice->balance - $request->amount, 2);

        $invoice->update([
            'balance' => max($newBalance, 0),
            'status' => $newBalance <= 0 ? 'paid' : 'partially_paid',
        ]);

        if ($invoice->status === 'paid') {
            $this->payouts->onInvoicePaid($invoice->fresh());
            $invoice->job?->update(['status' => 'paid']);
        }

        $this->notifications->audit('payment_status_changed', 'invoice', $invoice->id, null, null, [
            'status' => $invoice->status,
            'payment_id' => $payment->id,
        ]);

        return response()->json([
            'message' => 'Payment recorded',
            'invoice' => $invoice->fresh(),
            'payment' => $payment,
        ], 201);
    }

    public function history(Invoice $invoice): JsonResponse
    {
        return response()->json(
            Payment::where('invoice_id', $invoice->id)
                ->with('markedBy:id,name')
                ->latest()
                ->get()
        );
    }
}
