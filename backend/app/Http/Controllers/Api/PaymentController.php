<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\InvoicePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected InvoicePaymentService $payments) {}

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.max((float) $invoice->balance, 0.01),
            'reference_number' => 'nullable|string|max:100',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:50',
        ], [
            'amount.required' => 'Please enter the payment amount.',
            'payment_date.required' => 'Please select a payment date.',
        ]);

        $invoice = $this->payments->markPaid($invoice, [
            'amount' => $request->amount,
            'payment_date' => $request->payment_date,
            'reference_number' => $request->reference_number,
            'payment_method' => $request->payment_method ?? 'e_transfer',
        ]);

        return response()->json([
            'message' => 'Payment recorded',
            'invoice' => $invoice,
            'payment' => Payment::where('invoice_id', $invoice->id)->latest()->first(),
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
