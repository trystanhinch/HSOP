<?php

namespace App\Observers;

use App\Listeners\DispatchAlertOnSmsDeliveryFailed;
use App\Models\SmsLog;
use App\Support\CorrelationId;

class SmsLogMonitoringObserver
{
    public function creating(SmsLog $log): void
    {
        if (empty($log->correlation_id)) {
            $log->correlation_id = CorrelationId::current();
        }
    }

    public function created(SmsLog $log): void
    {
        app(DispatchAlertOnSmsDeliveryFailed::class)->handle($log);
    }

    public function updated(SmsLog $log): void
    {
        if ($log->wasChanged('status')) {
            app(DispatchAlertOnSmsDeliveryFailed::class)->handle($log);
        }
    }
}
