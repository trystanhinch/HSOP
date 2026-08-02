<?php

namespace App\Observers;

use App\Listeners\DispatchAlertOnAiActionError;
use App\Models\AiActionLog;
use App\Support\CorrelationId;

class AiActionLogMonitoringObserver
{
    public function creating(AiActionLog $log): void
    {
        if (empty($log->correlation_id)) {
            $log->correlation_id = CorrelationId::current();
        }
    }

    public function created(AiActionLog $log): void
    {
        app(DispatchAlertOnAiActionError::class)->handle($log);
    }
}
