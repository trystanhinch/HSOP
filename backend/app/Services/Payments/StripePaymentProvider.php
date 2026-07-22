<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\AiActionLog;
use App\Models\Invoice;
use App\Models\Payout;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Accounting\InvoicePaymentService;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Throwable;

class StripePaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private StripeClientFactory $stripeFactory,
        private InvoicePaymentService $payments,
        private SmsService $sms,
        private EmailService $email,
    ) {}

    public function createPaymentLink(Invoice $invoice, array $options = []): array
    {
        $invoice->loadMissing(['customer', 'job.lead']);
        $stripe = $this->stripeFactory->make();

        $amountCents = (int) round(((float) ($invoice->balance ?? $invoice->amount)) * 100);
        if ($amountCents < 50) {
            throw new \RuntimeException('Invoice balance too low for Stripe Checkout (min $0.50).');
        }

        $frontend = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $token = $invoice->job?->lead?->customer_portal_token;
        $successUrl = $options['success_url']
            ?? ($token
                ? "{$frontend}/payment/{$invoice->job_id}?token={$token}&paid=1"
                : "{$frontend}/payment/{$invoice->job_id}?paid=1");
        $cancelUrl = $options['cancel_url']
            ?? ($token
                ? "{$frontend}/payment/{$invoice->job_id}?token={$token}&cancelled=1"
                : "{$frontend}/payment/{$invoice->job_id}?cancelled=1");

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'cad',
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => $invoice->invoice_number ?: ('Invoice #'.$invoice->id),
                        'description' => $invoice->job?->address
                            ? 'ServiceOP work at '.$invoice->job->address
                            : 'ServiceOP invoice payment',
                    ],
                ],
            ]],
            'customer_email' => $invoice->customer?->email,
            'client_reference_id' => (string) $invoice->id,
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'job_id' => (string) ($invoice->job_id ?? ''),
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'invoice_id' => (string) $invoice->id,
                    'job_id' => (string) ($invoice->job_id ?? ''),
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $invoice->update([
            'stripe_checkout_session_id' => $session->id,
            'status' => in_array($invoice->status, ['paid', 'refunded', 'disputed'], true)
                ? $invoice->status
                : 'awaiting_payment',
        ]);

        return [
            'provider' => 'stripe',
            'payment_link' => $session->url,
            'provider_reference' => $session->id,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        $eventId = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $object = $payload['data']['object'] ?? [];

        if (! $eventId || ! $type) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        // Idempotency — never double-process the same Stripe event
        if (StripeWebhookEvent::where('event_id', $eventId)->exists()) {
            return ['handled' => true, 'invoice_id' => null, 'status' => 'duplicate'];
        }

        try {
            $result = match ($type) {
                'checkout.session.completed' => $this->onCheckoutCompleted($object),
                'payment_intent.succeeded' => $this->onPaymentIntentSucceeded($object),
                'payment_intent.payment_failed' => $this->onPaymentIntentFailed($object),
                'charge.refunded', 'charge.dispute.created', 'charge.dispute.funds_withdrawn' => $this->onRefundOrDispute($type, $object),
                'account.updated' => $this->onAccountUpdated($object),
                'transfer.paid', 'transfer.failed', 'transfer.reversed' => $this->onTransferUpdate($type, $object),
                default => ['handled' => false, 'invoice_id' => null, 'status' => 'ignored'],
            };

            StripeWebhookEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'status' => ($result['handled'] ?? false) ? 'processed' : 'ignored',
                'invoice_id' => $result['invoice_id'] ?? null,
                'payload_meta' => [
                    'object_id' => $object['id'] ?? null,
                    'status' => $result['status'] ?? null,
                ],
                'processed_at' => now(),
            ]);

            return $result;
        } catch (Throwable $e) {
            StripeWebhookEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'payload_meta' => ['object_id' => $object['id'] ?? null],
                'processed_at' => now(),
            ]);
            Log::error('Stripe webhook processing failed', [
                'event_id' => $eventId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createConnectedAccount(array $accountData): array
    {
        $stripe = $this->stripeFactory->make();
        $user = $accountData['user'] ?? null;
        if (! $user instanceof User) {
            throw new \InvalidArgumentException('user is required for Connect onboarding');
        }

        if ($user->stripe_account_id) {
            return [
                'provider' => 'stripe',
                'account_id' => $user->stripe_account_id,
            ];
        }

        $account = $stripe->accounts->create([
            'type' => 'express',
            'country' => $accountData['country'] ?? 'CA',
            'email' => $user->email,
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'business_type' => $accountData['business_type'] ?? 'individual',
            'metadata' => [
                'user_id' => (string) $user->id,
                'role' => $user->role,
            ],
        ]);

        $user->update([
            'stripe_account_id' => $account->id,
            'stripe_onboarding_status' => 'pending',
            'stripe_payout_ready' => false,
        ]);

        return [
            'provider' => 'stripe',
            'account_id' => $account->id,
        ];
    }

    public function createAccountOnboardingLink(User $user, ?string $returnUrl = null, ?string $refreshUrl = null): array
    {
        $created = $this->createConnectedAccount(['user' => $user]);
        $stripe = $this->stripeFactory->make();
        $frontend = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

        $link = $stripe->accountLinks->create([
            'account' => $created['account_id'],
            'refresh_url' => $refreshUrl ?: "{$frontend}/stripe/connect/refresh",
            'return_url' => $returnUrl ?: "{$frontend}/stripe/connect/return",
            'type' => 'account_onboarding',
        ]);

        return [
            'provider' => 'stripe',
            'account_id' => $created['account_id'],
            'onboarding_url' => $link->url,
        ];
    }

    public function createTransfer(Payout $payout): array
    {
        $split = $payout->split_type ?: $payout->payout_type;

        // Platform/company share stays on the main Stripe balance — no Connect transfer.
        if ($split === 'company') {
            $payout->update([
                'status' => 'paid',
                'paid_date' => now()->toDateString(),
                'stripe_transfer_id' => $payout->stripe_transfer_id ?: ('platform_retain_'.$payout->id),
            ]);

            return [
                'provider' => 'stripe',
                'transfer_id' => $payout->stripe_transfer_id,
                'status' => 'paid',
            ];
        }

        if (in_array($payout->status, ['paid', 'in_transit', 'on_hold'], true)) {
            return [
                'provider' => 'stripe',
                'transfer_id' => (string) $payout->stripe_transfer_id,
                'status' => $payout->status,
            ];
        }

        $payee = StripeClientFactory::payeeForPayout($payout);
        if (! $payee?->stripe_account_id || ! $payee->stripe_payout_ready) {
            $payout->update([
                'status' => 'queued',
                'eligibility_status' => 'Payee Stripe Connect not ready — queued for retry',
            ]);

            return [
                'provider' => 'stripe',
                'transfer_id' => '',
                'status' => 'queued',
            ];
        }

        $amountCents = (int) round(((float) $payout->payout_amount) * 100);
        if ($amountCents < 1) {
            throw new \RuntimeException('Payout amount too small for transfer');
        }

        $stripe = $this->stripeFactory->make();

        try {
            $transfer = $stripe->transfers->create([
                'amount' => $amountCents,
                'currency' => 'cad',
                'destination' => $payee->stripe_account_id,
                'transfer_group' => 'job_'.($payout->job_id ?: '0'),
                'metadata' => [
                    'payout_id' => (string) $payout->id,
                    'job_id' => (string) ($payout->job_id ?? ''),
                    'split_type' => (string) $split,
                    'user_id' => (string) $payee->id,
                ],
            ], [
                'idempotency_key' => 'payout_transfer_'.$payout->id,
            ]);

            $payout->update([
                'stripe_transfer_id' => $transfer->id,
                'status' => 'in_transit',
                'eligibility_status' => 'Stripe transfer created',
            ]);

            return [
                'provider' => 'stripe',
                'transfer_id' => $transfer->id,
                'status' => 'in_transit',
            ];
        } catch (ApiErrorException $e) {
            return $this->queueTransferFailure($payout, $e);
        } catch (Throwable $e) {
            // Never let transfer errors bubble into HTTP/job cycles
            return $this->queueTransferFailure($payout, $e);
        }
    }

    /**
     * Soft-fail transfer attempts: keep payout retryable (queued) when Connect
     * isn't ready, balance is low, or Stripe rejects the transfer temporarily.
     *
     * @return array{provider: string, transfer_id: string, status: string}
     */
    private function queueTransferFailure(Payout $payout, Throwable $e): array
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $code = method_exists($e, 'getStripeCode') ? (string) ($e->getStripeCode() ?: '') : '';

        $reason = 'Stripe transfer deferred: '.mb_substr($message, 0, 200);
        if (str_contains($lower, 'signed up for connect') || str_contains($lower, 'connect')) {
            $reason = 'Stripe Connect not enabled on platform — queued until Connect is ready';
        } elseif (str_contains($code, 'balance') || str_contains($lower, 'insufficient')) {
            $reason = 'Insufficient Stripe balance — queued for retry';
        }

        $payout->update([
            'status' => 'queued',
            'eligibility_status' => $reason,
        ]);

        Log::warning('Stripe payout transfer queued after failure', [
            'payout_id' => $payout->id,
            'reason' => $reason,
        ]);

        AiActionLog::create([
            'trigger_event' => 'payout_transfer_deferred',
            'actor_id' => User::where('role', 'owner')->value('id'),
            'data_viewed' => [
                'payout_id' => $payout->id,
                'error' => mb_substr($message, 0, 300),
            ],
            'decision' => 'queued',
            'action_taken' => 'defer_transfer',
            'rule_applied' => 'connect_unavailable_or_stripe_error → queued (no throw)',
            'required_human_approval' => false,
            'error' => mb_substr($message, 0, 500),
        ]);

        return [
            'provider' => 'stripe',
            'transfer_id' => '',
            'status' => 'queued',
        ];
    }

    private function onCheckoutCompleted(array $session): array
    {
        $invoiceId = (int) ($session['metadata']['invoice_id'] ?? $session['client_reference_id'] ?? 0);
        $invoice = $invoiceId ? Invoice::find($invoiceId) : null;
        if (! $invoice && ! empty($session['id'])) {
            $invoice = Invoice::where('stripe_checkout_session_id', $session['id'])->first();
        }
        if (! $invoice) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        if ($invoice->status === 'paid') {
            return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
        }

        if (($session['payment_status'] ?? '') !== 'paid' && ($session['status'] ?? '') !== 'complete') {
            return ['handled' => false, 'invoice_id' => $invoice->id, 'status' => $invoice->status];
        }

        $amount = isset($session['amount_total'])
            ? round(((int) $session['amount_total']) / 100, 2)
            : (float) ($invoice->balance ?? $invoice->amount);

        $this->payments->markPaid($invoice, [
            'amount' => $amount,
            'payment_method' => 'stripe',
            'stripe_transaction_id' => $session['payment_intent'] ?? $session['id'],
            'payment_date' => now()->toDateString(),
            'reference_number' => $session['id'],
        ]);

        if (! empty($session['payment_intent'])) {
            $invoice->fresh()->update([
                'stripe_payment_intent_id' => is_string($session['payment_intent'])
                    ? $session['payment_intent']
                    : ($session['payment_intent']['id'] ?? null),
                'stripe_checkout_session_id' => $session['id'] ?? $invoice->stripe_checkout_session_id,
            ]);
        }

        return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
    }

    private function onPaymentIntentSucceeded(array $intent): array
    {
        $invoiceId = (int) ($intent['metadata']['invoice_id'] ?? 0);
        $invoice = $invoiceId
            ? Invoice::find($invoiceId)
            : Invoice::where('stripe_payment_intent_id', $intent['id'] ?? '')->first();

        if (! $invoice) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        if ($invoice->status === 'paid') {
            return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
        }

        $amount = isset($intent['amount_received'])
            ? round(((int) $intent['amount_received']) / 100, 2)
            : (float) ($invoice->balance ?? $invoice->amount);

        $this->payments->markPaid($invoice, [
            'amount' => $amount,
            'payment_method' => 'stripe',
            'stripe_transaction_id' => $intent['id'] ?? null,
            'payment_date' => now()->toDateString(),
        ]);

        $invoice->fresh()->update(['stripe_payment_intent_id' => $intent['id'] ?? null]);

        return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
    }

    private function onPaymentIntentFailed(array $intent): array
    {
        $invoiceId = (int) ($intent['metadata']['invoice_id'] ?? 0);
        $invoice = $invoiceId
            ? Invoice::find($invoiceId)
            : Invoice::where('stripe_payment_intent_id', $intent['id'] ?? '')->first();

        if (! $invoice) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        if ($invoice->status === 'paid') {
            return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'paid'];
        }

        $invoice->update([
            'status' => 'payment_failed',
            'stripe_payment_intent_id' => $intent['id'] ?? $invoice->stripe_payment_intent_id,
        ]);

        $this->notifyPaymentFailed($invoice);

        AiActionLog::create([
            'trigger_event' => 'stripe_payment_failed',
            'actor_id' => User::where('role', 'owner')->value('id'),
            'data_viewed' => ['invoice_id' => $invoice->id, 'payment_intent' => $intent['id'] ?? null],
            'decision' => 'payment_failed',
            'action_taken' => 'notify_customer_pm',
            'rule_applied' => 'payment_intent.payment_failed',
            'required_human_approval' => false,
        ]);

        return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => 'payment_failed'];
    }

    private function onRefundOrDispute(string $type, array $object): array
    {
        $pi = $object['payment_intent'] ?? null;
        $piId = is_string($pi) ? $pi : ($pi['id'] ?? null);
        $invoice = $piId
            ? Invoice::where('stripe_payment_intent_id', $piId)->first()
            : null;

        if (! $invoice && ! empty($object['metadata']['invoice_id'])) {
            $invoice = Invoice::find((int) $object['metadata']['invoice_id']);
        }

        if (! $invoice) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        $newStatus = str_contains($type, 'dispute') ? 'disputed' : 'refunded';
        $invoice->update(['status' => $newStatus]);

        Payout::where('job_id', $invoice->job_id)
            ->whereNotIn('status', ['paid'])
            ->update([
                'status' => 'on_hold',
                'eligibility_status' => 'Held due to Stripe '.$type,
            ]);

        $owner = User::where('role', 'owner')->first();
        if ($owner) {
            $msg = "ServiceOP alert: Invoice #{$invoice->id} marked {$newStatus} ({$type}). Related payouts held.";
            $this->sms->sendToUser($owner, $msg, 'stripe_dispute_owner', $invoice->job_id);
        }

        AiActionLog::create([
            'trigger_event' => 'stripe_'.$newStatus,
            'actor_id' => $owner?->id,
            'data_viewed' => ['invoice_id' => $invoice->id, 'type' => $type],
            'decision' => $newStatus,
            'action_taken' => 'hold_payouts_notify_owner',
            'rule_applied' => $type,
            'required_human_approval' => true,
        ]);

        return ['handled' => true, 'invoice_id' => $invoice->id, 'status' => $newStatus];
    }

    private function onAccountUpdated(array $account): array
    {
        $accountId = $account['id'] ?? null;
        if (! $accountId) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        $user = User::where('stripe_account_id', $accountId)->first();
        if (! $user) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        $requirements = $account['requirements'] ?? [];
        $due = array_values(array_unique(array_merge(
            $requirements['currently_due'] ?? [],
            $requirements['past_due'] ?? [],
            $requirements['eventually_due'] ?? []
        )));

        $chargesEnabled = (bool) ($account['charges_enabled'] ?? false);
        $payoutsEnabled = (bool) ($account['payouts_enabled'] ?? false);
        $detailsSubmitted = (bool) ($account['details_submitted'] ?? false);

        $status = 'pending';
        if ($detailsSubmitted && $payoutsEnabled && empty($requirements['currently_due'] ?? []) && empty($requirements['past_due'] ?? [])) {
            $status = 'complete';
        } elseif ($detailsSubmitted) {
            $status = 'restricted';
        }

        $user->update([
            'stripe_onboarding_status' => $status,
            'stripe_requirements_due' => $due,
            'stripe_payout_ready' => $payoutsEnabled && $chargesEnabled && $status === 'complete',
        ]);

        return ['handled' => true, 'invoice_id' => null, 'status' => $status];
    }

    private function onTransferUpdate(string $type, array $transfer): array
    {
        $payoutId = (int) ($transfer['metadata']['payout_id'] ?? 0);
        $payout = $payoutId
            ? Payout::find($payoutId)
            : Payout::where('stripe_transfer_id', $transfer['id'] ?? '')->first();

        if (! $payout) {
            return ['handled' => false, 'invoice_id' => null, 'status' => null];
        }

        if ($type === 'transfer.paid') {
            $payout->update([
                'status' => 'paid',
                'paid_date' => now()->toDateString(),
                'stripe_transfer_id' => $transfer['id'] ?? $payout->stripe_transfer_id,
            ]);
        } elseif ($type === 'transfer.failed' || $type === 'transfer.reversed') {
            $payout->update([
                'status' => 'failed',
                'eligibility_status' => 'Stripe '.$type,
            ]);
        }

        return ['handled' => true, 'invoice_id' => null, 'status' => $payout->fresh()->status];
    }

    private function notifyPaymentFailed(Invoice $invoice): void
    {
        $invoice->loadMissing(['customer', 'job.pm']);
        $msg = 'ServiceOP: Your card payment for invoice '
            .($invoice->invoice_number ?: '#'.$invoice->id)
            .' did not go through. Please try again or pay by e-transfer.';

        if ($invoice->customer) {
            $this->sms->sendToUser($invoice->customer, $msg, 'stripe_payment_failed_customer', $invoice->job_id);
            if ($invoice->customer->email) {
                $this->email->send(
                    $invoice->customer->email,
                    'Payment unsuccessful',
                    'emails.notification',
                    ['body' => $msg, 'title' => 'Payment failed'],
                    'stripe_payment_failed',
                    $invoice->customer_id,
                    $invoice->job_id
                );
            }
        }

        if ($invoice->job?->pm) {
            $this->sms->sendToUser(
                $invoice->job->pm,
                "ServiceOP: Customer payment failed for job #{$invoice->job_id} (invoice {$invoice->invoice_number}).",
                'stripe_payment_failed_pm',
                $invoice->job_id
            );
        }
    }

    /**
     * Construct + verify a Stripe Event from raw webhook payload.
     */
    public function constructEvent(string $payload, string $sigHeader): Event
    {
        $secret = $this->stripeFactory->webhookSecret();
        if (! $secret) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
    }
}
