<?php

namespace App\Observers;

use App\Listeners\DispatchAlertOnEmailDeliveryFailed;
use App\Models\EmailLog;
use App\Support\CorrelationId;

class EmailLogMonitoringObserver
{
    public function creating(EmailLog $log): void
    {
        if (empty($log->correlation_id)) {
            $log->correlation_id = CorrelationId::current();
        }
    }

    public function created(EmailLog $log): void
    {
        app(DispatchAlertOnEmailDeliveryFailed::class)->handle($log);
    }

    public function updated(EmailLog $log): void
    {
        if ($log->wasChanged('status')) {
            app(DispatchAlertOnEmailDeliveryFailed::class)->handle($log);
        }
    }
}
