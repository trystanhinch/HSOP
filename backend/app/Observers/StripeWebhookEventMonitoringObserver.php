<?php

namespace App\Observers;

use App\Listeners\DispatchAlertOnStripeWebhookFailed;
use App\Models\StripeWebhookEvent;

class StripeWebhookEventMonitoringObserver
{
    public function created(StripeWebhookEvent $event): void
    {
        app(DispatchAlertOnStripeWebhookFailed::class)->handle($event);
    }

    public function updated(StripeWebhookEvent $event): void
    {
        if ($event->wasChanged('status') && $event->status === 'failed') {
            app(DispatchAlertOnStripeWebhookFailed::class)->handle($event);
        }
    }
}
