<?php

namespace App\Observers;

use App\Listeners\DispatchAlertOnWorkflowEscalation;
use App\Models\WorkflowEscalationLog;

class WorkflowEscalationLogMonitoringObserver
{
    public function created(WorkflowEscalationLog $log): void
    {
        app(DispatchAlertOnWorkflowEscalation::class)->handle($log);
    }
}
