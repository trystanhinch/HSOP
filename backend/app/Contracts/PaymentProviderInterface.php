<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Payout;

interface PaymentProviderInterface
{
    /**
     * Create a customer-facing payment link/session for an invoice.
     *
     * @return array{provider: string, payment_link: string, provider_reference: string|null}
     */
    public function createPaymentLink(Invoice $invoice, array $options = []): array;

    /**
     * Handle a provider webhook / mock payment confirmation payload.
     *
     * @return array{handled: bool, invoice_id: int|null, status: string|null}
     */
    public function handleWebhook(array $payload): array;

    /**
     * Create (or stub) a connected account for a contractor/PM.
     *
     * @return array{provider: string, account_id: string}
     */
    public function createConnectedAccount(array $accountData): array;

    /**
     * Execute (or stub) a transfer/payout to a connected account.
     *
     * @return array{provider: string, transfer_id: string, status: string}
     */
    public function createTransfer(Payout $payout): array;
}
