<?php

namespace App\Listeners;

use App\Models\StripeWebhookEvent;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Support\Str;

/**
 * Phase 10 — StripeWebhookEvent status=failed → AlertDispatcher (high — payment-related).
 */
class DispatchAlertOnStripeWebhookFailed
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(StripeWebhookEvent $event): void
    {
        if ($event->status !== 'failed') {
            return;
        }

        $this->dispatcher->dispatch('high', 'Stripe webhook processing failed: '.($event->type ?: 'unknown'), [
            'source' => 'stripe.webhook_failed',
            'stripe_webhook_event_id' => $event->id,
            'event_id' => $event->event_id,
            'type' => $event->type,
            'invoice_id' => $event->invoice_id,
            'error' => Str::limit((string) ($event->error ?? ''), 500),
        ]);
    }
}
