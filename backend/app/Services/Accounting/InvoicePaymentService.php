<?php

namespace App\Services\Accounting;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\JobNotificationService;
use App\Services\PayoutEligibilityService;
use Illuminate\Support\Facades\DB;

class InvoicePaymentService
{
    public function __construct(
        private PayoutEligibilityService $eligibility,
        private JobNotificationService $notifications,
    ) {}

    /**
     * Record a full/partial payment and refresh payout eligibility when fully paid.
     */
    public function markPaid(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $amount = round((float) ($data['amount'] ?? $invoice->balance ?? $invoice->amount), 2);
            $method = $data['payment_method'] ?? 'e_transfer';
            $txn = $data['stripe_transaction_id'] ?? null;
            $paidDate = $data['payment_date'] ?? now()->toDateString();

            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $method,
                'paid_status' => true,
                'cleared_status' => true,
                'marked_by' => auth()->id(),
                'paid_date' => $paidDate,
                'reference_number' => $data['reference_number'] ?? $txn,
                'status' => 'completed',
            ]);

            $amountPaid = round((float) $invoice->amount_paid + $amount, 2);
            $balance = round(max(0, (float) $invoice->amount - $amountPaid), 2);
            $fullyPaid = $balance <= 0.009;

            $invoice->update([
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'status' => $fullyPaid ? 'paid' : 'partially_paid',
                'payment_date' => $fullyPaid ? $paidDate : $invoice->payment_date,
                'payment_method' => $method,
                'stripe_transaction_id' => $txn ?? $invoice->stripe_transaction_id,
            ]);

            $invoice = $invoice->fresh(['job']);

            $exGst = 0.0;
            if ((float) $invoice->amount > 0) {
                $exGst = round((float) $invoice->subtotal * ($amount / (float) $invoice->amount), 2);
            }
            $payment = Payment::where('invoice_id', $invoice->id)->latest('id')->first();
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            if (! empty($data['ledger_note'])) {
                $meta['note'] = $data['ledger_note'];
            }
            if (($method === 'e_transfer' || $method === 'etransfer') && empty($meta['note']) && auth()->user()) {
                $meta['note'] = 'e-transfer, manually confirmed by '.auth()->user()->name.' (user #'.auth()->id().')';
            }
            app(\App\Services\Finance\FinancialLedgerService::class)->recordEntry([
                'entry_type' => $fullyPaid
                    ? \App\Models\FinancialLedgerEntry::TYPE_PAYMENT_RECEIVED
                    : \App\Models\FinancialLedgerEntry::TYPE_PAYMENT_PARTIAL,
                'direction' => 'credit',
                'amount' => $exGst,
                'gst_amount' => round($amount - $exGst, 2),
                'job_id' => $invoice->job_id,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment?->id,
                'actor_user_id' => auth()->id(),
                'reference' => $data['reference_number'] ?? $txn,
                'meta' => $meta ?: null,
                'is_test_data' => (bool) ($invoice->is_test_data ?? false),
            ]);

            if ($fullyPaid && $invoice->job) {
                $invoice->job->update(['status' => 'paid']);
                $this->eligibility->evaluateForJob($invoice->job->fresh([
                    'invoice', 'quote', 'revisionRequests', 'contractor', 'pm', 'lead.companySource',
                ]));
            }

            $this->notifications->audit('payment_status_changed', 'invoice', $invoice->id, null, null, [
                'status' => $invoice->status,
                'amount_paid' => $amountPaid,
            ]);

            return $invoice;
        });
    }
}
