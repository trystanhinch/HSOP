<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Services\Monitoring\AlertDispatcher;

/**
 * Phase 10 — Email delivery failure → AlertDispatcher (once per EmailLog row).
 */
class DispatchAlertOnEmailDeliveryFailed
{
    public function __construct(private AlertDispatcher $dispatcher) {}

    public function handle(EmailLog $log): void
    {
        if (! in_array($log->status, ['failed', 'provider_unavailable'], true)) {
            return;
        }

        $this->dispatcher->dispatch('medium', 'Email delivery failed: '.($log->trigger_event ?: 'unknown'), [
            'source' => 'email.delivery_failed',
            'email_log_id' => $log->id,
            'status' => $log->status,
            'trigger_event' => $log->trigger_event,
            'error_code' => $log->error_code,
            'related_job_id' => $log->related_job_id,
            'correlation_id' => $log->correlation_id,
        ]);
    }
}
