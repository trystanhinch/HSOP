<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Invoice;
use App\Models\Payout;
use App\Services\Accounting\InvoicePaymentService;
use Illuminate\Support\Str;

class MockPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private InvoicePaymentService $payments) {}

    public function createPaymentLink(Invoice $invoice, array $options = []): array
    {
        $ref = 'mock_plink_'.Str::lower(Str::random(16));

        return [
            'provider' => 'mock',
            'payment_link' => rtrim(config('app.frontend_url', 'http://localhost:5173'), '/')
                .'/payment/mock/'.$invoice->id.'?ref='.$ref,
            'provider_reference' => $ref,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        $invoiceId = (int) ($payload['invoice_id'] ?? 0);
        $event = $payload['event'] ?? 'payment_succeeded';

        if ($invoiceId < 1) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            return ['handled' => false, 'invoice_id' => $invoiceId, 'status' => null];
        }

        if ($event === 'payment_succeeded') {
            $this->payments->markPaid($invoice, [
                'amount' => (float) ($payload['amount'] ?? $invoice->balance ?? $invoice->amount),
                'payment_method' => 'e_transfer',
                'stripe_transaction_id' => $payload['transaction_id'] ?? ('mock_txn_'.Str::lower(Str::random(12))),
                'payment_date' => now()->toDateString(),
            ]);

            return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
        }

        if ($event === 'payment_failed') {
            $invoice->update(['status' => 'payment_failed']);

            return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'payment_failed'];
        }

        return ['handled' => false, 'invoice_id' => $invoice->id, 'status' => $invoice->status];
    }

    public function createConnectedAccount(array $accountData): array
    {
        return [
            'provider' => 'mock',
            'account_id' => 'acct_mock_'.Str::lower(Str::random(14)),
        ];
    }

    public function createTransfer(Payout $payout): array
    {
        $transferId = 'tr_mock_'.Str::lower(Str::random(14));

        $payout->update([
            'stripe_transfer_id' => $transferId,
            'status' => 'in_transit',
        ]);

        // Mock settles immediately for local E2E.
        $payout->update([
            'status' => 'paid',
            'paid_date' => now()->toDateString(),
        ]);

        return [
            'provider' => 'mock',
            'transfer_id' => $transferId,
            'status' => 'paid',
        ];
    }
}
