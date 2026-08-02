<?php

namespace App\Listeners;

use App\Models\SmsLog;
use App\Services\Monitoring\AlertDispatcher;

/**
 * Phase 10 — SMS delivery failure → AlertDispatcher (once per SmsLog row).
 */
class DispatchAlertOnSmsDeliveryFailed
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(SmsLog $log): void
    {
        if (! in_array($log->status, ['failed', 'provider_unavailable'], true)) {
            return;
        }

        $this->dispatcher->dispatch('medium', 'SMS delivery failed: '.($log->trigger_event ?: 'unknown'), [
            'source' => 'sms.delivery_failed',
            'sms_log_id' => $log->id,
            'status' => $log->status,
            'trigger_event' => $log->trigger_event,
            'error_code' => $log->error_code,
            'related_job_id' => $log->related_job_id,
            'correlation_id' => $log->correlation_id,
        ]);
    }
}
